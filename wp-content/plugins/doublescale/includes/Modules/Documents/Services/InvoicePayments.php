<?php
/**
 * Sync invoice amount_paid and status from payment records.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\DocumentCurrency;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;

/**
 * InvoicePayments service.
 */
class InvoicePayments {

	/**
	 * Recompute amount_paid and status from payments, then save.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return InvoiceModel
	 */
	public function sync( InvoiceModel $invoice ): InvoiceModel {
		$amount_paid = (float) PaymentModel::query()
			->where( 'invoice_id', (int) $invoice->id )
			->sum( 'amount' );
		// Payments have no currency column — they inherit the parent invoice's.
		// Summing here is safe because every row belongs to this one invoice.

		// Money has changed hands, so the currency the customer paid in must be
		// pinned even if the invoice was never "sent" (recorded manually, paid
		// via the portal, or charged by a gateway). Without this an inheriting
		// invoice keeps following the global setting, and changing it later
		// relabels a settled USD payment as EUR. Payments inherit the parent's
		// currency, so this also protects every historical payment row.
		if ( $amount_paid > 0 ) {
			DocumentCurrency::freeze_on_send( $invoice );
		}

		$invoice->amount_paid = round( $amount_paid, 2 );
		$previous_status      = (string) $invoice->status;
		$invoice->status      = self::derive_status( $invoice );
		$invoice->save();

		if ( InvoiceStatus::PAID === (string) $invoice->status && InvoiceStatus::PAID !== $previous_status ) {
			do_action( 'doublescale_sales_invoice_paid', $invoice->fresh() );
		}

		return $invoice->fresh();
	}

	/**
	 * Derive invoice status from payment totals and due date.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return string
	 */
	public static function derive_status( InvoiceModel $invoice ): string {
		return self::derive_status_from_values(
			(string) $invoice->status,
			(float) $invoice->total,
			(float) $invoice->amount_paid,
			$invoice->due_date ? (string) $invoice->due_date : null
		);
	}

	/**
	 * Pure status derivation for tests and reuse.
	 *
	 * @param string      $current_status Stored invoice status.
	 * @param float       $total Invoice total.
	 * @param float       $amount_paid Amount paid so far.
	 * @param string|null $due_date Due date (Y-m-d) or null.
	 * @param string|null $today Reference date (Y-m-d); defaults to site today.
	 * @return string
	 */
	public static function derive_status_from_values(
		string $current_status,
		float $total,
		float $amount_paid,
		?string $due_date,
		?string $today = null
	): string {
		if ( InvoiceStatus::DRAFT === $current_status ) {
			return InvoiceStatus::DRAFT;
		}

		if ( $amount_paid >= $total && $total > 0 ) {
			return InvoiceStatus::PAID;
		}

		if ( $amount_paid > 0 ) {
			return InvoiceStatus::PARTIALLY_PAID;
		}

		$today = $today ?? current_time( 'Y-m-d' );
		if ( $due_date && $due_date < $today ) {
			return InvoiceStatus::OVERDUE;
		}

		return InvoiceStatus::UNPAID;
	}
}
