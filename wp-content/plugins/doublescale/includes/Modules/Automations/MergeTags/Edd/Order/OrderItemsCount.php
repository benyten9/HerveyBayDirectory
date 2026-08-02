<?php
/**
 * Class Order Items
 *
 * This class is responsible for handling the order items merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Edd\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Order Items Merge Tag
 */
class OrderItemsCount extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Items Count';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'items_count';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Items Count';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'edd_order';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$payment_id = $contact->get_data( 'payment_id' );
		$payment    = edd_get_payment( $payment_id );
		if ( ! $payment ) {
			return '';
		}

		$items = edd_get_payment_meta_cart_details( $payment_id );

		return count( $items );
	}
}

MergeTagsManager::instance()->register( new OrderItemsCount() );
