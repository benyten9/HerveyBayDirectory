<?php
/**
 * Invoice number merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\MergeTags\AbstractInvoiceSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * InvoiceNumber merge tag.
 */
class InvoiceNumber extends AbstractInvoiceSalesMergeTag {

	public $name = 'Invoice Number';

	public $slug = 'invoice_number';

	public $description = 'Invoice reference number.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$invoice = $this->resolve_invoice( $contact );
		return $invoice ? (string) $invoice->invoice_number : '';
	}
}

MergeTagsManager::instance()->register( new InvoiceNumber() );
