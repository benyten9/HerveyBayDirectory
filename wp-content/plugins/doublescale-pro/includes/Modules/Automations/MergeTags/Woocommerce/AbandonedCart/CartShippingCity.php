<?php
/**
 * WooCommerce Abandoned Cart Shipping City
 *
 * This class is responsible for handling the Abandoned Cart Shipping City
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
 * Abandoned Cart Shipping City Merge Tag
 */
class CartShippingCity extends AbstractAbandonedCartMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Cart Shipping City';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'shipping_city';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Abandoned Cart Shipping City';

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

		$shipping_city = $abandoned_cart->fields['shipping_city'] ?? '';

		return $shipping_city;
	}
}

MergeTagsManager::instance()->register( new CartShippingCity() );
