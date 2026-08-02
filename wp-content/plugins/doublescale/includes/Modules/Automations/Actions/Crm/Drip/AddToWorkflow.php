<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Crm\Drip;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * AddToWorkflow action stub.
 */
class AddToWorkflow extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add To Workflow';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'drip_add_to_workflow';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'send_data';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'drip';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a subscriber to a Drip workflow.';

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

AddToWorkflow::instance();
