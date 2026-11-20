<?php
/**
 * Project teams: the owner plus the staff who work on a project.
 *
 * @package DoubleScale\Pro\Modules\Projects\Services
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Projects\Models\ProjectMemberModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use WP_Error;

/**
 * The contact collaborators model, for projects: a project has ONE owner
 * (`projects.owner_id`) plus any number of members. A member sees the project,
 * its tasks and its time and works on it (comments, files, tasks, time), but
 * only the owner or a manager edits it, sees its money or changes the team.
 *
 * Only staff who can use projects may join. A membership outlives the reason
 * it was added (a task taken away keeps it) until the owner or a manager
 * removes it; removing someone never deletes their time or tasks, and their
 * open tasks go to the project's owner
 * ({@see \DoubleScale\Pro\Modules\Tasks\Services\TaskAssigneeHandover}).
 */
final class ProjectTeam {

	public const REASON_MANUAL = 'manual';

	/** Kept on the team after handing the project to someone else. */
	public const REASON_FORMER_OWNER = 'former_owner';

	/**
	 * Removal contexts that are bookkeeping, not a person's decision: no
	 * notification and no activity entry.
	 */
	public const INTERNAL_CONTEXTS = array( 'became_owner', 'purge', 'offboarding' );

	/** @var array<int, int[]> project id => member ids */
	private static $members_by_project = array();

	/** @var array<int, int[]> user id => project ids */
	private static $projects_by_user = array();

	/** @var bool|null */
	private static $table_ready = null;

	/**
	 * @return string Fully prefixed table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_project_members';
	}

	/**
	 * Whether the members table exists yet (reads are empty until it does).
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		if ( null === self::$table_ready ) {
			// Checked once per Pro version, not on every request.
			$version = defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '';
			if ( '' !== $version && get_option( 'doublescale_project_members_table_checked' ) === $version ) {
				self::$table_ready = true;
				return true;
			}
			global $wpdb;
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::$table_ready = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( self::$table_ready && '' !== $version ) {
				update_option( 'doublescale_project_members_table_checked', $version, false );
			}
		}
		return self::$table_ready;
	}

	/**
	 * Whether a user may be on a project team: staff who can use projects
	 * (administrators, CRM Manager/Agent, Project Manager/Member).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_eligible( int $user_id ): bool {
		return $user_id > 0 && false !== get_userdata( $user_id ) && PermissionsCompat::has_project_access( $user_id );
	}

	/**
	 * The role that lets a person work on projects, as the Team screen names it.
	 *
	 * @param int $user_id User id.
	 * @return string Empty when none.
	 */
	public static function role_label( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$labels = UserRoles::get_roles();
		$order  = array_filter( array( UserRoles::CRM_MANAGER, PermissionsCompat::crm_agent_role(), UserRoles::PROJECT_MANAGER, UserRoles::PROJECT_MEMBER ) );
		foreach ( $order as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) && isset( $labels[ $role ] ) ) {
				return (string) $labels[ $role ];
			}
		}

		return user_can( $user, 'manage_options' ) ? __( 'Administrator', 'doublescale' ) : '';
	}

	/**
	 * Why a user cannot join a project's team, or null when they can.
	 *
	 * @param ProjectModel $project Project.
	 * @param int          $user_id User id.
	 * @return WP_Error|null
	 */
	public static function candidate_error( ProjectModel $project, int $user_id ): ?WP_Error {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'doublescale_project_member_invalid_user', __( 'That user does not exist.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( (int) $project->owner_id === $user_id ) {
			return new WP_Error( 'doublescale_project_member_is_owner', __( 'This person owns the project, so they are on its team already.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( ! self::is_eligible( $user_id ) ) {
			return self::no_project_access_error( $user, 'doublescale_project_member_no_project_access' );
		}

		return null;
	}

	/**
	 * The error for someone who cannot open projects, telling how to fix it.
	 *
	 * @param \WP_User $user User.
	 * @param string   $code Error code.
	 * @return WP_Error
	 */
	public static function no_project_access_error( $user, string $code ): WP_Error {
		return new WP_Error(
			$code,
			sprintf(
				/* translators: %s: person's name */
				__( '%s cannot open projects. Give them a Projects role (Project Manager or Project Member) or the CRM Agent role in Settings → Team first.', 'doublescale' ),
				$user->display_name
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Put a user on a project's team (idempotent).
	 *
	 * @param int      $project_id Project id.
	 * @param int      $user_id    User id.
	 * @param string   $reason     Why: 'manual', 'task:<id>', 'automation:<id>'.
	 * @param int|null $added_by   Acting user (null = current user).
	 * @return bool True when a row was added.
	 */
	public static function add( int $project_id, int $user_id, string $reason = self::REASON_MANUAL, ?int $added_by = null ): bool {
		global $wpdb;

		if ( $project_id <= 0 || $user_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$project = ProjectModel::find( $project_id );
		if ( ! $project || (int) $project->owner_id === $user_id || ! self::is_eligible( $user_id ) ) {
			return false;
		}

		$table = self::table();
		// Not sanitize_key(): it drops the ':' of "task:12".
		$reason = substr( preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( $reason ) ) ?: self::REASON_MANUAL, 0, 64 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = (bool) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` (project_id, user_id, added_by, reason) VALUES (%d, %d, %d, %s)",
				$project_id,
				$user_id,
				(int) ( $added_by ?? get_current_user_id() ),
				$reason
			)
		);

		self::flush_cache();

		if ( $inserted ) {
			/**
			 * Fires when a user joins a project's team.
			 *
			 * @param int    $project_id Project id.
			 * @param int    $user_id    New member.
			 * @param string $reason     Why they were added.
			 * @param int    $added_by   Who added them (0 = the system).
			 */
			do_action( 'doublescale_project_member_added', $project_id, $user_id, $reason, (int) ( $added_by ?? get_current_user_id() ) );
		}

		return $inserted;
	}

	/**
	 * Take a user off a project's team.
	 *
	 * @param int    $project_id Project id.
	 * @param int    $user_id    User id.
	 * @param string $context    'manual', or one of {@see INTERNAL_CONTEXTS}.
	 * @return bool True when a row was removed.
	 */
	public static function remove( int $project_id, int $user_id, string $context = self::REASON_MANUAL ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = (bool) $wpdb->delete(
			self::table(),
			array(
				'project_id' => $project_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);

		self::flush_cache();

		if ( $removed ) {
			/**
			 * Fires when a user leaves a project's team.
			 *
			 * @param int    $project_id Project id.
			 * @param int    $user_id    Former member.
			 * @param string $context    'manual', or bookkeeping ({@see ProjectTeam::INTERNAL_CONTEXTS}).
			 */
			do_action( 'doublescale_project_member_removed', $project_id, $user_id, $context );
		}

		return $removed;
	}

	/**
	 * Make the team exactly these users (the owner is skipped). Validate first
	 * with {@see candidate_error()}: ineligible ids are skipped silently here.
	 *
	 * @param ProjectModel $project  Project.
	 * @param int[]        $user_ids Wanted members.
	 * @param int|null     $added_by Acting user.
	 * @return array{added:int[], removed:int[]}
	 */
	public static function sync( ProjectModel $project, array $user_ids, ?int $added_by = null ): array {
		$wanted  = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		$current = self::member_ids( (int) $project->id );
		$result  = array(
			'added'   => array(),
			'removed' => array(),
		);

		foreach ( array_diff( $current, $wanted ) as $user_id ) {
			if ( self::remove( (int) $project->id, (int) $user_id ) ) {
				$result['removed'][] = (int) $user_id;
			}
		}
		foreach ( array_diff( $wanted, $current ) as $user_id ) {
			if ( self::add( (int) $project->id, (int) $user_id, self::REASON_MANUAL, $added_by ) ) {
				$result['added'][] = (int) $user_id;
			}
		}

		return $result;
	}

	/**
	 * Member ids of a project (the owner is not one).
	 *
	 * @param int $project_id Project id.
	 * @return int[]
	 */
	public static function member_ids( int $project_id ): array {
		if ( $project_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		if ( ! isset( self::$members_by_project[ $project_id ] ) ) {
			self::$members_by_project += self::members_for_projects( array( $project_id ) );
		}

		return self::$members_by_project[ $project_id ] ?? array();
	}

	/**
	 * Member ids for many projects in one query (lists and boards).
	 *
	 * @param int[] $project_ids Project ids.
	 * @return array<int, int[]> project id => member ids (every id present).
	 */
	public static function members_for_projects( array $project_ids ): array {
		global $wpdb;

		$project_ids = array_values( array_unique( array_filter( array_map( 'intval', $project_ids ) ) ) );
		$out         = array_fill_keys( $project_ids, array() );
		if ( empty( $project_ids ) || ! self::table_exists() ) {
			return $out;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT project_id, user_id FROM `{$table}` WHERE project_id IN ({$placeholders}) ORDER BY id ASC", $project_ids ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['project_id'] ][] = (int) $row['user_id'];
		}

		foreach ( $out as $project_id => $ids ) {
			self::$members_by_project[ $project_id ] = $ids;
		}

		return $out;
	}

	/**
	 * Members of a project, with why and when they were added.
	 *
	 * @param int $project_id Project id.
	 * @return array<int, array{user_id:int, reason:string, added_by:int, created_at:string}>
	 */
	public static function members( int $project_id ): array {
		global $wpdb;

		if ( $project_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, reason, added_by, created_at FROM `{$table}` WHERE project_id = %d ORDER BY id ASC", $project_id ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				return array(
					'user_id'    => (int) $row['user_id'],
					'reason'     => (string) $row['reason'],
					'added_by'   => (int) $row['added_by'],
					'created_at' => (string) $row['created_at'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Ids of every project a user is a member of (not the ones they own).
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function project_ids_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		if ( ! isset( self::$projects_by_user[ $user_id ] ) ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT project_id FROM `{$table}` WHERE user_id = %d", $user_id ) );

			self::$projects_by_user[ $user_id ] = array_map( 'intval', (array) $ids );
		}

		return self::$projects_by_user[ $user_id ];
	}

	/**
	 * Whether a user is a member of a project (owning it is not membership).
	 *
	 * @param int $project_id Project id.
	 * @param int $user_id    User id.
	 * @return bool
	 */
	public static function is_member( int $project_id, int $user_id ): bool {
		return in_array( $project_id, self::project_ids_for_user( $user_id ), true );
	}

	/**
	 * Everyone on the team: the owner, then the members.
	 *
	 * @param ProjectModel $project Project.
	 * @return int[]
	 */
	public static function team_user_ids( ProjectModel $project ): array {
		$ids = array_merge( array( (int) $project->owner_id ), self::member_ids( (int) $project->id ) );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * A subquery selecting the project ids a user is a member of, for
	 * `whereIn( 'id', … )`.
	 *
	 * @param int $user_id User id.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public static function subquery_for_user( int $user_id ) {
		return ProjectMemberModel::query()->where( 'user_id', $user_id )->select( 'project_id' );
	}

	/**
	 * Drop every membership of a project (project deleted).
	 *
	 * @param int $project_id Project id.
	 * @return void
	 */
	public static function purge_project( int $project_id ): void {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'project_id' => $project_id ), array( '%d' ) );
		self::flush_cache();
	}

	/**
	 * Drop every membership of a user (user deleted).
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function purge_user( int $user_id ): void {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
		self::flush_cache();
	}

	/**
	 * Offboarding: move a leaving user's memberships to someone else, or drop
	 * them when there is nobody (or the target cannot open projects).
	 *
	 * Runs after free moved the projects themselves with a raw UPDATE (no
	 * events), so it also drops rows of people who now own the project.
	 *
	 * @param int      $from_user_id Leaving user.
	 * @param int|null $to_user_id   Their replacement, or null.
	 * @return int Rows moved.
	 */
	public static function reassign_all( int $from_user_id, ?int $to_user_id ): int {
		global $wpdb;

		if ( $from_user_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$table    = self::table();
		$projects = $wpdb->prefix . 'doublescale_projects';
		$moved    = 0;

		if ( null === $to_user_id || $to_user_id <= 0 || ! self::is_eligible( $to_user_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'user_id' => $from_user_id ), array( '%d' ) );
		} else {
			// Drop the rows the target already holds, then move the rest.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE a FROM `{$table}` a INNER JOIN `{$table}` b ON a.project_id = b.project_id AND b.user_id = %d WHERE a.user_id = %d",
					$to_user_id,
					$from_user_id
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$moved = (int) $wpdb->update( $table, array( 'user_id' => $to_user_id ), array( 'user_id' => $from_user_id ), array( '%d' ), array( '%d' ) );
		}

		// The owner is never a member, whoever owns the project now.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE m FROM `{$table}` m INNER JOIN `{$projects}` p ON p.id = m.project_id AND p.owner_id = m.user_id" );

		self::flush_cache();

		return $moved;
	}

	/**
	 * Forget the per-request memo (after every write).
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$members_by_project = array();
		self::$projects_by_user   = array();
	}

	/**
	 * The members table was just created.
	 *
	 * @return void
	 */
	public static function mark_table_ready(): void {
		self::$table_ready = null;
	}
}
