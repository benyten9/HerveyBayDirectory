<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Woocommerce\Wishlist;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * UserAddsProductToWishlist trigger stub.
 */
class UserAddsProductToWishlist extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'User Adds Product To Wishlist';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_user_adds_product_to_wishlist';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user adds a product to their wishlist.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'woocommerce';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'wishlist';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
}

TriggersManager::instance()->register( new UserAddsProductToWishlist() );
