<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Crm\Convertkit;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * AddToSequence action stub.
 */
class AddToSequence extends ProAutomationStubAction {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add To Sequence';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'convertkit_add_to_sequence';

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
	public $group = 'convertkit';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a contact to a sequence in Convertkit';

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

AddToSequence::instance();
