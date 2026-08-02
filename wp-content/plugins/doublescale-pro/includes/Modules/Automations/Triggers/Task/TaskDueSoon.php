<?php
/**
 * Automation trigger: task due soon.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskDueSoon trigger.
 */
class TaskDueSoon extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Task due soon';

	/**
	 * @var string
	 */
	public $slug = 'task_due_soon';

	/**
	 * @var string
	 */
	public $description = 'Fires when a pending task is due within a configured window.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_automation_task_due_soon', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $task  Task model.
	 * @param int   $hours Window hours that matched.
	 */
	public function handle( $task, $hours = 24 ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}
		$this->enroll(
			$task,
			array(
				'due_soon_hours' => (int) $hours,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$task = $args['task'] ?? null;
		if ( ! $task instanceof TaskModel ) {
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
					1  => $this->t( '1 hour' ),
					4  => $this->t( '4 hours' ),
					24 => $this->t( '24 hours' ),
					48 => $this->t( '48 hours' ),
					72 => $this->t( '72 hours' ),
				),
				'default-value' => 24,
			),
		);
	}
}
