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
 * DeleteTask action stub.
 */
class DeleteTask extends ProAutomationStubAction {

	public $name = 'Delete a task';

	public $slug = 'delete_task';

	public $description = 'This action will delete the triggering task.';

	public $source = 'tasks';

	public $group = 'task';
}

DeleteTask::instance();
