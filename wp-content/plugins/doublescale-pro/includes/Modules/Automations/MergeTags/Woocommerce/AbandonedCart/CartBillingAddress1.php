<?php
/**
 * WooCommerce Abandoned Cart Billing Address 1
 *
 * This class is responsible for handling the Abandoned Cart Billing Address 1
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
 * Abandoned Cart Billing Address 1 Merge Tag
 */
class CartBillingAddress1 extends AbstractAbandonedCartMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Cart Billing Address 1';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'billing_address_1';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Abandoned Cart Billing Address 1';

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

		$billing_address_1 = $abandoned_cart->fields['billing_address_1'] ?? '';

		return $billing_address_1;
	}
}

MergeTagsManager::instance()->register( new CartBillingAddress1() );
