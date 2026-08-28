<?php
/**
 * Create, update, or clear the recurrence rule attached to an invoice.
 *
 * @package DoubleScale\Pro\Modules\RecurringInvoices
 */

namespace DoubleScale\Pro\Modules\RecurringInvoices\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Pro\Modules\RecurringInvoices\Models\InvoiceRecurrenceModel;

/**
 * SaveInvoiceRecurrence service.
 */
class SaveInvoiceRecurrence {

	/**
	 * Apply a recurrence payload to an invoice.
	 *
	 * Passing `null` (or an empty/inactive payload) removes the rule — this is
	 * how the UI's "No" option turns recurrence off.
	 *
	 * @param InvoiceModel              $invoice Template invoice.
	 * @param array<string, mixed>|null $payload Sanitized recurrence payload.
	 * @return InvoiceRecurrenceModel|null Stored rule, or null when cleared.
	 */
	public function save( InvoiceModel $invoice, ?array $payload ): ?InvoiceRecurrenceModel {
		$existing = InvoiceRecurrenceModel::where( 'template_invoice_id', (int) $invoice->id )->first();

		if ( empty( $payload ) ) {
			if ( $existing ) {
				$existing->delete();
			}

			return null;
		}

		$unit  = InvoiceRecurrenceModel::normalize_unit( $payload['interval_unit'] ?? 'month' );
		$value = max( 1, (int) ( $payload['interval_value'] ?? 1 ) );

		$is_infinite  = array_key_exists( 'is_infinite', $payload ) ? (bool) $payload['is_infinite'] : true;
		$total_cycles = max( 0, (int) ( $payload['total_cycles'] ?? 0 ) );

		// A cycle cap of 0 means "no cap" — treat it as infinite so the rule
		// can't be saved in a state that stops immediately.
		if ( 0 === $total_cycles ) {
			$is_infinite = true;
		}

		$data = array(
			'template_invoice_id' => (int) $invoice->id,
			'interval_value'      => $value,
			'interval_unit'       => $unit,
			'total_cycles'        => $total_cycles,
			'is_infinite'         => $is_infinite,
			'end_date'            => ! empty( $payload['end_date'] ) ? (string) $payload['end_date'] : null,
			'auto_send'           => ! empty( $payload['auto_send'] ),
			'require_paid'        => ! empty( $payload['require_paid'] ),
			'is_active'           => true,
		);

		if ( $existing ) {
			$schedule_changed = (int) $existing->interval_value !== $value
				|| InvoiceRecurrenceModel::normalize_unit( $existing->interval_unit ) !== $unit;

			$existing->fill( $data );

			// Only recompute the slot when the cadence itself moved, so editing
			// unrelated options (auto-send, end date) doesn't push the next run.
			if ( $schedule_changed || empty( $existing->next_run_at ) ) {
				$existing->next_run_at = $this->first_run_at( $invoice, $existing );
			}

			$existing->save();

			return $existing;
		}

		$recurrence = new InvoiceRecurrenceModel();
		$recurrence->fill( $data );
		$recurrence->cycles_done = 0;
		$recurrence->next_run_at = $this->first_run_at( $invoice, $recurrence );
		$recurrence->save();

		return $recurrence;
	}

	/**
	 * First slot after the template's own invoice date.
	 *
	 * The template invoice is the first document in the series and already
	 * exists, so the schedule starts one interval on from its issue date.
	 *
	 * @param InvoiceModel           $invoice    Template invoice.
	 * @param InvoiceRecurrenceModel $recurrence Rule (already carrying interval).
	 * @return string MySQL datetime.
	 */
	private function first_run_at( InvoiceModel $invoice, InvoiceRecurrenceModel $recurrence ): string {
		$tz  = wp_timezone();
		$raw = ! empty( $invoice->invoice_date )
			? (string) $invoice->invoice_date
			: current_time( 'Y-m-d' );

		try {
			$anchor = new \DateTime( $raw, $tz );
		} catch ( \Throwable $e ) {
			$anchor = new \DateTime( current_time( 'Y-m-d' ), $tz );
		}

		$anchor->setTime( 0, 0, 0 );

		return $recurrence->compute_next_run_at( $anchor->format( 'Y-m-d H:i:s' ), $anchor );
	}
}
