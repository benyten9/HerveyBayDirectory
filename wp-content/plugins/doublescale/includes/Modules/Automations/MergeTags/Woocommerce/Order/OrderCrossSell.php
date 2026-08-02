<?php
/**
 * Class Order Cross Sell
 *
 * This class is responsible for handling the order cross sell merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Order Cross Sell Merge Tag
 */
class OrderCrossSell extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Cross Sell';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'cross_sell';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Cross Sell';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$order_id = $contact->get_data( 'order_id' );

		$order = wc_get_order( $order_id );

		$cross_sell = $order->get_items( 'cross_sell' );

		$cross_sell_items = array();

		foreach ( $cross_sell as $item ) {
			$product            = $item->get_product();
			$cross_sell_items[] = $product->get_name();
		}

		return implode( ', ', $cross_sell_items );
	}
}

MergeTagsManager::instance()->register( new OrderCrossSell() );
