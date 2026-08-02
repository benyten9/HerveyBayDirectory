<?php
/**
 * Automation trigger: credit note applied to invoice.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

final class CreditNoteApplied extends AbstractSalesLifecycleTrigger {

	public $name = 'Credit note applied';

	public $slug = 'credit_note_applied';

	public $description = 'Fires when credit from a credit note is applied to an invoice.';

	public $attributes = array();

	public $group = 'credit_notes';

	public function load_hooks(): void {
		add_action( 'doublescale_sales_credit_note_applied', array( $this, 'handle' ), 10, 4 );
	}

	/**
	 * @param mixed $credit_note    {@see \DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel} instance.
	 * @param mixed $invoice        {@see \DoubleScale\Modules\Documents\Models\InvoiceModel} instance.
	 * @param mixed $amount         Applied amount.
	 * @param mixed $application_id Application row ID.
	 */
	public function handle( $credit_note, $invoice = null, $amount = 0, $application_id = 0 ): void {
		$extra = array();
		if ( is_numeric( $amount ) && (float) $amount > 0 ) {
			$extra['application_amount'] = (float) $amount;
		}
		if ( is_numeric( $application_id ) && (int) $application_id > 0 ) {
			$extra['application_id'] = (int) $application_id;
		}
		if ( is_object( $invoice ) && isset( $invoice->id ) ) {
			$extra['invoice_id'] = (int) $invoice->id;
		}

		$this->enroll_from_credit_note( $credit_note, $extra );
	}
}
