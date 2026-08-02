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
 * RemoveSubscriberFromCampaign action stub.
 */
class RemoveSubscriberFromCampaign extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove Subscriber From Campaign';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'drip_remove_subscriber_from_campaign';

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
	public $description = 'This action will remove a subscriber from a Drip campaign.';

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

RemoveSubscriberFromCampaign::instance();
