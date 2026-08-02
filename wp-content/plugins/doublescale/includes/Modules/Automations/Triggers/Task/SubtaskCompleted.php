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
 * SubtaskCompleted trigger stub.
 */
class SubtaskCompleted extends TriggerPro {

	public $name = 'Subtask completed';

	public $slug = 'subtask_completed';

	public $description = 'Fires when a subtask is marked as completed.';

	public $attributes = array();

	public $source = 'tasks';

	public $group = 'subtask';
}

TriggersManager::instance()->register( new SubtaskCompleted() );
