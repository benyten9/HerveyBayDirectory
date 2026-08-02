<?php
/**
 * Invoice due date merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Sales\MergeTags\AbstractInvoiceSalesMergeTag;

/**
 * InvoiceDueDate merge tag.
 */
class InvoiceDueDate extends AbstractInvoiceSalesMergeTag {

	public $name = 'Invoice Due Date';

	public $slug = 'invoice_due_date';

	public $description = 'Invoice payment due date.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$invoice = $this->resolve_invoice( $contact );
		return $invoice && $invoice->due_date ? (string) $invoice->due_date : '';
	}
}

MergeTagsManager::instance()->register( new InvoiceDueDate() );
