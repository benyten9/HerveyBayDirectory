<?php
/**
 * Automation action: complete the triggering project.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;

defined( 'ABSPATH' ) || exit;

/**
 * CompleteProject action.
 */
class CompleteProject extends BaseProjectAction {

	/**
	 * @var string
	 */
	public $name = 'Complete a project';

	/**
	 * @var string
	 */
	public $slug = 'complete_project';

	/**
	 * @var string
	 */
	public $description = 'This action will move the triggering project to a completed status.';

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'project_created',
		'project_status_changed',
		'project_completed',
		'project_owner_changed',
		'project_due_soon',
		'project_overdue',
		'project_comment_posted',
		'project_converted_from_deal',
	);

	/**
	 * {@inheritdoc}
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$project = $this->resolve_project( $automation_contact );
		if ( ! $project ) {
			return false;
		}

		$completed = ProjectStatusModel::query()
			->where( 'is_completed', true )
			->orderBy( 'position', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( ! $completed ) {
			return false;
		}

		return (bool) $project->moveToStatus( (int) $completed->id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}
}