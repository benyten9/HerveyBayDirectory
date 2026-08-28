<?php
/**
 * Read-only recurring invoice abilities.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\RecurringInvoices\Models\InvoiceRecurrenceModel;
use DoubleScale\Pro\Modules\RecurringInvoices\Rest\InvoiceRecurrenceShaper;

/**
 * A recurrence rule is not itself an owned record — it hangs off a template
 * invoice, and the invoice carries `sale_agent_user_id`. Gate 3 therefore scopes
 * through the template rather than on the rule's own columns, which is why every
 * query here joins the invoice before applying {@see AbilityScope}.
 *
 * `next_run_at` is the field users actually ask about ("when does this bill
 * again"), and it is null on an inactive or exhausted rule. The shaper reports
 * `is_active`, `cycles_done`, and `total_cycles` alongside it so an agent can
 * say WHY nothing is scheduled instead of reporting "no next run" as a fault.
 */
final class RecurrenceAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( self::class, 'can_view_sales' );

		return array(
			'doublescale/list-invoice-recurrences' => array(
				'module_slug'      => 'recurring_invoices',
				'label'            => __( 'List recurring invoice schedules', 'doublescale' ),
				'description'      => __( 'Billing schedules attached to template invoices — how often each regenerates, how many cycles have run, and when the next one is due. A schedule with no next run is either switched off or has reached its cycle cap or end date; check is_active and cycles_done before calling it broken. Read-only: this never generates or sends an invoice.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'active_only'  => array(
							'type'        => 'boolean',
							'description' => 'Only schedules that are switched on.',
							'default'     => false,
						),
						'due_before'   => array(
							'type'        => 'string',
							'description' => 'Only schedules whose next run falls on or before this date (YYYY-MM-DD). Use this for "what bills this month".',
						),
						'contact_id'   => array(
							'type'        => 'integer',
							'description' => 'Only schedules whose template invoice belongs to this contact.',
						),
						'interval_unit' => array(
							'type'        => 'string',
							'description' => 'Only schedules on this cadence.',
							'enum'        => InvoiceRecurrenceModel::UNITS,
						),
						'limit'        => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'       => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_recurrences' ),
			),

			'doublescale/get-invoice-recurrence'   => array(
				'module_slug'      => 'recurring_invoices',
				'label'            => __( 'Get recurring invoice schedule', 'doublescale' ),
				'description'      => __( 'One billing schedule with its cadence, cycle count, end date, next and last run, and the template invoice it regenerates. Look it up by its own id or by the template invoice id.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'                  => array(
							'type'        => 'integer',
							'description' => 'Schedule id. Provide this or template_invoice_id.',
						),
						'template_invoice_id' => array(
							'type'        => 'integer',
							'description' => 'Template invoice id. Provide this or id.',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_recurrence' ),
			),
		);
	}

	/**
	 * Gate 2 — the shared Sales view capability.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_view_sales(): bool {
		return Capabilities::current_user_can( 'doublescale_view_sales' );
	}

	/**
	 * Whether the caller sees every schedule or only their own.
	 *
	 * Matches the invoice and credit-note rule exactly: a rep who cannot see
	 * another rep's invoice must not see its billing schedule either, since the
	 * schedule exposes the template's amount and cadence.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all(): bool {
		return Capabilities::can_manage_all_sales() || Capabilities::can_assign_sales_rep();
	}

	/**
	 * List recurrence rules.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_recurrences( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = InvoiceRecurrenceModel::query()->with( array( 'templateInvoice' ) );

		if ( ! empty( $input['active_only'] ) ) {
			$query->where( 'is_active', 1 );
		}

		if ( ! empty( $input['interval_unit'] ) ) {
			$query->where( 'interval_unit', InvoiceRecurrenceModel::normalize_unit( (string) $input['interval_unit'] ) );
		}

		if ( ! empty( $input['due_before'] ) ) {
			// A null next_run_at is "not scheduled", not "due now", so the
			// NOT NULL guard is what keeps retired rules out of a due list.
			$query->whereNotNull( 'next_run_at' )
				->where( 'next_run_at', '<=', (string) $input['due_before'] . ' 23:59:59' );
		}

		$template_ids = self::visible_template_ids( $input );
		if ( array() === $template_ids ) {
			// No readable template invoices means no readable schedules. Return
			// an empty page rather than an unscoped query.
			return AbilityResult::collection(
				array(),
				0,
				$limit,
				$offset,
				array( 'scope' => AbilityScope::label( self::sees_all() ) )
			);
		}

		if ( null !== $template_ids ) {
			$query->whereIn( 'template_invoice_id', $template_ids );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'next_run_at', 'asc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_recurrence( $row );
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( self::sees_all() ) )
		);
	}

	/**
	 * Get one recurrence rule.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_recurrence( array $input ) {
		$id          = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$template_id = isset( $input['template_invoice_id'] ) ? (int) $input['template_invoice_id'] : 0;

		if ( $id <= 0 && $template_id <= 0 ) {
			return AbilityResult::not_found(
				__( 'Provide either a schedule id or a template_invoice_id.', 'doublescale' )
			);
		}

		$query = InvoiceRecurrenceModel::query()->with( array( 'templateInvoice' ) );

		if ( $id > 0 ) {
			$query->where( 'id', $id );
		} else {
			$query->where( 'template_invoice_id', $template_id );
		}

		$recurrence = $query->first();

		if ( ! $recurrence ) {
			return AbilityResult::not_found(
				$template_id > 0
					? __( 'That invoice has no billing schedule attached.', 'doublescale' )
					: __( 'No billing schedule found with that id.', 'doublescale' )
			);
		}

		// Gate 3 runs against the TEMPLATE, which is the record that carries an
		// owner. A rule whose template has been deleted is unreachable rather
		// than world-readable.
		$template = $recurrence->templateInvoice ?? null;
		if ( ! $template ) {
			return AbilityResult::not_found(
				__( 'The template invoice for this schedule no longer exists.', 'doublescale' )
			);
		}

		$forbidden = AbilityScope::assert_owns(
			$template,
			'sale_agent_user_id',
			self::sees_all(),
			__( 'The invoice this schedule belongs to is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		return self::shape_recurrence( $recurrence );
	}

	/**
	 * Template invoice ids the caller may read.
	 *
	 * Returns null when the caller sees everything, so the query is left
	 * unfiltered instead of loading every invoice id on the site.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, int>|null
	 */
	private static function visible_template_ids( array $input ): ?array {
		$sees_all   = self::sees_all();
		$contact_id = isset( $input['contact_id'] ) ? (int) $input['contact_id'] : 0;

		if ( $sees_all && $contact_id <= 0 ) {
			return null;
		}

		$model = '\DoubleScale\Modules\Documents\Models\InvoiceModel';
		if ( ! class_exists( $model ) ) {
			return null;
		}

		$invoices = $model::query();

		if ( $contact_id > 0 ) {
			$invoices->where( 'contact_id', $contact_id );
		}

		AbilityScope::apply( $invoices, 'sale_agent_user_id', $sees_all );

		$ids = array();
		foreach ( $invoices->get() as $invoice ) {
			$ids[] = (int) $invoice->id;
		}

		return $ids;
	}

	/**
	 * Shape one rule, reusing the REST shaper so the agent and the UI never
	 * disagree about a cadence or a cycle count.
	 *
	 * @since 1.0.0
	 *
	 * @param InvoiceRecurrenceModel $recurrence Rule.
	 * @return array<string, mixed>
	 */
	private static function shape_recurrence( $recurrence ): array {
		$data = InvoiceRecurrenceShaper::shape( $recurrence );

		$template = $recurrence->templateInvoice ?? null;
		if ( is_object( $template ) ) {
			$data['template'] = array(
				'invoice_id'     => (int) $template->id,
				'invoice_number' => (string) ( $template->invoice_number ?? '' ),
				'contact_id'     => (int) ( $template->contact_id ?? 0 ),
				'total'          => (float) ( $template->total ?? 0 ),
				'currency'       => (string) ( $template->currency ?? '' ),
			);
		} else {
			$data['template'] = null;
		}

		// Spelling out WHY a rule has no next run saves the agent from
		// reporting a deliberately finished schedule as a failure.
		$data['schedule_state'] = self::schedule_state( $recurrence );

		return $data;
	}

	/**
	 * Plain-language reason a rule is or is not going to fire again.
	 *
	 * @since 1.0.0
	 *
	 * @param InvoiceRecurrenceModel $recurrence Rule.
	 * @return string
	 */
	private static function schedule_state( $recurrence ): string {
		if ( ! (bool) $recurrence->is_active ) {
			return 'paused';
		}

		if ( ! (bool) $recurrence->is_infinite
			&& (int) $recurrence->total_cycles > 0
			&& (int) $recurrence->cycles_done >= (int) $recurrence->total_cycles ) {
			return 'completed';
		}

		if ( ! empty( $recurrence->end_date ) && (string) $recurrence->end_date < gmdate( 'Y-m-d' ) ) {
			return 'ended';
		}

		if ( empty( $recurrence->next_run_at ) ) {
			return 'not_scheduled';
		}

		return 'scheduled';
	}
}
