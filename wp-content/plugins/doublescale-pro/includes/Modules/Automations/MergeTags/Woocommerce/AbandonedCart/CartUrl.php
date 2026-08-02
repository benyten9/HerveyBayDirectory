<?php
/**
 * WooCommerce Abandoned Cart URL
 *
 * This class is responsible for handling the Abandoned Cart URL
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
 * Abandoned Cart URL Merge Tag
 */
class CartUrl extends AbstractAbandonedCartMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Cart Recovery URL';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'url';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Abandoned Cart Recovery URL';

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

		$abandoned_cart_hash = $abandoned_cart->hash_key;
		$abandoned_cart_url  = add_query_arg( 'doublescale-cart-id', $abandoned_cart_hash, home_url() );

		return $abandoned_cart_url;
	}
}

MergeTagsManager::instance()->register( new CartUrl() );
