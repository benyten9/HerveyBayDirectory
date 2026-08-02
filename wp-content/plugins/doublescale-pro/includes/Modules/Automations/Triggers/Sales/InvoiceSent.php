<?php
/**
 * Automation trigger: invoice sent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

final class InvoiceSent extends AbstractSalesLifecycleTrigger {

	public $name = 'Invoice sent';

	public $slug = 'invoice_sent';

	public $description = 'Fires when an invoice is emailed to the customer.';

	public $attributes = array();

	public function load_hooks(): void {
		add_action( 'doublescale_sales_invoice_sent', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $invoice {@see \DoubleScale\Modules\Documents\Models\InvoiceModel} instance.
	 * @param mixed $message Optional custom message.
	 */
	public function handle( $invoice, $message = '' ): void {
		$this->enroll_from_invoice(
			$invoice,
			array(
				'message' => is_string( $message ) ? $message : '',
			)
		);
	}
}
