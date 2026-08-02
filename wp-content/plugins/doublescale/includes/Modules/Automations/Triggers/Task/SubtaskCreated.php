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
 * SubtaskCreated trigger stub.
 */
class SubtaskCreated extends TriggerPro {

	public $name = 'Subtask created';

	public $slug = 'subtask_created';

	public $description = 'Fires when a subtask is created.';

	public $attributes = array();

	public $source = 'tasks';

	public $group = 'subtask';
}

TriggersManager::instance()->register( new SubtaskCreated() );
