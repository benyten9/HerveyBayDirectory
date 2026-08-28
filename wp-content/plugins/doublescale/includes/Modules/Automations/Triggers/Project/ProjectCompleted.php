<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Project;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * ProjectCompleted trigger stub.
 */
class ProjectCompleted extends TriggerPro {

	public $name = 'Project completed';

	public $slug = 'project_completed';

	public $description = 'Fires when a project moves to a completed status. Projects without a client contact cannot enroll.';

	public $attributes = array();

	public $source = 'projects';

	public $group = 'project';
}

TriggersManager::instance()->register( new ProjectCompleted() );
