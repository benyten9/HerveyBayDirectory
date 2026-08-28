<?php
/**
 * Automation trigger: project completed.
 *
 * Listens to status_changed and filters on the new status's is_completed flag.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectCompleted trigger.
 */
class ProjectCompleted extends BaseProjectTrigger {

	/**
	 * In-request dedupe for the two status-changed emitters.
	 *
	 * @var array<string,bool>
	 */
	private static $completed_seen = array();

	/**
	 * @var string
	 */
	public $name = 'Project completed';

	/**
	 * @var string
	 */
	public $slug = 'project_completed';

	/**
	 * @var string
	 */
	public $description = 'Fires when a project moves to a completed status. Projects without a client contact cannot enroll.';

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

		if ( ! AutomationModuleStorage::is_ready( 'projects', ProjectModel::class ) ) {
			return;
		}

		$status = ProjectStatusModel::find( (int) $new_status_id );
		if ( ! $status || ! $status->is_completed ) {
			return;
		}

		$key = (int) $project->id . ':' . (string) $old_status_id . ':' . (string) $new_status_id;
		if ( isset( self::$completed_seen[ $key ] ) ) {
			return;
		}
		self::$completed_seen[ $key ] = true;

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
		return isset( $args['project'] ) && $args['project'] instanceof ProjectModel;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array();
	}
}
