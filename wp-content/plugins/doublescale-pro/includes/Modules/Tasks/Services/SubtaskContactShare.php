<?php
/**
 * Whoever works a subtask works its task's contact.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Modules\Contacts\Services\ContactCollaboratorService;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * A subtask given to an agent shares its task's contact with them (a contact
 * task's contact, or a deal task's deal contact), like the task's own
 * assignee gets it from free's ContactCollaboratorSync. Project subtasks put
 * the agent on the team instead (ProjectTeamSync), which shares the client.
 *
 * Lives in Pro, on free's long-standing collaborator API, so a newer Pro
 * works the same on an older free.
 */
final class SubtaskContactShare {

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_subtask_created', array( $this, 'on_subtask_saved' ), 20, 1 );
		add_action( 'doublescale_subtask_updated', array( $this, 'on_subtask_updated' ), 20, 2 );
	}

	/**
	 * @param object $subtask Subtask model (task_id, assigned_to).
	 * @return void
	 */
	public function on_subtask_saved( $subtask ): void {
		$assignee = is_object( $subtask ) ? (int) ( $subtask->assigned_to ?? 0 ) : 0;
		if ( $assignee <= 0 || ! method_exists( ContactCollaboratorService::class, 'sync_from_assignment' ) ) {
			return;
		}

		$task = TaskModel::find( (int) $subtask->task_id );
		if ( ! $task ) {
			return;
		}

		$contact_id = self::contact_of( $task );
		if ( $contact_id > 0 ) {
			ContactCollaboratorService::sync_from_assignment( 'task', (int) $task->id, $contact_id, $assignee );
		}
	}

	/**
	 * @param object               $subtask Subtask model.
	 * @param array<string, mixed> $changes Changed attributes.
	 * @return void
	 */
	public function on_subtask_updated( $subtask, $changes = array() ): void {
		if ( is_array( $changes ) && array_key_exists( 'assigned_to', $changes ) ) {
			$this->on_subtask_saved( $subtask );
		}
	}

	/**
	 * The contact a task is about: a contact task's, or its deal's.
	 *
	 * @param TaskModel $task Task.
	 * @return int
	 */
	private static function contact_of( TaskModel $task ): int {
		if ( TaskEntityType::CONTACT === (int) $task->entity_type ) {
			return (int) $task->entity_id;
		}

		if ( TaskEntityType::DEAL === (int) $task->entity_type && doublescale_module_data_ready( 'deals' ) ) {
			$deal = DealModel::find( (int) $task->entity_id );
			return $deal ? (int) $deal->contact_id : 0;
		}

		return 0;
	}
}
