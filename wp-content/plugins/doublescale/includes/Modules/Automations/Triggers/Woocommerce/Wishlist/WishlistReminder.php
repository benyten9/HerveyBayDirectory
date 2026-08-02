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
 * WishlistReminder trigger stub.
 */
class WishlistReminder extends TriggerPro {

	/**
	 * Tasks instance for scheduling cron jobs
	 *
	 * @var \DoubleScale\Core\Tasks
	 */
	private $tasks;

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Wishlist Reminder';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_wishlist_reminder';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired to remind users about items in their wishlist after a specified period.';

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
	 * Constructor
	 *
	 * @since 1.0.0
	 */
}

TriggersManager::instance()->register( new WishlistReminder() );
