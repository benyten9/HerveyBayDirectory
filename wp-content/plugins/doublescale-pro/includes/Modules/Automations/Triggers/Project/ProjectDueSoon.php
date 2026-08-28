<?php
/**
 * Automation trigger: project due soon.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectDueSoon trigger.
 */
class ProjectDueSoon extends BaseProjectTrigger {

	/**
	 * @var string
	 */
	public $name = 'Project due soon';

	/**
	 * @var string
	 */
	public $slug = 'project_due_soon';

	/**
	 * @var string
	 */
	public $description = 'Fires when an incomplete project is due within a configured window. Projects without a client contact cannot enroll.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_automation_project_due_soon', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $project Project model.
	 * @param int   $hours   Window hours that matched.
	 */
	public function handle( $project, $hours = 24 ): void {
		if ( ! $project instanceof ProjectModel ) {
			return;
		}
		$this->enroll(
			$project,
			array(
				'due_soon_hours' => (int) $hours,
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

		$configured = (int) $automation->get_setting( 'hours', 24 );
		$actual     = (int) ( $args['data']['due_soon_hours'] ?? 0 );

		return $configured > 0 && $configured === $actual;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'hours' => array(
				'label'         => $this->t( 'Due within' ),
				'type'          => 'select',
				'options'       => array(
					24 => $this->t( '24 hours' ),
					48 => $this->t( '48 hours' ),
					72 => $this->t( '72 hours' ),
				),
				'default-value' => 24,
			),
		);
	}
}