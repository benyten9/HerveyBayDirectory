<?php
/**
 * Automation trigger: project status changed.
 *
 * Dedupes the two emitters: ProjectModel::moveToStatus() and
 * ProjectManager::log_project_status_change().
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectStatusChanged trigger.
 */
class ProjectStatusChanged extends BaseProjectTrigger {

	/**
	 * @var string
	 */
	public $name = 'Project status changed';

	/**
	 * @var string
	 */
	public $slug = 'project_status_changed';

	/**
	 * @var string
	 */
	public $description = 'Fires when a project status changes. Projects without a client contact cannot enroll.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_project_status_changed', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * @param mixed $project       Project model.
	 * @param mixed $old_status_id Previous status ID.
	 * @param mixed $new_status_id New status ID.
	 */
	public function handle( $project, $old_status_id = null, $new_status_id = null ): void {
		if ( ! $project instanceof ProjectModel ) {
			return;
		}

		if ( (string) $old_status_id === (string) $new_status_id ) {
			return;
		}

		if ( ! self::claim_status_change( (int) $project->id, $old_status_id, $new_status_id ) ) {
			return;
		}

		$this->enroll(
			$project,
			array(
				'old_status_id' => $old_status_id,
				'new_status_id' => $new_status_id,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$project = $args['project'] ?? null;
		if ( ! $project instanceof ProjectModel ) {
			return false;
		}

		$old = $automation->get_setting( 'old_status', 'any-status' );
		$new = $automation->get_setting( 'new_status', 'any-status' );

		$old_actual = $args['data']['old_status_id'] ?? null;
		$new_actual = $args['data']['new_status_id'] ?? null;

		return $this->matches_any_or_value( $old, $old_actual, 'any-status' )
			&& $this->matches_any_or_value( $new, $new_actual, 'any-status' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		$options = $this->get_status_options();

		return array(
			'old_status' => array(
				'label'         => $this->t( 'Old status' ),
				'type'          => 'select',
				'options'       => $options,
				'default-value' => 'any-status',
			),
			'new_status' => array(
				'label'         => $this->t( 'New status' ),
				'type'          => 'select',
				'options'       => $options,
				'default-value' => 'any-status',
			),
		);
	}
}