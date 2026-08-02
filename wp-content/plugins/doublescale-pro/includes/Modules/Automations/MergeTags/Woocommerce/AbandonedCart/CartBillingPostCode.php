<?php
/**
 * WooCommerce Abandoned Cart Billing Post Code
 *
 * This class is responsible for handling the Abandoned Cart Billing Post Code
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
 * Abandoned Cart Billing Post_Code Merge Tag
 */
class CartBillingPostCode extends AbstractAbandonedCartMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Cart Billing Post Code';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'billing_post_code';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Abandoned Cart Billing Post Code';

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

		// WooCommerce uses billing_postcode; some front-ends may send billing_post_code.
		$fields            = is_array( $abandoned_cart->fields ) ? $abandoned_cart->fields : array();
		$billing_post_code = $fields['billing_postcode'] ?? $fields['billing_post_code'] ?? '';

		return $billing_post_code;
	}
}

MergeTagsManager::instance()->register( new CartBillingPostCode() );
