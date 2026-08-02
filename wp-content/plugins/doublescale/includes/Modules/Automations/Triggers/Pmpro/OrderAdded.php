<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Pmpro;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * OrderAdded trigger stub.
 */
class OrderAdded extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Added';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_order_added';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a new order is added in Paid Memberships Pro.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'pmpro';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'pmpro';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
}

TriggersManager::instance()->register( new OrderAdded() );
