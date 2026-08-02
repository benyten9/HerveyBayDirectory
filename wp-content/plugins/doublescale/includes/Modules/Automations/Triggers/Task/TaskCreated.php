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
 * TaskCreated trigger stub.
 */
class TaskCreated extends TriggerPro {

	public $name = 'Task created';

	public $slug = 'task_created';

	public $description = 'Fires when a new task is created.';

	public $attributes = array();

	public $source = 'tasks';

	public $group = 'task';
}

TriggersManager::instance()->register( new TaskCreated() );
