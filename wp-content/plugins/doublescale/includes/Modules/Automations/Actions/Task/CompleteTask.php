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
 * CompleteTask action stub.
 */
class CompleteTask extends ProAutomationStubAction {

	public $name = 'Complete a task';

	public $slug = 'complete_task';

	public $description = 'This action will mark the triggering task as completed.';

	public $source = 'tasks';

	public $group = 'task';
}

CompleteTask::instance();
