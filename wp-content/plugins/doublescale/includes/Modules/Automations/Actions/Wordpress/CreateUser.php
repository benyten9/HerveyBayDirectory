<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Wordpress;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * CreateUser action stub.
 */
class CreateUser extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Create User';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'create_user';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will create a new user.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'wp';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'user';

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

CreateUser::instance();
