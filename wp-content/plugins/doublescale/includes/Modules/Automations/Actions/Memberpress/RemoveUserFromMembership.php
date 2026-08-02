<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Memberpress;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * RemoveUserFromMembership action stub.
 */
class RemoveUserFromMembership extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove User From Membership';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_remove_user_from_membership';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove a user from a MemberPress membership by expiring their active transactions.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'memberpress';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'memberpress';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 *
	 * @return bool
	 */
}

RemoveUserFromMembership::instance();
