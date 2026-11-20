<?php
/**
 * Who may take a task.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Scope\OwnerScope;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Services\ContactCollaboratorService;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectTeam;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use WP_Error;
use DoubleScale\Pro\Modules\Companies\Models\CompanyModel;

/**
 * Whoever takes a task (or a subtask) must be able to open Tasks and the
 * record the task is about:
 *
 * - a deal: its owner, or sales-manager tier. A deal has one owner and no
 *   sharing list, so a rep who does not own it would get a task on a deal
 *   they cannot open;
 * - a project: anyone who can open projects. Agents join the team when the
 *   task is saved ({@see \DoubleScale\Pro\Modules\Projects\Services\ProjectTeamSync});
 * - a contact: its owner, someone it is shared with (the contact's
 *   "Owner & sharing" card), or sales-manager tier, as for a deal. Other
 *   managers see every contact too, but their work on a client comes
 *   through its projects or tickets. A contact nobody owns is the team's:
 *   anyone who opens Tasks.
 *
 * Interactive paths refuse with {@see error()}; background paths, which
 * cannot ask anyone, fall back to the record's owner with {@see allowed_or_owner()}.
 */
final class TaskAssigneeRule {

	/** Saving puts the assignee on the project team. */
	public const NOTE_JOINS_PROJECT_TEAM = 'joins_project_team';

	/**
	 * Why this user cannot take a task on this record, or null when they can.
	 *
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @param int $user_id     Wanted assignee.
	 * @return WP_Error|null
	 */
	public static function error( int $entity_type, int $entity_id, int $user_id ): ?WP_Error {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'Invalid assignee user ID.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// No name: the account may be a customer's, and their name is not the
		// team's business.
		if ( ! user_can( $user, 'doublescale_access' ) && ! user_can( $user, 'manage_options' ) ) {
			return new WP_Error(
				'doublescale_task_assignee_not_on_team',
				__( 'This person is not on your team. Tasks can only go to team members.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::opens_tasks( $user_id ) ) {
			return new WP_Error(
				'doublescale_task_assignee_cannot_open_tasks',
				sprintf(
					/* translators: %s: person's name */
					__( '%s cannot open tasks. Give them a Sales, Projects or CRM Agent role in Settings → Team, or pick someone else.', 'doublescale' ),
					$user->display_name
				),
				array( 'status' => 400 )
			);
		}

		if ( TaskEntityType::DEAL === $entity_type ) {
			return self::deal_error( $entity_id, $user );
		}

		if ( TaskEntityType::PROJECT === $entity_type && class_exists( ProjectTeam::class ) && ! ProjectTeam::is_eligible( $user_id ) ) {
			return ProjectTeam::no_project_access_error( $user, 'doublescale_task_assignee_no_project_access' );
		}

		if ( TaskEntityType::CONTACT === $entity_type ) {
			return self::contact_error( $entity_id, $user );
		}

		if ( TaskRecordEntity::TYPE === $entity_type ) {
			return TaskRecordEntity::assignee_error( $entity_id, $user );
		}

		return null;
	}

	/**
	 * A contact's task goes to its owner, someone it is shared with, or
	 * someone who manages every client; a contact nobody owns is the team's.
	 *
	 * @param int      $contact_id Contact id.
	 * @param \WP_User $user       Wanted assignee.
	 * @return WP_Error|null
	 */
	private static function contact_error( int $contact_id, $user ): ?WP_Error {
		$contact = ContactModel::find( $contact_id );
		$owner   = $contact ? (int) $contact->owner_id : 0;
		$user_id = (int) $user->ID;

		if ( $owner <= 0 || $owner === $user_id || Permissions::has_sales_manager_access( $user_id ) ) {
			return null;
		}

		if ( class_exists( ContactCollaboratorService::class ) && ContactCollaboratorService::is_collaborator( $contact_id, $user_id ) ) {
			return null;
		}

		return new WP_Error(
			'doublescale_task_assignee_contact_not_shared',
			sprintf(
				/* translators: 1: person picked for the task, 2: the contact's owner */
				__( '%1$s cannot take this task: the contact belongs to %2$s and is not shared with %1$s. Assign it to %2$s or a sales manager, or share the contact from its "Owner & sharing" card first.', 'doublescale' ),
				$user->display_name,
				self::name_of( $owner )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * The rule for a task being saved: only a NEW assignment is checked. A
	 * save that keeps the stored assignee and record (editing the title of an
	 * old task, say) passes, so old tasks never get stuck.
	 *
	 * @param array<string, mixed> $data   Attributes being saved (assigned_to, entity_type, entity_id).
	 * @param TaskModel|null       $stored The task as stored, or null for a new one.
	 * @return WP_Error|null
	 */
	public static function error_for_task( array $data, ?TaskModel $stored = null ): ?WP_Error {
		$assignee    = (int) ( $data['assigned_to'] ?? ( $stored ? $stored->assigned_to : 0 ) );
		$entity_type = (int) ( $data['entity_type'] ?? ( $stored ? $stored->entity_type : 0 ) );
		$entity_id   = (int) ( $data['entity_id'] ?? ( $stored ? $stored->entity_id : 0 ) );

		if ( $stored
			&& (int) $stored->assigned_to === $assignee
			&& (int) $stored->entity_type === $entity_type
			&& (int) $stored->entity_id === $entity_id ) {
			return null;
		}

		if ( $assignee <= 0 ) {
			return null;
		}

		return self::error( $entity_type, $entity_id, $assignee );
	}

	/**
	 * What saving will also do for an allowed assignee, for the task window:
	 * one of the NOTE_* constants, or '' when nothing else changes.
	 *
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @param int $user_id     Assignee.
	 * @return string
	 */
	public static function note( int $entity_type, int $entity_id, int $user_id ): string {
		if ( $user_id <= 0 || ! PermissionsCompat::is_agent_tier( $user_id ) ) {
			return '';
		}

		if ( TaskEntityType::PROJECT === $entity_type && doublescale_module_data_ready( 'projects' ) && class_exists( ProjectTeam::class ) ) {
			$project = ProjectModel::find( $entity_id );
			$on_team = $project && ( (int) $project->owner_id === $user_id || ProjectTeam::is_member( $entity_id, $user_id ) );
			return $project && ! $on_team ? self::NOTE_JOINS_PROJECT_TEAM : '';
		}

		return '';
	}

	/**
	 * The sentence for a note, naming the assignee.
	 *
	 * @param string $note    NOTE_* constant.
	 * @param int    $user_id Assignee.
	 * @return string
	 */
	public static function note_message( string $note, int $user_id ): string {
		$user = get_userdata( $user_id );
		$name = $user ? $user->display_name : '#' . $user_id;

		if ( self::NOTE_JOINS_PROJECT_TEAM === $note ) {
			/* translators: %s: person's name */
			return sprintf( __( '%s will join the project team.', 'doublescale' ), $name );
		}
		return '';
	}

	/**
	 * Whether someone can open the record right now. Not the same question as
	 * {@see error()}: assigning puts an agent on the project team, but when a
	 * record changes hands, only who still sees it counts.
	 *
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @param int $user_id     User id.
	 * @return bool
	 */
	public static function can_open( int $entity_type, int $entity_id, int $user_id ): bool {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return false;
		}

		if ( TaskEntityType::PROJECT === $entity_type ) {
			return doublescale_module_data_ready( 'projects' ) && ProjectModel::query()->readableBy( $user_id )->whereKey( $entity_id )->exists();
		}

		if ( TaskEntityType::CONTACT === $entity_type ) {
			$contact = ContactModel::find( $entity_id );
			return $contact && OwnerScope::for_user( $user_id )->can_view_contact( $contact );
		}

		return null === self::error( $entity_type, $entity_id, $user_id );
	}

	/**
	 * Who owns the record a task is about: the deal's, project's or contact's
	 * owner. 0 when nobody does (or the record is gone).
	 *
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @return int
	 */
	public static function record_owner( int $entity_type, int $entity_id ): int {
		if ( $entity_id <= 0 ) {
			return 0;
		}

		if ( TaskEntityType::DEAL === $entity_type ) {
			$deal = doublescale_module_data_ready( 'deals' ) ? DealModel::find( $entity_id ) : null;
			return $deal ? (int) $deal->owner_id : 0;
		}

		if ( TaskEntityType::PROJECT === $entity_type ) {
			$project = doublescale_module_data_ready( 'projects' ) ? ProjectModel::find( $entity_id ) : null;
			return $project ? (int) $project->owner_id : 0;
		}

		if ( TaskEntityType::CONTACT === $entity_type ) {
			$contact = ContactModel::find( $entity_id );
			return $contact ? (int) $contact->owner_id : 0;
		}

		if ( TaskEntityType::COMPANY === $entity_type && class_exists( CompanyModel::class ) ) {
			$company = CompanyModel::find( $entity_id );
			return $company ? (int) $company->owner_user_id : 0;
		}

		if ( TaskRecordEntity::TYPE === $entity_type ) {
			return TaskRecordEntity::owner( $entity_id );
		}

		return 0;
	}

	/**
	 * For paths that cannot ask anyone (automations, recurrence, copies, a
	 * record changing hands): the wanted person when they may take the task,
	 * else the record's owner when they may, else 0.
	 *
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @param int $user_id     Wanted assignee (0 = nobody chosen).
	 * @return int
	 */
	public static function allowed_or_owner( int $entity_type, int $entity_id, int $user_id ): int {
		if ( $user_id > 0 && null === self::error( $entity_type, $entity_id, $user_id ) ) {
			return $user_id;
		}

		$owner = self::record_owner( $entity_type, $entity_id );
		if ( $owner > 0 && null === self::error( $entity_type, $entity_id, $owner ) ) {
			return $owner;
		}

		return 0;
	}

	/**
	 * A person's name for messages.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function name_of( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : '#' . $user_id;
	}

	/**
	 * Roles that open Tasks: sales roles, the CRM Agent and Manager, project
	 * roles and administrators.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private static function opens_tasks( int $user_id ): bool {
		if ( method_exists( Permissions::class, 'can_access_tasks_api' ) ) {
			return Permissions::can_access_tasks_api( $user_id );
		}

		return PermissionsCompat::has_agent_access( $user_id ) || PermissionsCompat::has_project_access( $user_id );
	}

	/**
	 * A deal task goes to the deal's owner or to someone who manages every deal.
	 *
	 * @param int      $deal_id Deal id.
	 * @param \WP_User $user    Wanted assignee.
	 * @return WP_Error|null
	 */
	private static function deal_error( int $deal_id, $user ): ?WP_Error {
		$user_id = (int) $user->ID;
		if ( Permissions::has_sales_manager_access( $user_id ) || ! doublescale_module_data_ready( 'deals' ) ) {
			return null;
		}

		$deal = DealModel::find( $deal_id );
		if ( ! $deal ) {
			return null;
		}

		$owner = (int) $deal->owner_id;
		if ( $owner === $user_id ) {
			return null;
		}

		if ( $owner <= 0 ) {
			return new WP_Error(
				'doublescale_task_assignee_deal_has_no_owner',
				__( 'This deal has no owner yet. Give it an owner first, or assign the task to a sales manager.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( PermissionsCompat::has_agent_access( $user_id ) ) {
			return new WP_Error(
				'doublescale_task_assignee_not_deal_owner',
				sprintf(
					/* translators: 1: person picked for the task, 2: the deal's owner */
					__( '%1$s cannot take this task: the deal belongs to %2$s. Assign it to %2$s or a sales manager, or change the deal owner first.', 'doublescale' ),
					$user->display_name,
					self::name_of( $owner )
				),
				array( 'status' => 400 )
			);
		}

		return new WP_Error(
			'doublescale_task_assignee_no_deal_access',
			sprintf(
				/* translators: %s: person's name */
				__( '%s does not work on deals. Pick someone from the sales team, or give them a Sales role in Settings → Team.', 'doublescale' ),
				$user->display_name
			),
			array( 'status' => 400 )
		);
	}
}
