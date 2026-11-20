<?php
/**
 * Contact summary: the figures shown in the contact profile's header tiles.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Contacts\Services;

use DoubleScale\Modules\Activities\Services\ActivityManager;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Sales\Capabilities as SalesCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the contact summary.
 *
 * Each part is null when the viewer cannot see it or its module is off, so
 * the profile drops that tile and moves the next one up. Pro adds `deals`
 * and `tasks` through the `doublescale_contact_summary` filter.
 */
class ContactSummaryService {

	/**
	 * Invoice statuses that still have money to collect.
	 *
	 * @var string[]
	 */
	const DUE_STATUSES = array(
		InvoiceStatus::UNPAID,
		InvoiceStatus::PARTIALLY_PAID,
		InvoiceStatus::OVERDUE,
	);

	/**
	 * Summary for one contact.
	 *
	 * @param int $contact_id Contact ID (already checked as visible).
	 * @return array<string, mixed>
	 */
	public static function for_contact( int $contact_id ): array {
		$summary = array(
			'invoices'      => self::invoices( $contact_id ),
			'upcoming'      => self::upcoming( $contact_id ),
			'last_activity' => self::last_activity( $contact_id ),
			'deals'         => null,
			'tasks'         => null,
			// Fill the row when a module above is off.
			'purchases'     => PurchaseTotals::for_contacts( array( $contact_id ) ),
			'files'         => self::files( $contact_id ),
			'created_at'    => self::created_at( $contact_id ),
		);

		/**
		 * Filter the contact summary.
		 *
		 * @param array $summary    Summary parts.
		 * @param int   $contact_id Contact ID.
		 */
		return (array) apply_filters( 'doublescale_contact_summary', $summary, $contact_id );
	}

	/**
	 * Files kept on the contact (the team's and the client's own).
	 *
	 * @param int $contact_id Contact ID.
	 */
	private static function files( int $contact_id ): int {
		try {
			return (int) \DoubleScale\Core\Models\AttachmentModel::forType( 'contact' )->where( 'attachable_id', $contact_id )->active()->count();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * When the contact was added.
	 *
	 * @param int $contact_id Contact ID.
	 */
	private static function created_at( int $contact_id ): ?string {
		$contact = \DoubleScale\Modules\Contacts\Models\ContactModel::find( $contact_id );
		return $contact && $contact->created_at ? (string) $contact->created_at : null;
	}

	/**
	 * Unpaid invoices: how many, the balance left and how many are late.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<string, mixed>|null
	 */
	private static function invoices( int $contact_id ): ?array {
		// Invoices are the Documents module's data (null = no card).
		if ( ! doublescale_module_data_ready( 'documents' ) ) {
			return null;
		}
		if ( ! class_exists( SalesCapabilities::class ) || ! SalesCapabilities::can_view_sales() ) {
			return null;
		}

		try {
			$query = InvoiceModel::query()
				->where( 'contact_id', $contact_id )
				->whereIn( 'status', self::DUE_STATUSES );

			// A rep who manages only their own sales sees only their invoices.
			if ( ! SalesCapabilities::current_user_can( 'doublescale_view_sales' )
				&& ! SalesCapabilities::current_user_can( 'doublescale_manage_all_sales' )
				&& ! SalesCapabilities::current_user_can( 'doublescale_manage' ) ) {
				$query->where( 'sale_agent_user_id', get_current_user_id() );
			}

			$rows = $query->get( array( 'total', 'amount_paid', 'due_date', 'status', 'currency' ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		$today   = current_time( 'Y-m-d' );
		$due     = 0.0;
		$overdue = 0;
		$currency = '';
		foreach ( $rows as $row ) {
			$due += max( 0, (float) $row->total - (float) $row->amount_paid );
			if ( InvoiceStatus::OVERDUE === $row->status
				|| ( ! empty( $row->due_date ) && substr( (string) $row->due_date, 0, 10 ) < $today ) ) {
				++$overdue;
			}
			if ( '' === $currency && ! empty( $row->currency ) ) {
				$currency = (string) $row->currency;
			}
		}

		return array(
			'count'    => count( $rows ),
			'due'      => round( $due, 2 ),
			'overdue'  => $overdue,
			'currency' => $currency,
		);
	}

	/**
	 * Scheduled calls, meetings and tasks from today on, nearest first.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<string, mixed>
	 */
	private static function upcoming( int $contact_id ): array {
		$result = ActivityManager::instance()->get_unified_timeline(
			array(
				'contact_id' => $contact_id,
				'date_from'  => gmdate( 'Y-m-d' ),
				'sort_by'    => 'activity_date',
				'sort_order' => 'asc',
			),
			3,
			1
		);

		return array(
			'count' => (int) ( $result['meta']['total'] ?? 0 ),
			'items' => array_map( array( __CLASS__, 'item' ), (array) ( $result['data'] ?? array() ) ),
		);
	}

	/**
	 * The most recent thing that happened with this contact.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<string, mixed>|null
	 */
	private static function last_activity( int $contact_id ): ?array {
		$result = ActivityManager::instance()->get_unified_timeline(
			array(
				'contact_id' => $contact_id,
				'date_to'    => gmdate( 'Y-m-d' ),
				'sort_by'    => 'activity_date',
				'sort_order' => 'desc',
			),
			1,
			1
		);

		$first = $result['data'][0] ?? null;
		return $first ? self::item( $first ) : null;
	}

	/**
	 * The fields of a timeline row the profile needs.
	 *
	 * @param array|object $row Timeline row.
	 * @return array<string, mixed>
	 */
	private static function item( $row ): array {
		$row  = (array) $row;
		$data = isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array();

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'item_type'     => (string) ( $row['item_type'] ?? 'activity' ),
			'activity_type' => (string) ( $row['activity_type'] ?? '' ),
			'title'         => (string) ( $row['title'] ?? $data['title'] ?? '' ),
			'message'       => wp_strip_all_tags( (string) ( $row['formatted_message'] ?? '' ) ),
			'date'          => (string) ( $row['activity_date'] ?? $row['due_date'] ?? '' ),
		);
	}
}
