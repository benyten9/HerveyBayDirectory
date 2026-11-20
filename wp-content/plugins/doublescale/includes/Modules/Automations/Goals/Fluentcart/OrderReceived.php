<?php
/**
 * FluentCart "Order Received" goal (free plugin): definition only. The
 * runtime hook ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Goals\Fluentcart;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\GoalPro;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\GoalsManager;

/**
 * OrderReceived goal stub.
 */
class OrderReceived extends GoalPro {

	/**
	 * Pro class that runs this goal.
	 */
	public const PRO_CLASS = '\\DoubleScale\\Pro\\Modules\\Automations\\Goals\\Fluentcart\\OrderReceived';

	/**
	 * Goal Name
	 *
	 * @var string
	 */
	public $name = 'Order Received';

	/**
	 * Goal Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_received';

	/**
	 * Goal Description
	 *
	 * @var string
	 */
	public $description = 'This goal is achieved when the contact pays a FluentCart order.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'fluentcart';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		// Pro is on but older than the FluentCart goal: keep it locked rather
		// than offer a goal that is never reached.
		if ( ! $this->is_pro && ! class_exists( self::PRO_CLASS ) ) {
			$this->is_pro = true;
		}
	}

	/**
	 * Is completed
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param mixed                  $data               Data.
	 *
	 * @return bool
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		return false;
	}
}

GoalsManager::instance()->register( new OrderReceived() );
