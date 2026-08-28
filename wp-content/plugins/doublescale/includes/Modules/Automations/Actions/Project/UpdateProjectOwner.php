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
 * UpdateProjectOwner action stub.
 */
class UpdateProjectOwner extends ProAutomationStubAction {

	public $name = 'Update project owner';

	public $slug = 'update_project_owner';

	public $description = 'This action will update the owner of the triggering project.';

	public $source = 'projects';

	public $group = 'project';
}

UpdateProjectOwner::instance();
