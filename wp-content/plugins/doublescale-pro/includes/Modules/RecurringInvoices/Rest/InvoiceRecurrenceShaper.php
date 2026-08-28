<?php
/**
 * Shape recurrence rules for API responses.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\RecurringInvoices\Models\InvoiceRecurrenceModel;

/**
 * InvoiceRecurrenceShaper class.
 */
class InvoiceRecurrenceShaper {

	/**
	 * @param InvoiceRecurrenceModel $recurrence Recurrence rule.
	 * @return array<string, mixed>
	 */
	public static function shape( InvoiceRecurrenceModel $recurrence ): array {
		return array(
			'id'                  => (int) $recurrence->id,
			'template_invoice_id' => (int) $recurrence->template_invoice_id,
			'interval_value'      => (int) $recurrence->interval_value,
			'interval_unit'       => InvoiceRecurrenceModel::normalize_unit( $recurrence->interval_unit ),
			'total_cycles'        => (int) $recurrence->total_cycles,
			'is_infinite'         => (bool) $recurrence->is_infinite,
			'cycles_done'         => (int) $recurrence->cycles_done,
			'end_date'            => $recurrence->end_date ? (string) $recurrence->end_date : null,
			'auto_send'           => (bool) $recurrence->auto_send,
			'require_paid'        => (bool) $recurrence->require_paid,
			'next_run_at'         => $recurrence->next_run_at ? (string) $recurrence->next_run_at : null,
			'last_run_at'         => $recurrence->last_run_at ? (string) $recurrence->last_run_at : null,
			'is_active'           => (bool) $recurrence->is_active,
		);
	}
}
