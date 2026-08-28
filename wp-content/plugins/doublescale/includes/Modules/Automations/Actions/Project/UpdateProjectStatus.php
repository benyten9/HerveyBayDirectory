<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Project;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * UpdateProjectStatus action stub.
 */
class UpdateProjectStatus extends ProAutomationStubAction {

	public $name = 'Update project status';

	public $slug = 'update_project_status';

	public $description = 'This action will update the status of the triggering project.';

	public $source = 'projects';

	public $group = 'project';
}

UpdateProjectStatus::instance();
