<?php
/**
 * Automation trigger: credit note sent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

final class CreditNoteSent extends AbstractSalesLifecycleTrigger {

	public $name = 'Credit note sent';

	public $slug = 'credit_note_sent';

	public $description = 'Fires when a credit note is emailed to the customer.';

	public $attributes = array();

	public $group = 'credit_notes';

	public function load_hooks(): void {
		add_action( 'doublescale_sales_credit_note_sent', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $credit_note {@see \DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel} instance.
	 * @param mixed $message     Optional custom message.
	 */
	public function handle( $credit_note, $message = '' ): void {
		$this->enroll_from_credit_note(
			$credit_note,
			array(
				'message' => is_string( $message ) ? $message : '',
			)
		);
	}
}
