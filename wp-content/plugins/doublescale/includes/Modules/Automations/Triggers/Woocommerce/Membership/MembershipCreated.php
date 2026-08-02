<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Woocommerce\Membership;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * MembershipCreated trigger stub.
 */
class MembershipCreated extends TriggerPro {

	/**
	 * Admin membership ID for tracking admin-created memberships
	 *
	 * @var int|null
	 */
	public $admin_membership = null;
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Membership Created';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_membership_created';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a new membership is created in WooCommerce Memberships.';

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
	public $group = 'membership';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
}

TriggersManager::instance()->register( new MembershipCreated() );
