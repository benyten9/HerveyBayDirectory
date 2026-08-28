<?php
/**
 * Automation action: add a comment to the triggering project.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectDiscussionModel;

defined( 'ABSPATH' ) || exit;

/**
 * AddProjectComment action.
 */
class AddProjectComment extends BaseProjectAction {

	/**
	 * @var string
	 */
	public $name = 'Add a project comment';

	/**
	 * @var string
	 */
	public $slug = 'add_project_comment';

	/**
	 * @var string
	 */
	public $description = 'This action will add a comment to the triggering project.';

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

		$body = $this->parse_text( $step->get_setting( 'body' ), $automation_contact );
		$body = trim( wp_kses_post( (string) $body ) );
		if ( '' === $body ) {
			return false;
		}

		$user_id = get_current_user_id() ?: (int) ( $project->owner_id ?: 1 );

		$discussion = ProjectDiscussionModel::create(
			array(
				'project_id' => (int) $project->id,
				'parent_id'  => null,
				'user_id'    => $user_id,
				'body'       => $body,
			)
		);

		if ( ! $discussion ) {
			return false;
		}

		do_action( 'doublescale_project_comment_posted', $project, $discussion, null );

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'body' => array(
				'label'    => $this->t( 'Comment' ),
				'type'     => 'textarea',
				'required' => true,
				'tooltip'  => $this->t( 'Supports merge tags.' ),
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
				'body' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}