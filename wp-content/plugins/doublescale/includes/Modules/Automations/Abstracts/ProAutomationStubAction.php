<?php
/**
 * Base class for Pro-only automation actions shipped in the free plugin as definitions only.
 * Full behavior is registered from the Pro add-on (same slug) when the add-on is active.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

/**
 * Pro automation stub action (metadata + safe no-op implementation on free).
 */
abstract class ProAutomationStubAction extends ActionPro {

	/**
	 * Free catalog path: never run real side effects here; {@see ProcessAutomation} also skips when is_pro.
	 *
	 * @param AutomationModel        $automation Automation model.
	 * @param AutomationStepModel    $step Step model.
	 * @param AutomationContactModel $automation_contact Enrollment row.
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		return false;
	}
}
