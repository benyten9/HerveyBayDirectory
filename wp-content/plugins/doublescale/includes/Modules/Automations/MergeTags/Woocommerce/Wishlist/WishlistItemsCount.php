<?php

/**
 * Wishlist Items Count Merge Tag
 *
 * This class is responsible for handling the wishlist items count merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Wishlist;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Wishlist Items Count Merge Tag
 */
class WishlistItemsCount extends MergeTag {













	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Wishlist Items Count';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'wishlist_items_count';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Wishlist Items Count';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'wishlist';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array( 'wc_user_adds_product_to_wishlist', 'wc_wishlist_item_on_sale', 'wc_wishlist_reminder' );

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$wishlist_id = $contact->get_data( 'wishlist_id' );

		if ( ! $wishlist_id ) {
			return '0';
		}

		$wishlist_items = get_post_meta( $wishlist_id, '_wishlist_items', true );
		$items_count    = count( $wishlist_items );
		return (string) $items_count;
	}
}

MergeTagsManager::instance()->register( new WishlistItemsCount() );
