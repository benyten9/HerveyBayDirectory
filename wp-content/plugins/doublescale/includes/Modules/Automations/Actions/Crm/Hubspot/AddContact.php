<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Crm\Hubspot;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * AddContact action stub.
 */
class AddContact extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add Contact';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'hubspot_add_contact';

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
	public $group = 'hubspot';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a contact to Hubspot.';

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

AddContact::instance();
