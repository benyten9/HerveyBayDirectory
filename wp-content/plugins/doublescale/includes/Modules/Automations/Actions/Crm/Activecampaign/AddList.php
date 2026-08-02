<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Crm\Activecampaign;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * AddList action stub.
 */
class AddList extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add List';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'activecampaign_add_list';

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
	public $group = 'activecampaign';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add list to a contact in ActiveCampaign.';

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	public $is_integration = true;

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

AddList::instance();
