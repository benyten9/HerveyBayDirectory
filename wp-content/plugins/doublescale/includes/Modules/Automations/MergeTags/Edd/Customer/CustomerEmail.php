<?php
/**
 * Class Customer Email
 *
 * This class is responsible for handling the customer email merge tag
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
 * Customer Email Merge Tag
 */
class CustomerEmail extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Customer Email';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'email';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Customer Email';

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
		$customer    = edd_get_customer( $customer_id );
		if ( ! $customer ) {
			return '';
		}

		return $customer->email;
	}
}

MergeTagsManager::instance()->register( new CustomerEmail() );
