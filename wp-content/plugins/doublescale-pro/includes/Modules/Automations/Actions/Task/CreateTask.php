<?php
/**
 * Automation action: create a task.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Task;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskPriority;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Core\Constants\TaskType;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Pro\Modules\Automations\Triggers\Task\BaseTaskTrigger;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;
use DoubleScale\Pro\Modules\Tasks\Services\TaskStatusManager;

defined( 'ABSPATH' ) || exit;

/**
 * CreateTask action.
 */
class CreateTask extends BaseTaskAction {

	/**
	 * @var string
	 */
	public $name = 'Create a task';

	/**
	 * @var string
	 */
	public $slug = 'create_task';

	/**
	 * @var string
	 */
	public $description = 'This action will create a new task.';

	/**
	 * {@inheritdoc}
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		if ( ! $this->tasks_storage_ready() ) {
			return false;
		}

		$title = $this->parse_text( $step->get_setting( 'title' ), $automation_contact );
		if ( '' === trim( (string) $title ) ) {
			$title = $this->t( 'New task' );
		}

		$binding = $step->get_setting( 'entity_binding' ) ?: 'contact';
		$entity  = $this->resolve_entity_binding( $binding, $automation_contact );
		if ( ! $entity ) {
			return false;
		}

		$due_offset = max( 0, (int) $step->get_setting( 'due_offset_days', 0 ) );
		$due_date   = date( 'Y-m-d', strtotime( '+' . $due_offset . ' days', current_time( 'timestamp' ) ) );
		$due_time   = $step->get_setting( 'due_time' );
		if ( $due_time && preg_match( '/^\d{2}:\d{2}$/', (string) $due_time ) ) {
			$due_time .= ':00';
		} elseif ( $due_time && ! preg_match( '/^\d{2}:\d{2}:\d{2}$/', (string) $due_time ) ) {
			$due_time = null;
		}

		$assignee = (int) $step->get_setting( 'assignee' );
		if ( $assignee <= 0 ) {
			$assignee = get_current_user_id() ?: 1;
		}

		$data = array(
			'title'       => $title,
			'description' => $this->parse_text( $step->get_setting( 'description' ), $automation_contact ),
			'entity_type' => $entity['entity_type'],
			'entity_id'   => $entity['entity_id'],
			'assigned_to' => $assignee,
			'task_type'   => $step->get_setting( 'task_type' ) ?: TaskType::TODO,
			'status'      => TaskStatus::PENDING,
			'priority'    => $step->get_setting( 'priority' ) ?: TaskPriority::MEDIUM,
			'due_date'    => $due_date,
			'due_time'    => $due_time ?: null,
		);

		BaseTaskTrigger::suppress_enrollment( true );
		try {
			$task = TaskModel::create( $data );
			if ( ! $task ) {
				return false;
			}

			$status_id = (int) $step->get_setting( 'status_id' );
			if ( $status_id > 0 ) {
				TaskStatusManager::instance()->apply_status_to_task( $task, $status_id );
				$task->save();
			} else {
				$default_stage = TaskStatusModel::where( 'status', 'open' )
					->orderBy( 'sort_order', 'asc' )
					->orderBy( 'id', 'asc' )
					->first();
				if ( $default_stage ) {
					TaskStatusManager::instance()->apply_status_to_task( $task, (int) $default_stage->id );
					$task->save();
				}
			}

			$label_ids = $step->get_setting( 'label_ids' );
			if ( is_array( $label_ids ) && ! empty( $label_ids ) ) {
				$task->labels()->sync( array_map( 'intval', $label_ids ) );
			}

			// Keep enrollment data in sync so later steps / merge tags see this task.
			$automation_contact->set_data( array( 'task_id' => (int) $task->id ) );
		} finally {
			BaseTaskTrigger::suppress_enrollment( false );
		}

		return true;
	}

	/**
	 * Resolve entity binding from automation contact data.
	 *
	 * @param string                 $binding            contact|deal|project.
	 * @param AutomationContactModel $automation_contact Contact.
	 * @return array{entity_type:int,entity_id:int}|null
	 */
	private function resolve_entity_binding( string $binding, AutomationContactModel $automation_contact ): ?array {
		$data = is_array( $automation_contact->data ) ? $automation_contact->data : array();

		if ( 'deal' === $binding ) {
			$deal_id = isset( $data['deal_id'] ) ? (int) $data['deal_id'] : 0;
			if ( $deal_id <= 0 ) {
				return null;
			}
			return array(
				'entity_type' => TaskEntityType::DEAL,
				'entity_id'   => $deal_id,
			);
		}

		if ( 'project' === $binding ) {
			$project_id = isset( $data['project_id'] ) ? (int) $data['project_id'] : 0;
			if ( $project_id <= 0 ) {
				return null;
			}
			return array(
				'entity_type' => TaskEntityType::PROJECT,
				'entity_id'   => $project_id,
			);
		}

		$contact = $automation_contact->contact ?? null;
		if ( ! $contact || empty( $contact->id ) ) {
			return null;
		}

		return array(
			'entity_type' => TaskEntityType::CONTACT,
			'entity_id'   => (int) $contact->id,
		);
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
			'task_type'       => array(
				'label'   => $this->t( 'Task type' ),
				'type'    => 'select',
				'options' => $this->get_task_type_options(),
			),
			'priority'        => array(
				'label'   => $this->t( 'Priority' ),
				'type'    => 'select',
				'options' => $this->get_priority_options(),
			),
			'assignee'        => $this->get_assignee_field(),
			'due_offset_days' => array(
				'label'   => $this->t( 'Due in (days)' ),
				'type'    => 'number',
				'tooltip' => $this->t( 'Number of days from when this action runs.' ),
			),
			'due_time'        => array(
				'label'   => $this->t( 'Due time' ),
				'type'    => 'text',
				'tooltip' => $this->t( 'Optional time in HH:MM or HH:MM:SS format.' ),
			),
			'status_id'       => array(
				'label'   => $this->t( 'Kanban status' ),
				'type'    => 'select',
				'options' => $this->get_kanban_status_options(),
			),
			'label_ids'       => array(
				'label'    => $this->t( 'Labels' ),
				'type'     => 'multiselect',
				'options'  => $this->get_label_options(),
				'multiple' => true,
			),
			'entity_binding'  => array(
				'label'         => $this->t( 'Link task to' ),
				'type'          => 'select',
				'options'       => array(
					'contact' => $this->t( 'Contact in this automation' ),
					'deal'    => $this->t( 'Linked deal' ),
					'project' => $this->t( 'Linked project' ),
				),
				'default-value' => 'contact',
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
				'task_type'       => array(
					'type' => 'string',
				),
				'priority'        => array(
					'type' => 'string',
				),
				'assignee'        => array(
					'type' => 'integer',
				),
				'due_offset_days' => array(
					'type' => 'integer',
				),
				'due_time'        => array(
					'type' => 'string',
				),
				'status_id'       => array(
					'type' => 'integer',
				),
				'label_ids'       => array(
					'type' => 'array',
				),
				'entity_binding'  => array(
					'type' => 'string',
				),
			),
		);
	}
}
