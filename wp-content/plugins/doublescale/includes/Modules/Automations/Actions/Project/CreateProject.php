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
 * CreateProject action stub.
 */
class CreateProject extends ProAutomationStubAction {

	public $name = 'Create a project';

	public $slug = 'create_project';

	public $description = 'This action will create a new project.';

	public $source = 'projects';

	public $group = 'project';
}

CreateProject::instance();
