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
 * TaskOverdue trigger stub.
 */
class TaskOverdue extends TriggerPro {

	public $name = 'Task overdue';

	public $slug = 'task_overdue';

	public $description = 'Fires when a pending task becomes overdue.';

	public $attributes = array();

	public $source = 'tasks';

	public $group = 'task';
}

TriggersManager::instance()->register( new TaskOverdue() );
