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
 * CreateTask action stub.
 */
class CreateTask extends ProAutomationStubAction {

	public $name = 'Create a task';

	public $slug = 'create_task';

	public $description = 'This action will create a new task.';

	public $source = 'tasks';

	public $group = 'task';
}

CreateTask::instance();
