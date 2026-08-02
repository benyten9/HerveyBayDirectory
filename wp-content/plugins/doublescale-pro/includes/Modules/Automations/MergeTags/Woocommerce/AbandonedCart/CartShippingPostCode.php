<?php
/**
 * WooCommerce Abandoned Cart Shipping Post Code
 *
 * This class is responsible for handling the Abandoned Cart Shipping Post Code
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\AbandonedCart;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\MergeTags\Woocommerce\AbandonedCart\AbstractAbandonedCartMergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Abandoned Cart Shipping Post_Code Merge Tag
 */
class CartShippingPostCode extends AbstractAbandonedCartMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Cart Shipping Post Code';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'shipping_post_code';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Abandoned Cart Shipping Post Code';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model. Contact Model.
	 * @param string                   $merge_tag         Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$abandoned_cart = $this->resolve_abandoned_cart( $contact );
		if ( ! $abandoned_cart ) {
			return '';
		}

		// WooCommerce uses shipping_postcode; some front-ends may send shipping_post_code.
		$fields             = is_array( $abandoned_cart->fields ) ? $abandoned_cart->fields : array();
		$shipping_post_code = $fields['shipping_postcode'] ?? $fields['shipping_post_code'] ?? '';

		return $shipping_post_code;
	}
}

MergeTagsManager::instance()->register( new CartShippingPostCode() );
