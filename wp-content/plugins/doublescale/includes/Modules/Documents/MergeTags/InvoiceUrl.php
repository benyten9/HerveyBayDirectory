<?php
/**
 * Invoice public URL merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\MergeTags\AbstractInvoiceSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * InvoiceUrl merge tag.
 */
class InvoiceUrl extends AbstractInvoiceSalesMergeTag {

	public $name = 'Invoice URL';

	public $slug = 'invoice_url';

	public $description = 'Public link for the customer to view and pay the invoice.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$invoice = $this->resolve_invoice( $contact );
		return $invoice ? $this->invoice_public_url( $invoice ) : '';
	}
}

MergeTagsManager::instance()->register( new InvoiceUrl() );
