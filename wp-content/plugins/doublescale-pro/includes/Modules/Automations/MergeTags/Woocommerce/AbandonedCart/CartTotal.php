<?php
/**
 * WooCommerce Abandoned Cart Total
 *
 * This class is responsible for handling the Abandoned Cart Total Value
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
 * Abandoned Cart Total Merge Tag
 */
class CartTotal extends AbstractAbandonedCartMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Cart Total';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'total';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Abandoned Cart Total Value';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                   $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$abandoned_cart = $this->resolve_abandoned_cart( $contact );
		if ( ! $abandoned_cart ) {
			return '';
		}

		$total    = $abandoned_cart->total ?? 0;
		$currency = $abandoned_cart->currency ?? get_woocommerce_currency();

		// Format the total with currency
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $total, array( 'currency' => $currency ) ) );
		}

		return $total . ' ' . $currency;
	}
}

MergeTagsManager::instance()->register( new CartTotal() );
