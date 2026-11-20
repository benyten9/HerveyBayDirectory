<?php
/**
 * Class Order Products
 *
 * {{order:products}}: the names of the products bought in the order. Also the
 * base of the other purchased-product tags, which share how the products are
 * collected.
 *
 * @since 1.7.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Order Products Merge Tag
 */
class OrderProducts extends MergeTag {

	/**
	 * Most products listed, so a large wholesale order cannot turn a message
	 * into a catalogue (or push a WhatsApp body past Meta's length limit).
	 */
	const MAX_PRODUCTS = 10;

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Products';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'products';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Names of the products bought in the order';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact   Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$names = array();

		foreach ( $this->get_products_for_contact( $contact ) as $product ) {
			$names[] = $product->get_name();
		}

		return implode( ', ', $names );
	}

	/**
	 * The purchased products of the order the contact entered with.
	 *
	 * @param AutomationContactModel|object $contact Contact Model.
	 *
	 * @return \WC_Product[]
	 */
	protected function get_products_for_contact( $contact ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order_id = is_object( $contact ) && method_exists( $contact, 'get_data' )
			? (int) $contact->get_data( 'order_id' )
			: 0;

		if ( $order_id <= 0 ) {
			return array();
		}

		// wc_get_order() returns false for a missing or trashed order.
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		return $this->get_purchased_products( $order );
	}

	/**
	 * The products a customer can still open and review, once each.
	 *
	 * - A variation is listed as its parent product: that is the page with the
	 *   reviews, and two sizes of one shirt are one product to review.
	 * - Products deleted or no longer published are left out — their link
	 *   would be a 404.
	 * - Items refunded in full are left out: asking for a review of something
	 *   the customer sent back is the wrong message.
	 *
	 * @param \WC_Order $order Order.
	 *
	 * @return \WC_Product[]
	 */
	protected function get_purchased_products( $order ) {
		$products = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$quantity = (float) $item->get_quantity();
			$refunded = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
			if ( $quantity > 0 && $refunded >= $quantity ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( $product->get_parent_id() ) {
				$product = wc_get_product( $product->get_parent_id() );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
			}

			if ( 'publish' !== $product->get_status() ) {
				continue;
			}

			$products[ $product->get_id() ] = $product;

			if ( count( $products ) >= static::MAX_PRODUCTS ) {
				break;
			}
		}

		return array_values( $products );
	}

	/**
	 * Where to send the customer to write a review: the product's Reviews tab
	 * when reviews are open, otherwise the product page itself.
	 *
	 * WooCommerce opens the Reviews tab when the URL ends in #reviews.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string
	 */
	protected function get_review_url( $product ) {
		$url = (string) $product->get_permalink();

		return $this->accepts_reviews( $product ) ? $url . '#reviews' : $url;
	}

	/**
	 * Whether the customer can leave a review on this product.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return bool
	 */
	protected function accepts_reviews( $product ) {
		$enabled = function_exists( 'wc_reviews_enabled' ) ? wc_reviews_enabled() : true;

		return $enabled && $product->get_reviews_allowed();
	}
}

MergeTagsManager::instance()->register( new OrderProducts() );
