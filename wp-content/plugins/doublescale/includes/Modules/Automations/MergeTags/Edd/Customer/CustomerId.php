<?php
/**
 * Class Customer ID
 *
 * This class is responsible for handling the customer ID merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Edd\Customer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Customer ID Merge Tag
 */
class CustomerId extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Customer ID';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'id';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Customer ID';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'edd_customer';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$customer_id = $contact->get_data( 'customer_id' );

		return $customer_id;
	}
}

MergeTagsManager::instance()->register( new CustomerId() );
