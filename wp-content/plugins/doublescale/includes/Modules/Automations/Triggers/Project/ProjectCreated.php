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
 * ProjectCreated trigger stub.
 */
class ProjectCreated extends TriggerPro {

	public $name = 'Project created';

	public $slug = 'project_created';

	public $description = 'Fires when a new project is created. Projects without a client contact cannot enroll.';

	public $attributes = array();

	public $source = 'projects';

	public $group = 'project';
}

TriggersManager::instance()->register( new ProjectCreated() );
