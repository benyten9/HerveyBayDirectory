<?php
/**
 * WooCommerce Abandoned Cart ID
 *
 * This class is responsible for handling the Abandoned Cart ID
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
 * Abandoned Cart ID Merge Tag
 */
class CartId extends AbstractAbandonedCartMergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Cart ID';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'id';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Abandoned Cart ID';

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

		return $abandoned_cart->id;
	}
}

MergeTagsManager::instance()->register( new CartId() );
