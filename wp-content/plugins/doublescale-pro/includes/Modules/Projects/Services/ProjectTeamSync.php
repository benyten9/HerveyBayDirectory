<?php
/**
 * Keeps project teams in step with the rest of the CRM.
 *
 * @package DoubleScale\Pro\Modules\Projects\Services
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Modules\Contacts\Services\ContactCollaboratorService;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * Listens on the models, so every path (REST, MCP, automations) is covered:
 *
 * - a project task assigned to an agent puts them on the team (`task:<id>`);
 *   managers see every project already and are only added by hand;
 * - a member who becomes the owner leaves the member list, and the owner
 *   handing over stays on as a member;
 * - a leaving user's memberships go to their replacement (offboarding);
 * - deleted users lose their memberships;
 * - agents on a team, and the project's owner, work the project's contact
 *   (contact collaborators).
 */
final class ProjectTeamSync {

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_task_created', array( $this, 'on_task_saved' ), 20, 1 );
		add_action( 'doublescale_task_updated', array( $this, 'on_task_updated' ), 20, 2 );
		add_action( 'doublescale_subtask_created', array( $this, 'on_subtask_saved' ), 20, 1 );
		add_action( 'doublescale_subtask_updated', array( $this, 'on_subtask_updated' ), 20, 2 );
		add_action( 'doublescale_project_created', array( $this, 'on_project_created' ), 10, 1 );
		add_action( 'doublescale_project_owner_changed', array( $this, 'on_owner_changed' ), 10, 3 );
		add_action( 'doublescale_project_updated', array( $this, 'on_project_updated' ), 10, 2 );
		add_action( 'doublescale_project_member_added', array( $this, 'on_member_added' ), 10, 2 );
		add_action( 'doublescale_records_reassigned', array( $this, 'on_records_reassigned' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ), 10, 1 );
		add_action( 'wpmu_delete_user', array( $this, 'on_user_deleted' ), 10, 1 );
	}

	/**
	 * @param object $task Task model.
	 * @return void
	 */
	public function on_task_saved( $task ): void {
		if ( ! is_object( $task ) || TaskEntityType::PROJECT !== (int) ( $task->entity_type ?? 0 ) ) {
			return;
		}

		$assignee   = (int) ( $task->assigned_to ?? 0 );
		$project_id = (int) ( $task->entity_id ?? 0 );
		if ( $assignee <= 0 || $project_id <= 0 || ! PermissionsCompat::is_agent_tier( $assignee ) ) {
			return;
		}
		if ( ProjectTeam::is_member( $project_id, $assignee ) ) {
			return;
		}

		ProjectTeam::add( $project_id, $assignee, 'task:' . (int) $task->id );
	}

	/**
	 * @param object               $task    Task model.
	 * @param array<string, mixed> $changes Changed attributes.
	 * @return void
	 */
	public function on_task_updated( $task, $changes = array() ): void {
		$changes = is_array( $changes ) ? $changes : array();
		if ( isset( $changes['assigned_to'] ) || isset( $changes['entity_id'] ) || isset( $changes['entity_type'] ) ) {
			$this->on_task_saved( $task );
		}
	}

	/**
	 * A project subtask given to an agent puts them on the team too: they see
	 * its task and log time on it already.
	 *
	 * @param object $subtask Subtask model.
	 * @return void
	 */
	public function on_subtask_saved( $subtask ): void {
		$assignee = is_object( $subtask ) ? (int) ( $subtask->assigned_to ?? 0 ) : 0;
		if ( $assignee <= 0 || ! doublescale_module_data_ready( 'tasks' ) ) {
			return;
		}
		$task = TaskModel::find( (int) $subtask->task_id );
		if ( ! $task || TaskEntityType::PROJECT !== (int) $task->entity_type || ! PermissionsCompat::is_agent_tier( $assignee ) ) {
			return;
		}
		if ( ! ProjectTeam::is_member( (int) $task->entity_id, $assignee ) ) {
			ProjectTeam::add( (int) $task->entity_id, $assignee, 'task:' . (int) $task->id );
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
	 * The new owner is on the team as its owner; their member row goes. The
	 * previous owner stays on the team as a member, so a handover does not cut
	 * them off (they lose the money and the settings). Offboarding moves
	 * projects without this event and drops the leaver instead.
	 *
	 * @param ProjectModel $project   Project.
	 * @param int|null     $old_owner Previous owner.
	 * @param int|null     $new_owner New owner.
	 * @return void
	 */
	public function on_owner_changed( $project, $old_owner, $new_owner ): void {
		if ( ! $project instanceof ProjectModel ) {
			return;
		}
		if ( (int) $new_owner > 0 ) {
			ProjectTeam::remove( (int) $project->id, (int) $new_owner, 'became_owner' );
			$this->share_contact( $project, (int) $new_owner );
		}
		if ( (int) $old_owner > 0 && (int) $old_owner !== (int) $new_owner ) {
			ProjectTeam::add( (int) $project->id, (int) $old_owner, ProjectTeam::REASON_FORMER_OWNER );
		}
	}

	/**
	 * The owner of a new project works its client, like its team does.
	 *
	 * @param ProjectModel $project Project.
	 * @return void
	 */
	public function on_project_created( $project ): void {
		if ( $project instanceof ProjectModel && (int) $project->owner_id > 0 ) {
			$this->share_contact( $project, (int) $project->owner_id );
		}
	}

	/**
	 * A project moved to another contact: its owner and agents work the new one.
	 *
	 * @param ProjectModel         $project Project.
	 * @param array<string, mixed> $changes Changed attributes.
	 * @return void
	 */
	public function on_project_updated( $project, $changes ): void {
		if ( ! $project instanceof ProjectModel || ! is_array( $changes ) || ! array_key_exists( 'contact_id', $changes ) ) {
			return;
		}
		foreach ( ProjectTeam::team_user_ids( $project ) as $user_id ) {
			$this->share_contact( $project, $user_id );
		}
	}

	/**
	 * @param int $project_id Project id.
	 * @param int $user_id    New member.
	 * @return void
	 */
	public function on_member_added( $project_id, $user_id ): void {
		$project = ProjectModel::find( (int) $project_id );
		if ( $project ) {
			$this->share_contact( $project, (int) $user_id );
		}
	}

	/**
	 * Offboarding (free's OwnershipReassigner) moved a user's records.
	 *
	 * @param int      $from_user_id Leaving user.
	 * @param int|null $to_user_id   Replacement, or null for the unassigned pool.
	 * @return void
	 */
	public function on_records_reassigned( $from_user_id, $to_user_id ): void {
		ProjectTeam::reassign_all( (int) $from_user_id, null === $to_user_id ? null : (int) $to_user_id );
	}

	/**
	 * @param int $user_id Deleted user.
	 * @return void
	 */
	public function on_user_deleted( $user_id ): void {
		ProjectTeam::purge_user( (int) $user_id );
	}

	/**
	 * An agent on the team works the project's contact. The collaborator
	 * service skips managers and the contact's owner itself.
	 *
	 * @param ProjectModel $project Project.
	 * @param int          $user_id Member.
	 * @return void
	 */
	private function share_contact( ProjectModel $project, int $user_id ): void {
		$contact_id = (int) $project->contact_id;
		if ( $contact_id <= 0 || ! class_exists( ContactCollaboratorService::class ) || ! method_exists( ContactCollaboratorService::class, 'sync_from_assignment' ) ) {
			return;
		}
		ContactCollaboratorService::sync_from_assignment( 'project', (int) $project->id, $contact_id, $user_id );
	}
}
