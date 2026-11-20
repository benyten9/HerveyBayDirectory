<?php
/**
 * Open work follows its record when someone loses access to it.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Modules\Projects\Services\ProjectTeam;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * When a deal changes owner, someone leaves a project team, or a contact stops
 * being shared with someone (or gets an owner its old one cannot see past),
 * the open tasks and subtasks of whoever can no longer open the record go to
 * the record's owner. Finished work stays with whoever did it, as history.
 *
 * Every reassignment is a normal save, so the new person is notified and the
 * task's activity shows the change.
 */
final class TaskAssigneeHandover {

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_deal_updated', array( $this, 'on_deal_updated' ), 20, 2 );
		add_action( 'doublescale_project_member_removed', array( $this, 'on_project_member_removed' ), 20, 3 );
		add_action( 'doublescale_contact_collaborator_removed', array( $this, 'on_contact_share_removed' ), 20, 2 );
		add_action( 'doublescale_contact_owner_changed', array( $this, 'on_contact_owner_changed' ), 20, 3 );
	}

	/**
	 * @param object               $deal    Deal model.
	 * @param array<string, mixed> $changes Changed attributes.
	 * @return void
	 */
	public function on_deal_updated( $deal, $changes = array() ): void {
		if ( ! is_object( $deal ) || ! is_array( $changes ) || ! array_key_exists( 'owner_id', $changes ) ) {
			return;
		}

		$this->hand_over( TaskEntityType::DEAL, (int) $deal->id, (int) $deal->owner_id );
	}

	/**
	 * @param int    $project_id Project id.
	 * @param int    $user_id    Former member.
	 * @param string $context    Why they left.
	 * @return void
	 */
	public function on_project_member_removed( $project_id, $user_id, $context = 'manual' ): void {
		// Becoming the owner is no loss; offboarding and deleted users move
		// their work through the reassigner.
		if ( class_exists( ProjectTeam::class ) && in_array( (string) $context, ProjectTeam::INTERNAL_CONTEXTS, true ) ) {
			return;
		}

		$owner = TaskAssigneeRule::record_owner( TaskEntityType::PROJECT, (int) $project_id );
		$this->hand_over( TaskEntityType::PROJECT, (int) $project_id, $owner, (int) $user_id );
	}

	/**
	 * @param int $contact_id Contact id.
	 * @param int $user_id    Former collaborator.
	 * @return void
	 */
	public function on_contact_share_removed( $contact_id, $user_id ): void {
		$owner = TaskAssigneeRule::record_owner( TaskEntityType::CONTACT, (int) $contact_id );
		$this->hand_over( TaskEntityType::CONTACT, (int) $contact_id, $owner, (int) $user_id );
	}

	/**
	 * Only the old owner can lose the contact here; back in the shared pool,
	 * every agent still sees it.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $old_owner  Previous owner (0 = unassigned).
	 * @param int $new_owner  New owner (0 = unassigned).
	 * @return void
	 */
	public function on_contact_owner_changed( $contact_id, $old_owner, $new_owner ): void {
		if ( (int) $old_owner <= 0 || (int) $old_owner === (int) $new_owner ) {
			return;
		}

		$this->hand_over( TaskEntityType::CONTACT, (int) $contact_id, (int) $new_owner, (int) $old_owner );
	}

	/**
	 * Give the open tasks and subtasks on a record whose person can no longer
	 * open it to $to.
	 *
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @param int $to          Who takes them (the record's owner).
	 * @param int $only_user   Only this person's work (0 = anyone who lost it).
	 * @return void
	 */
	private function hand_over( int $entity_type, int $entity_id, int $to, int $only_user = 0 ): void {
		if ( $entity_id <= 0 || $to <= 0 ) {
			return;
		}

		// One query for the common case: a record with no tasks (bulk
		// reassigning thousands of contacts fires this once per contact).
		$tasks = TaskModel::where( 'entity_type', $entity_type )->where( 'entity_id', $entity_id )->get();
		if ( $tasks->isEmpty() ) {
			return;
		}

		// The owner must be able to take the work, not only see the record
		// (a contact can be owned by someone who never opens Tasks).
		if ( ! TaskAssigneeRule::can_open( $entity_type, $entity_id, $to ) || null !== TaskAssigneeRule::error( $entity_type, $entity_id, $to ) ) {
			return;
		}

		foreach ( $tasks as $task ) {
			if ( TaskStatus::COMPLETED !== (string) $task->status && $this->lost_it( (int) $task->assigned_to, $only_user, $entity_type, $entity_id, $to ) ) {
				$task->assigned_to = $to;
				$task->save();
			}

			foreach ( SubtaskModel::where( 'task_id', (int) $task->id )->where( 'is_completed', 0 )->get() as $subtask ) {
				if ( $this->lost_it( (int) $subtask->assigned_to, $only_user, $entity_type, $entity_id, $to ) ) {
					$subtask->assigned_to = $to;
					$subtask->save();
				}
			}
		}
	}

	/**
	 * @param int $assignee    Current person.
	 * @param int $only_user   Only this person (0 = anyone).
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @param int $to          The new person.
	 * @return bool
	 */
	private function lost_it( int $assignee, int $only_user, int $entity_type, int $entity_id, int $to ): bool {
		if ( $assignee <= 0 || $assignee === $to || ( $only_user > 0 && $assignee !== $only_user ) ) {
			return false;
		}

		return ! TaskAssigneeRule::can_open( $entity_type, $entity_id, $assignee );
	}
}
