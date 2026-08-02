<?php
/**
 * Invoice balance due merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\MergeTags\AbstractInvoiceSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * InvoiceBalance merge tag.
 */
class InvoiceBalance extends AbstractInvoiceSalesMergeTag {

	public $name = 'Invoice Balance';

	public $slug = 'invoice_balance';

	public $description = 'Remaining balance due on the invoice.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$invoice = $this->resolve_invoice( $contact );
		if ( ! $invoice ) {
			return '';
		}
		return $this->format_invoice_money( $invoice, 'balance' );
	}
}

MergeTagsManager::instance()->register( new InvoiceBalance() );
