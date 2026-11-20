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
 * UpdateProjectTeam action stub.
 */
class UpdateProjectTeam extends ProAutomationStubAction {

	public $name = 'Update project team';

	public $slug = 'update_project_team';

	public $description = 'This action will add a team member to the triggering project, or remove one.';

	public $source = 'projects';

	public $group = 'project';
}

UpdateProjectTeam::instance();
