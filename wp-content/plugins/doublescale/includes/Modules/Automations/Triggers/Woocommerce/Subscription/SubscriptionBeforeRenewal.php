<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Woocommerce\Subscription;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * SubscriptionBeforeRenewal trigger stub.
 */
class SubscriptionBeforeRenewal extends TriggerPro {

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
	public $name = 'Subscription Before Renewal';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_subscription_before_renewal';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired before a subscription renewal payment is processed.';

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
	public $group = 'subscription';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
}

TriggersManager::instance()->register( new SubscriptionBeforeRenewal() );
