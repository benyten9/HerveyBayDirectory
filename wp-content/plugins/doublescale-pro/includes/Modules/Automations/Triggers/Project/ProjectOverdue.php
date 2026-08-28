<?php
/**
 * Automation trigger: project overdue.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectOverdue trigger.
 */
class ProjectOverdue extends BaseProjectTrigger {

	/**
	 * @var string
	 */
	public $name = 'Project overdue';

	/**
	 * @var string
	 */
	public $slug = 'project_overdue';

	/**
	 * @var string
	 */
	public $description = 'Fires when an incomplete project becomes overdue. Projects without a client contact cannot enroll.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_automation_project_overdue', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * @param mixed $project Project model.
	 */
	public function handle( $project ): void {
		if ( ! $project instanceof ProjectModel ) {
			return;
		}
		$this->enroll( $project );
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