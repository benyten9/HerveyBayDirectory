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
 * CompleteProject action stub.
 */
class CompleteProject extends ProAutomationStubAction {

	public $name = 'Complete a project';

	public $slug = 'complete_project';

	public $description = 'This action will move the triggering project to a completed status.';

	public $source = 'projects';

	public $group = 'project';
}

CompleteProject::instance();
