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
 * ProjectDueSoon trigger stub.
 */
class ProjectDueSoon extends TriggerPro {

	public $name = 'Project due soon';

	public $slug = 'project_due_soon';

	public $description = 'Fires when an incomplete project is due within a configured window. Projects without a client contact cannot enroll.';

	public $attributes = array();

	public $source = 'projects';

	public $group = 'project';
}

TriggersManager::instance()->register( new ProjectDueSoon() );
