<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Delays;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * DelayUntilMonthDay action stub.
 */
class DelayUntilMonthDay extends ProAutomationStubAction {

	/**
	 * Pro class that implements this delay.
	 */
	const PRO_CLASS = '\DoubleScale\Pro\Modules\Automations\Actions\Delays\DelayUntilMonthDay';

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Delay Until Day of the Month';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'delay-until-month-day';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will delay the automation until a day of the month, for example the 25th of next month.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Auto enqueue step
	 *
	 * @var bool
	 */
	public $auto_enqueue = false;

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'delay';

	/**
	 * Register
	 *
	 * An older Pro that predates this delay never replaces the stub, and a
	 * stub that looks unlocked would let a Pro user pick a step that does
	 * nothing. Keep it locked until the Pro class is there. Checked here, on
	 * `init`, because free loads before Pro's autoloader exists.
	 */
	public function register() {
		if ( ! class_exists( self::PRO_CLASS ) ) {
			$this->is_pro = true;
		}

		parent::register();
	}
}

DelayUntilMonthDay::instance();
