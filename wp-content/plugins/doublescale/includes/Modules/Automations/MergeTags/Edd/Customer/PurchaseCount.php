<?php
/**
 * Class Purchase Count
 *
 * This class is responsible for handling the purchase count merge tag
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
 * Purchase Count Merge Tag
 */
class PurchaseCount extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Purchase Count';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'purchase_count';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Purchase Count';

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
		if ( ! $this->is_automation_contact( $contact ) ) {
			return '';
		}

		$customer_id = $contact->get_data( 'customer_id' );
		$customer    = edd_get_customer( $customer_id );
		if ( ! $customer ) {
			return '';
		}

		$purchase_count = $customer->purchase_count;

		return $purchase_count;
	}
}

MergeTagsManager::instance()->register( new PurchaseCount() );
