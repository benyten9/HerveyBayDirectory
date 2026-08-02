<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * ChangeOrderStatus action stub.
 */
class ChangeOrderStatus extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Change Order Status';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'change_order_status';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will change the order status.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'woocommerce';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Required triggers for this action to be enabled
	 *
	 * @var array
	 */
	public $required_triggers = array( 'wc_order_created', 'wc_order_completed', 'wc_order_status_changed', 'wc_order_refunded' );


	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
}

ChangeOrderStatus::instance();
