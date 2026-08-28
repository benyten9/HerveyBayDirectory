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
		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order_id = is_object( $contact ) && method_exists( $contact, 'get_data' )
			? (int) $contact->get_data( 'order_id' )
			: 0;

		if ( $order_id <= 0 ) {
			return '';
		}

		// wc_get_order() returns false for a missing or trashed order.
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$cross_sell_items = array();

		foreach ( $this->get_cross_sell_products( $order ) as $product ) {
			$cross_sell_items[] = $product->get_name();
		}

		return implode( ', ', $cross_sell_items );
	}

	/**
	 * Resolve the cross-sell products linked to an order's line items.
	 *
	 * WooCommerce has no 'cross_sell' line-item type — cross-sells are a
	 * property of each purchased product, so they are collected from the order's
	 * products rather than read off the order directly.
	 *
	 * @param \WC_Order $order Order.
	 *
	 * @return \WC_Product[] Unique cross-sell products, purchased ones excluded.
	 */
	protected function get_cross_sell_products( $order ) {
		$purchased_ids  = array();
		$cross_sell_ids = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product' ) ) {
				continue;
			}

			$product = $item->get_product();

			// Deleted products yield null here — the original crash.
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$purchased_ids[] = (int) $product->get_id();

			foreach ( (array) $product->get_cross_sell_ids() as $cross_sell_id ) {
				$cross_sell_ids[] = (int) $cross_sell_id;
			}
		}

		$cross_sell_ids = array_diff(
			array_unique( array_filter( $cross_sell_ids ) ),
			$purchased_ids
		);

		$products = array();

		foreach ( $cross_sell_ids as $cross_sell_id ) {
			$product = wc_get_product( $cross_sell_id );

			// Skip anything deleted, draft, or otherwise not purchasable.
			if ( ! $product instanceof \WC_Product || ! $product->is_visible() ) {
				continue;
			}

			$products[] = $product;
		}

		return $products;
	}
}

MergeTagsManager::instance()->register( new OrderCrossSell() );
