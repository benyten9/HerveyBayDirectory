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
 * AddProjectComment action stub.
 */
class AddProjectComment extends ProAutomationStubAction {

	public $name = 'Add a project comment';

	public $slug = 'add_project_comment';

	public $description = 'This action will add a comment to the triggering project.';

	public $source = 'projects';

	public $group = 'project';
}

AddProjectComment::instance();
