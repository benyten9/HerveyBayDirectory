<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Task;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * TaskCompleted trigger stub.
 */
class TaskCompleted extends TriggerPro {

	public $name = 'Task completed';

	public $slug = 'task_completed';

	public $description = 'Fires when a task is marked as completed.';

	public $attributes = array();

	public $source = 'tasks';

	public $group = 'task';
}

TriggersManager::instance()->register( new TaskCompleted() );
