<?php
/**
 * Shared WooCommerce order trigger product filtering.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Order;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Product filter helpers for WooCommerce order triggers.
 */
trait OrderTriggerProductFilter {

	/**
	 * Whether the automation's optional product filter matches the order in $args.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger payload.
	 */
	protected function order_matches_product_filter( AutomationModel $automation, array $args ): bool {
		$product_ids = $automation->get_setting( 'product_ids', array() );
		if ( empty( $product_ids ) ) {
			return true;
		}

		$order_id = isset( $args['data']['order_id'] ) ? absint( $args['data']['order_id'] ) : 0;
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$allowed = array_filter( array_unique( array_map( 'absint', (array) $product_ids ) ) );
		if ( empty( $allowed ) ) {
			return true;
		}

		$order_product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$order_product_ids[] = (int) $product->get_id();
			$parent_id           = (int) $product->get_parent_id();
			if ( $parent_id ) {
				$order_product_ids[] = $parent_id;
			}
		}

		$order_product_ids = array_unique( array_filter( $order_product_ids ) );

		return ! empty( array_intersect( $allowed, $order_product_ids ) );
	}
}
