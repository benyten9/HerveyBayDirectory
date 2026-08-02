<?php
/**
 * Invoice total merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\MergeTags\AbstractInvoiceSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * InvoiceTotal merge tag.
 */
class InvoiceTotal extends AbstractInvoiceSalesMergeTag {

	public $name = 'Invoice Total';

	public $slug = 'invoice_total';

	public $description = 'Formatted invoice total with currency.';

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
		return $this->format_invoice_money( $invoice, 'total' );
	}
}

MergeTagsManager::instance()->register( new InvoiceTotal() );
