<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Task;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * AssignTask action stub.
 */
class AssignTask extends ProAutomationStubAction {

	public $name = 'Assign a task';

	public $slug = 'assign_task';

	public $description = 'This action will assign the triggering task to a user.';

	public $source = 'tasks';

	public $group = 'task';
}

AssignTask::instance();
