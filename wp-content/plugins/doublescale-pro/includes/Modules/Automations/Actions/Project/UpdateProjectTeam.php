<?php
/**
 * Automation action: add someone to, or take someone off, the triggering
 * project's team.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectTeam;

defined( 'ABSPATH' ) || exit;

/**
 * UpdateProjectTeam action.
 */
class UpdateProjectTeam extends BaseProjectAction {

	/**
	 * @var string
	 */
	public $name = 'Update project team';

	/**
	 * @var string
	 */
	public $slug = 'update_project_team';

	/**
	 * @var string
	 */
	public $description = 'This action will add a team member to the triggering project, or remove one.';

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

		$member = (int) $step->get_setting( 'member' );
		if ( $member <= 0 ) {
			return false;
		}

		if ( 'remove' === (string) $step->get_setting( 'operation' ) ) {
			if ( (int) $project->owner_id === $member ) {
				return false;
			}
			ProjectTeam::remove( (int) $project->id, $member );
			return true;
		}

		// Only staff who can open projects join; the owner is on the team already.
		if ( null !== ProjectTeam::candidate_error( $project, $member ) ) {
			return false;
		}

		ProjectTeam::add( (int) $project->id, $member, 'automation:' . (int) $automation->id, 0 );
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		$member          = $this->get_owner_field( true );
		$member['label'] = $this->t( 'Team member' );
		$member['placeholder'] = $this->t( 'Search and select a team member…' );

		return array(
			'operation' => array(
				'label'    => $this->t( 'Action' ),
				'type'     => 'select',
				'required' => true,
				'default'  => 'add',
				'options'  => array(
					'add'    => $this->t( 'Add to the team' ),
					'remove' => $this->t( 'Remove from the team' ),
				),
			),
			'member'    => $member,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation' => array(
					'type'     => 'string',
					'enum'     => array( 'add', 'remove' ),
					'required' => true,
				),
				'member'    => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}
