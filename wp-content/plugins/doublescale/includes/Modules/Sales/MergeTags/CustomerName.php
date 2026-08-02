<?php
/**
 * Customer display name merge tag for sales emails.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * CustomerName merge tag.
 */
class CustomerName extends AbstractSalesMergeTag {

	public $name = 'Customer Name';

	public $slug = 'customer_name';

	public $description = 'Customer full name from the linked contact or document.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		return $this->resolve_customer_display_name( $contact );
	}
}

MergeTagsManager::instance()->register( new CustomerName() );
