<?php
/**
 * Automation trigger: proposal converted to invoice.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;

defined( 'ABSPATH' ) || exit;

final class ProposalConvertedToInvoice extends AbstractSalesLifecycleTrigger {

	public $name = 'Proposal converted to invoice';

	public $slug = 'proposal_converted_to_invoice';

	public $description = 'Fires when a proposal is converted to a draft invoice.';

	public $attributes = array();

	public function load_hooks(): void {
		add_action( 'doublescale_sales_proposal_converted_to_invoice', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $proposal {@see ProposalModel} instance.
	 * @param mixed $invoice  {@see InvoiceModel} instance.
	 */
	public function handle( $proposal, $invoice ): void {
		if ( ! $proposal instanceof ProposalModel || ! $invoice instanceof InvoiceModel ) {
			return;
		}

		$this->enroll_from_proposal(
			$proposal,
			array(
				'invoice_id' => (int) $invoice->id,
			)
		);
	}
}
