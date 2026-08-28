<?php
/**
 * Automation action: create a project.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Pro\Modules\Automations\Triggers\Project\BaseProjectTrigger;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;

defined( 'ABSPATH' ) || exit;

/**
 * CreateProject action.
 */
class CreateProject extends BaseProjectAction {

	/**
	 * @var string
	 */
	public $name = 'Create a project';

	/**
	 * @var string
	 */
	public $slug = 'create_project';

	/**
	 * @var string
	 */
	public $description = 'This action will create a new project.';

	/**
	 * {@inheritdoc}
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		if ( ! $this->projects_storage_ready() ) {
			return false;
		}

		$contact = $automation_contact->contact ?? null;
		if ( ! $contact || empty( $contact->id ) ) {
			return false;
		}

		$title = $this->parse_text( $step->get_setting( 'title' ), $automation_contact );
		if ( '' === trim( (string) $title ) ) {
			$title = $this->t( 'New project' );
		}

		$status_id = (int) $step->get_setting( 'status_id' );
		if ( $status_id <= 0 ) {
			$default = ProjectStatusModel::orderBy( 'position', 'asc' )->orderBy( 'id', 'asc' )->first();
			$status_id = $default ? (int) $default->id : 0;
		}
		if ( $status_id <= 0 ) {
			return false;
		}

		$owner = (int) $step->get_setting( 'owner' );
		if ( $owner <= 0 ) {
			$owner = get_current_user_id() ?: 1;
		}

		$data = is_array( $automation_contact->data ) ? $automation_contact->data : array();
		$deal_id = isset( $data['deal_id'] ) ? (int) $data['deal_id'] : null;

		$due_offset = max( 0, (int) $step->get_setting( 'due_offset_days', 0 ) );
		$due_date   = null;
		if ( $due_offset > 0 ) {
			$due_date = date( 'Y-m-d', strtotime( '+' . $due_offset . ' days', current_time( 'timestamp' ) ) );
		}

		$budget = $step->get_setting( 'budget' );
		$budget = ( '' !== (string) $budget && null !== $budget ) ? (float) $this->parse_text( (string) $budget, $automation_contact ) : null;

		$payload = array(
			'title'       => $title,
			'description' => $this->parse_text( $step->get_setting( 'description' ), $automation_contact ),
			'status_id'   => $status_id,
			'contact_id'  => (int) $contact->id,
			'owner_id'    => $owner,
			'deal_id'     => $deal_id > 0 ? $deal_id : null,
			'budget'      => $budget,
			'due_date'    => $due_date,
			'start_date'  => current_time( 'Y-m-d' ),
		);

		BaseProjectTrigger::suppress_enrollment( true );
		try {
			$project = ProjectModel::create( $payload );
			if ( ! $project ) {
				return false;
			}
			$automation_contact->set_data( array( 'project_id' => (int) $project->id ) );
		} finally {
			BaseProjectTrigger::suppress_enrollment( false );
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'title'           => array(
				'label'    => $this->t( 'Title' ),
				'type'     => 'text',
				'required' => true,
				'tooltip'  => $this->t( 'Supports merge tags.' ),
			),
			'description'     => array(
				'label' => $this->t( 'Description' ),
				'type'  => 'textarea',
			),
			'status_id'       => array(
				'label'   => $this->t( 'Status' ),
				'type'    => 'select',
				'options' => $this->get_status_options(),
			),
			'owner'           => $this->get_owner_field(),
			'budget'          => array(
				'label'   => $this->t( 'Budget' ),
				'type'    => 'text',
				'tooltip' => $this->t( 'Supports merge tags.' ),
			),
			'due_offset_days' => array(
				'label'   => $this->t( 'Due in (days)' ),
				'type'    => 'number',
				'tooltip' => $this->t( 'Number of days from when this action runs. Leave empty for no due date.' ),
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
				'title'           => array(
					'type'     => 'string',
					'required' => true,
				),
				'description'     => array(
					'type' => 'string',
				),
				'status_id'       => array(
					'type' => 'integer',
				),
				'owner'           => array(
					'type' => 'integer',
				),
				'budget'          => array(
					'type' => 'string',
				),
				'due_offset_days' => array(
					'type' => 'integer',
				),
			),
		);
	}
}