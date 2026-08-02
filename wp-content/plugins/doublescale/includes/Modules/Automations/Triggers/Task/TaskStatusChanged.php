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
 * TaskStatusChanged trigger stub.
 */
class TaskStatusChanged extends TriggerPro {

	public $name = 'Task status changed';

	public $slug = 'task_status_changed';

	public $description = 'Fires when a task kanban status changes.';

	public $attributes = array();

	public $source = 'tasks';

	public $group = 'task';
}

TriggersManager::instance()->register( new TaskStatusChanged() );
