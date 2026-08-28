<?php
/**
 * Automation action: update the triggering project's status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

defined( 'ABSPATH' ) || exit;

/**
 * UpdateProjectStatus action.
 */
class UpdateProjectStatus extends BaseProjectAction {

	/**
	 * @var string
	 */
	public $name = 'Update project status';

	/**
	 * @var string
	 */
	public $slug = 'update_project_status';

	/**
	 * @var string
	 */
	public $description = 'This action will update the status of the triggering project.';

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

		$status_id = (int) $step->get_setting( 'status_id' );
		if ( $status_id <= 0 ) {
			return false;
		}

		return (bool) $project->moveToStatus( $status_id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'status_id' => array(
				'label'    => $this->t( 'Status' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $this->get_status_options(),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}