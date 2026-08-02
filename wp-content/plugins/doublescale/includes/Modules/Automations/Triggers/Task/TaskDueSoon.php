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
 * TaskDueSoon trigger stub.
 */
class TaskDueSoon extends TriggerPro {

	public $name = 'Task due soon';

	public $slug = 'task_due_soon';

	public $description = 'Fires when a pending task is due within a configured window.';

	public $attributes = array();

	public $source = 'tasks';

	public $group = 'task';
}

TriggersManager::instance()->register( new TaskDueSoon() );
