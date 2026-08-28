<?php
/**
 * Read-only reporting abilities.
 *
 * @package DoubleScale\Pro\Modules\Analytics
 */

namespace DoubleScale\Pro\Modules\Analytics\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Services\CurrencyResolver;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * "How are we doing" questions, answered in one call.
 *
 * Restricted to sales-manager tier deliberately: these are whole-company
 * figures with no owner scoping, matching the permission the Analytics REST
 * routes already use for their aggregate endpoints. A Sales Rep asking about
 * their own numbers is served by get-sales-summary and get-deal-summary, which
 * ARE scoped.
 */
final class ReportAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_sales_manager_access' );

		return array(
			'doublescale/get-revenue-report' => array(
				'module_slug'      => 'analytics',
				'label'            => __( 'Get revenue report', 'doublescale' ),
				'description'      => __( 'Company-wide invoiced and collected totals over a date range, split by currency and month. Covers everyone, not just your own records — use get-sales-summary for your own figures.', 'doublescale' ),
				'category'         => AbilityCategories::ANALYTICS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'date_from' => array(
							'type'        => 'string',
							'description' => 'Inclusive lower bound on invoice date, YYYY-MM-DD. Defaults to 12 months ago.',
						),
						'date_to'   => array(
							'type'        => 'string',
							'description' => 'Inclusive upper bound, YYYY-MM-DD. Defaults to today.',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_revenue_report' ),
			),

			'doublescale/get-contact-growth' => array(
				'module_slug'      => 'analytics',
				'label'            => __( 'Get contact growth', 'doublescale' ),
				'description'      => __( 'How many contacts were added per month over a date range, for answering "are we growing".', 'doublescale' ),
				'category'         => AbilityCategories::ANALYTICS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'date_from' => array(
							'type'        => 'string',
							'description' => 'Start date, YYYY-MM-DD. Defaults to 12 months ago.',
						),
						'date_to'   => array(
							'type'        => 'string',
							'description' => 'End date, YYYY-MM-DD. Defaults to today.',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_contact_growth' ),
			),
		);
	}

	/**
	 * Resolve the requested window, defaulting to the last 12 months.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{0: string, 1: string}
	 */
	private static function window( array $input ): array {
		$to   = ! empty( $input['date_to'] ) ? (string) $input['date_to'] : gmdate( 'Y-m-d' );
		$from = ! empty( $input['date_from'] )
			? (string) $input['date_from']
			: gmdate( 'Y-m-d', strtotime( '-12 months' ) );

		return array( $from, $to );
	}

	/**
	 * Invoiced and collected totals per currency and month.
	 *
	 * Built from the invoice table rather than the ReportPeriod services: those
	 * are shaped for the dashboard's filter UI, and an agent needs the plain
	 * figures with their currency attached.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_revenue_report( array $input ): array {
		list( $from, $to ) = self::window( $input );

		$rows = InvoiceModel::query()
			->where( 'invoice_date', '>=', $from )
			->where( 'invoice_date', '<=', $to )
			->get();

		$by_currency = array();

		foreach ( $rows as $invoice ) {
			$currency = CurrencyResolver::resolve( $invoice );
			$month    = substr( (string) $invoice->invoice_date, 0, 7 );

			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = array(
					'currency'  => $currency,
					'invoiced'  => 0.0,
					'collected' => 0.0,
					'count'     => 0,
					'by_month'  => array(),
				);
			}

			$total   = (float) $invoice->total;
			$applied = (float) $invoice->amount_paid;

			$by_currency[ $currency ]['invoiced']  += $total;
			$by_currency[ $currency ]['collected'] += $applied;
			++$by_currency[ $currency ]['count'];

			if ( ! isset( $by_currency[ $currency ]['by_month'][ $month ] ) ) {
				$by_currency[ $currency ]['by_month'][ $month ] = array(
					'invoiced'  => 0.0,
					'collected' => 0.0,
				);
			}
			$by_currency[ $currency ]['by_month'][ $month ]['invoiced']  += $total;
			$by_currency[ $currency ]['by_month'][ $month ]['collected'] += $applied;
		}

		foreach ( $by_currency as $code => $bucket ) {
			ksort( $by_currency[ $code ]['by_month'] );
			$by_currency[ $code ]['outstanding'] = $bucket['invoiced'] - $bucket['collected'];
		}

		return array(
			'date_from'     => $from,
			'date_to'       => $to,
			'currencies'    => array_values( $by_currency ),
			'scope'         => 'all',
			'currency_note' => __( 'Figures are grouped by currency and must not be added together across currencies.', 'doublescale' ),
		);
	}

	/**
	 * Contact growth over the window.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_contact_growth( array $input ): array {
		list( $from, $to ) = self::window( $input );

		// Deliberately NOT ReportingService::get_contact_growth(): that method
		// calls groupByRaw(), which the vendored Eloquent build does not
		// provide, so it throws. It has no callers anywhere in either plugin —
		// it is dead, broken code, and wrapping it would only surface the
		// breakage to agents.
		$rows = ContactModel::query()
			->where( 'created_at', '>=', $from . ' 00:00:00' )
			->where( 'created_at', '<=', $to . ' 23:59:59' )
			->get();

		$by_month = array();
		foreach ( $rows as $contact ) {
			$month              = substr( (string) $contact->created_at, 0, 7 );
			$by_month[ $month ] = ( $by_month[ $month ] ?? 0 ) + 1;
		}

		ksort( $by_month );

		return array(
			'date_from'   => $from,
			'date_to'     => $to,
			'total_added' => count( $rows ),
			'by_month'    => $by_month,
		);
	}
}
