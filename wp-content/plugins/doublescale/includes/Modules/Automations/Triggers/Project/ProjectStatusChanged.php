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
 * ProjectStatusChanged trigger stub.
 */
class ProjectStatusChanged extends TriggerPro {

	public $name = 'Project status changed';

	public $slug = 'project_status_changed';

	public $description = 'Fires when a project status changes. Projects without a client contact cannot enroll.';

	public $attributes = array();

	public $source = 'projects';

	public $group = 'project';
}

TriggersManager::instance()->register( new ProjectStatusChanged() );
