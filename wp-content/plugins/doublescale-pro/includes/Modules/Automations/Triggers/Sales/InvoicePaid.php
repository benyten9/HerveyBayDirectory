<?php
/**
 * Automation trigger: invoice paid.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

final class InvoicePaid extends AbstractSalesLifecycleTrigger {

	public $name = 'Invoice paid';

	public $slug = 'invoice_paid';

	public $description = 'Fires when an invoice is paid in full.';

	public $attributes = array();

	public function load_hooks(): void {
		add_action( 'doublescale_sales_invoice_paid', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * @param mixed $invoice {@see \DoubleScale\Modules\Documents\Models\InvoiceModel} instance.
	 */
	public function handle( $invoice ): void {
		$this->enroll_from_invoice( $invoice );
	}
}
