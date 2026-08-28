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
 * UpdateCustomFieldProject action stub.
 */
class UpdateCustomFieldProject extends ProAutomationStubAction {

	public $name = 'Update a project custom field';

	public $slug = 'update_custom_field_project';

	public $description = 'This action will update a custom field on the triggering project.';

	public $source = 'projects';

	public $group = 'project';
}

UpdateCustomFieldProject::instance();
