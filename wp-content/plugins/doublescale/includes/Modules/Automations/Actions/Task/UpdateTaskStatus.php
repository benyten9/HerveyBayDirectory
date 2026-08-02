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
 * UpdateTaskStatus action stub.
 */
class UpdateTaskStatus extends ProAutomationStubAction {

	public $name = 'Update task status';

	public $slug = 'update_task_status';

	public $description = 'This action will update the kanban status of the triggering task.';

	public $source = 'tasks';

	public $group = 'task';
}

UpdateTaskStatus::instance();
