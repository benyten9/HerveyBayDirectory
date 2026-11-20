<?php
/**
 * Record-level ownership scoping for every module.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Scope;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Services\ContactCollaboratorService;
use WP_Error;

/**
 * One implementation of "you only see your own records", for a given user.
 *
 * {@see \DoubleScale\Core\Abilities\AbilityScope} enforces the same idea for
 * the MCP abilities but is bound to the current user and leaves the "may this
 * user see everything?" question to each caller. This class answers that
 * question too, through {@see Permissions::sees_all_records()}, and can be
 * built for ANY user: campaigns and automations run in the background, where
 * `get_current_user_id()` is nobody, and must scope by their creator.
 *
 * Contacts have two levels. A contact is VISIBLE to its owner, to its
 * collaborators (someone with a deal, task or ticket on it) and — when it
 * has no owner — to every agent, who may claim it; it is OWNED only by its
 * owner. Lists, search and single reads use visibility; export,
 * campaign audiences, reassignment and merge use ownership, so a collaborator
 * can work a contact without being able to take it with them.
 */
final class OwnerScope {

	/**
	 * The user this scope is evaluated for.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Memoised answers per module.
	 *
	 * @var array<string, bool>
	 */
	private $sees_all = array();

	/**
	 * Collaborated contact ids, loaded once.
	 *
	 * @var int[]|null
	 */
	private $collaborating = null;

	/**
	 * System context: no user at all (cron, queue workers, WP-CLI, inbound
	 * webhooks). Unrestricted, exactly as every module behaved before scoping
	 * existed — REST permission callbacks already keep anonymous visitors out.
	 *
	 * @var bool
	 */
	private $system = false;

	/**
	 * @param int  $user_id User the scope belongs to.
	 * @param bool $system  Whether this is the no-user system context.
	 */
	private function __construct( int $user_id, bool $system = false ) {
		$this->user_id = $user_id;
		$this->system  = $system;
	}

	/**
	 * A scope for the current request's user.
	 *
	 * With nobody logged in this is the SYSTEM scope (sees everything):
	 * campaign sends, segment counts and automation steps run without a user
	 * and must keep reaching every contact. A job that acts for a person
	 * scopes with for_user( $creator ) instead.
	 *
	 * @return self
	 */
	public static function current(): self {
		$user_id = (int) get_current_user_id();

		return new self( $user_id, $user_id <= 0 );
	}

	/**
	 * A scope for a given user — the right choice in background jobs, where
	 * the record's creator, not the (absent) current user, decides.
	 *
	 * Unlike current(), user 0 here scopes to NOTHING: a job asking "what may
	 * this record's creator see?" about a deleted creator must not widen.
	 *
	 * @param int $user_id User id. 0 scopes to nothing.
	 * @return self
	 */
	public static function for_user( int $user_id ): self {
		return new self( $user_id );
	}

	/**
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Whether this user sees every record of a module.
	 *
	 * @param string $module Module slug (see Permissions::sees_all_records()).
	 * @return bool
	 */
	public function sees_all( string $module ): bool {
		if ( $this->system ) {
			return true;
		}

		if ( $this->user_id <= 0 ) {
			return false;
		}

		if ( ! array_key_exists( $module, $this->sees_all ) ) {
			$this->sees_all[ $module ] = (bool) Permissions::sees_all_records( $module, $this->user_id );
		}

		return $this->sees_all[ $module ];
	}

	/**
	 * Restrict a list query to this user's own records.
	 *
	 * Call this LAST, after every caller-supplied filter has been applied, so
	 * no input can widen the scope.
	 *
	 * @param object $query  Eloquent query builder.
	 * @param string $column Owner column on the table.
	 * @param string $module Module slug.
	 * @return object The query, for chaining.
	 */
	public function apply( $query, string $column, string $module ) {
		if ( $this->sees_all( $module ) ) {
			return $query;
		}

		return $query->where( $column, $this->user_id );
	}

	/**
	 * Whether this user owns a record.
	 *
	 * A null/0 owner counts as NOT owned: an unassigned record belongs to the
	 * team, and a scoped user should not reach it just because nobody claimed
	 * it.
	 *
	 * @param object $record Model instance.
	 * @param string $column Owner column.
	 * @param string $module Module slug.
	 * @return bool
	 */
	public function owns( $record, string $column, string $module ): bool {
		if ( $this->sees_all( $module ) ) {
			return true;
		}

		if ( ! is_object( $record ) ) {
			return false;
		}

		$owner = (int) ( $record->{$column} ?? 0 );

		return $owner > 0 && $owner === $this->user_id;
	}

	/**
	 * Guard an action on a single record; 403 when this user does not own it.
	 *
	 * @param object $record  Model instance.
	 * @param string $column  Owner column.
	 * @param string $module  Module slug.
	 * @param string $message Caller-facing refusal message.
	 * @return WP_Error|null Null when allowed.
	 */
	public function assert_owns( $record, string $column, string $module, string $message ): ?WP_Error {
		if ( $this->owns( $record, $column, $module ) ) {
			return null;
		}

		return AbilityResult::forbidden( $message );
	}

	// ------------------------------------------------------------------
	// Contacts: visibility (owner OR collaborator) vs ownership (owner).
	// ------------------------------------------------------------------

	/**
	 * Restrict a contacts query to the contacts this user may SEE: the ones
	 * they own, the ones they collaborate on, and unassigned ones.
	 *
	 * @param object $query  Contacts query builder.
	 * @param string $column Owner column (default `owner_id`).
	 * @return object
	 */
	public function apply_contact_visibility( $query, string $column = 'owner_id' ) {
		if ( $this->sees_all( 'contacts' ) ) {
			return $query;
		}

		$user_id = $this->user_id;
		$ids     = $this->collaborating_contact_ids();
		$table   = self::table_of( $query );
		$owner   = ( '' !== $table && false === strpos( $column, '.' ) ) ? $table . '.' . $column : $column;
		$id_col  = '' !== $table ? $table . '.id' : 'id';

		return $query->where(
			static function ( $q ) use ( $owner, $id_col, $user_id, $ids ) {
				$q->where( $owner, $user_id )
					// Unassigned contacts are the team's: visible, claimable.
					->orWhereNull( $owner )
					->orWhere( $owner, 0 );
				if ( ! empty( $ids ) ) {
					$q->orWhereIn( $id_col, $ids );
				}
			}
		);
	}

	/**
	 * The query's own table, so scoped columns stay unambiguous when the
	 * query joins another owned table (deals have an `owner_id` too).
	 *
	 * @param object $query Query builder.
	 * @return string Empty when it cannot be told.
	 */
	private static function table_of( $query ): string {
		if ( is_object( $query ) && method_exists( $query, 'getModel' ) ) {
			$model = $query->getModel();
			if ( is_object( $model ) && method_exists( $model, 'getTable' ) ) {
				return (string) $model->getTable();
			}
		}

		return '';
	}

	/**
	 * Restrict a contacts query to the contacts this user may EDIT: the ones
	 * they own plus unassigned ones (the team's). Collaborating is not enough.
	 *
	 * @param object $query  Contacts query builder.
	 * @param string $column Owner column (default `owner_id`).
	 * @return object
	 */
	public function apply_contact_editable( $query, string $column = 'owner_id' ) {
		if ( $this->sees_all( 'contacts' ) ) {
			return $query;
		}

		$table   = self::table_of( $query );
		$owner   = ( '' !== $table && false === strpos( $column, '.' ) ) ? $table . '.' . $column : $column;
		$user_id = $this->user_id;

		return $query->where(
			static function ( $q ) use ( $owner, $user_id ) {
				$q->where( $owner, $user_id )->orWhereNull( $owner )->orWhere( $owner, 0 );
			}
		);
	}

	/**
	 * Whether this user may edit a contact: an administrator or CRM Manager
	 * edits any contact, everyone else only the ones they own. Seeing every
	 * contact (a module manager) or collaborating on one is read only, and an
	 * unassigned contact is claimed before it is edited.
	 *
	 * @param object $contact Contact model.
	 * @return bool
	 */
	public function can_edit_contact( $contact ): bool {
		if ( $this->user_id <= 0 || ! is_object( $contact ) ) {
			return false;
		}

		if ( Permissions::can_edit_any_contact( $this->user_id ) ) {
			return true;
		}

		return (int) ( $contact->owner_id ?? 0 ) === $this->user_id;
	}

	/**
	 * Whether this user may delete a contact: the edit rule, for users allowed
	 * to delete at all (Sales Reps never are).
	 *
	 * @param object $contact Contact model.
	 * @return bool
	 */
	public function can_delete_contact( $contact ): bool {
		return Permissions::can_delete_contacts( $this->user_id ) && $this->can_edit_contact( $contact );
	}

	/**
	 * Restrict a contacts query to the contacts this user may delete.
	 *
	 * @param object $query  Contacts query builder.
	 * @param string $column Owner column (default `owner_id`).
	 * @return object
	 */
	public function apply_contact_deletable( $query, string $column = 'owner_id' ) {
		if ( ! Permissions::can_delete_contacts( $this->user_id ) ) {
			return $query->whereRaw( '1 = 0' );
		}

		if ( Permissions::can_edit_any_contact( $this->user_id ) ) {
			return $query;
		}

		$table = self::table_of( $query );
		if ( '' !== $table && false === strpos( $column, '.' ) ) {
			$column = $table . '.' . $column;
		}

		return $query->where( $column, $this->user_id > 0 ? $this->user_id : -1 );
	}

	/**
	 * Restrict a contacts query to the contacts this user OWNS. Export,
	 * campaign audiences, automation enrollment, merge and reassignment use
	 * this: collaborating on a contact never lets you take it along.
	 *
	 * @param object $query  Contacts query builder.
	 * @param string $column Owner column (default `owner_id`).
	 * @return object
	 */
	public function apply_contact_ownership( $query, string $column = 'owner_id' ) {
		$table = self::table_of( $query );
		if ( '' !== $table && false === strpos( $column, '.' ) ) {
			$column = $table . '.' . $column;
		}

		return $this->apply( $query, $column, 'contacts' );
	}

	/**
	 * Guard for records that point at a contact (invoices, proposals, tasks,
	 * deals): the caller must be able to see that contact, or it answers like
	 * a contact that does not exist.
	 *
	 * @param int $contact_id Contact id (0 = none, allowed).
	 * @return \WP_Error|null
	 */
	public static function contact_reference_error( int $contact_id ): ?\WP_Error {
		$scope = self::current();
		if ( $contact_id <= 0 || $scope->sees_all( 'contacts' ) ) {
			return null;
		}

		$contact = \DoubleScale\Modules\Contacts\Models\ContactModel::find( $contact_id );
		if ( $contact && $scope->can_view_contact( $contact ) ) {
			return null;
		}

		return new \WP_Error( 'contact_not_found', __( 'Contact not found', 'doublescale' ), array( 'status' => 404 ) );
	}

	/**
	 * Whether this user may see a contact (owner, collaborator, or sees all).
	 *
	 * @param object $contact Contact model.
	 * @return bool
	 */
	public function can_view_contact( $contact ): bool {
		if ( $this->owns_contact( $contact ) ) {
			return true;
		}

		if ( ! is_object( $contact ) ) {
			return false;
		}

		// Unassigned contacts belong to the team: every agent may see (and
		// claim) them. A deleted-creator scope (user 0) still sees nothing.
		if ( $this->user_id > 0 && (int) ( $contact->owner_id ?? 0 ) <= 0 ) {
			return true;
		}

		return in_array( (int) ( $contact->id ?? 0 ), $this->collaborating_contact_ids(), true );
	}

	/**
	 * Whether this user owns a contact (or sees all contacts).
	 *
	 * @param object $contact Contact model.
	 * @return bool
	 */
	public function owns_contact( $contact ): bool {
		return $this->owns( $contact, 'owner_id', 'contacts' );
	}

	/**
	 * Ids of the contacts this user collaborates on, loaded once per scope.
	 *
	 * @return int[]
	 */
	public function collaborating_contact_ids(): array {
		if ( null === $this->collaborating ) {
			$this->collaborating = ( $this->user_id > 0 && class_exists( ContactCollaboratorService::class ) )
				? ContactCollaboratorService::contact_ids_for_user( $this->user_id )
				: array();
		}

		return $this->collaborating;
	}

	/**
	 * The scope label reported alongside results ("all" / "own").
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	public function label( string $module ): string {
		return $this->sees_all( $module ) ? 'all' : 'own';
	}
}
