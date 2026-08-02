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
 * AddOrderNote action stub.
 */
class AddOrderNote extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add Order Note';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'add_order_note';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a note to the order.';

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
	 * @param AutomationModel         $automation
	 * @param AutomationStepModel    $step
	 * @param AutomationContactModel $contact
	 */
}

AddOrderNote::instance();
