<?php
/**
 * Automation trigger: project created.
 *
 * Note: fires in the model `created` event before `sync_custom_fields()` runs
 * in RestProjectController — custom-field conditions on this trigger see empty values.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectCreated trigger.
 */
class ProjectCreated extends BaseProjectTrigger {

	/**
	 * @var string
	 */
	public $name = 'Project created';

	/**
	 * @var string
	 */
	public $slug = 'project_created';

	/**
	 * @var string
	 */
	public $description = 'Fires when a new project is created. Projects without a client contact cannot enroll.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_project_created', array( $this, 'handle' ), 10, 1 );
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