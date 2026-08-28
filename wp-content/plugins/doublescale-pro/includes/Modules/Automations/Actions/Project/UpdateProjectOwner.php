<?php
/**
 * Automation action: update the triggering project's owner.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

defined( 'ABSPATH' ) || exit;

/**
 * UpdateProjectOwner action.
 */
class UpdateProjectOwner extends BaseProjectAction {

	/**
	 * @var string
	 */
	public $name = 'Update project owner';

	/**
	 * @var string
	 */
	public $slug = 'update_project_owner';

	/**
	 * @var string
	 */
	public $description = 'This action will update the owner of the triggering project.';

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

		$owner = (int) $step->get_setting( 'owner' );
		if ( $owner <= 0 ) {
			return false;
		}

		$project->owner_id = $owner;
		return (bool) $project->save();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'owner' => $this->get_owner_field( true ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'owner' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}