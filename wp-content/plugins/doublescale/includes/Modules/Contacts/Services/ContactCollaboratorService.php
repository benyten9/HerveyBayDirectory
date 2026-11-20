<?php
/**
 * Contact collaborators: who may see and work a contact without owning it.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The Follow Up Boss / HighLevel "followers" model: a contact has ONE owner,
 * plus any number of collaborators who can open it, read its timeline and
 * work their own deals, tasks and tickets on it. Collaborators never export
 * it, reassign it, or reach it from their campaigns.
 *
 * Rows are mostly written for you: assigning a deal, task, ticket, booking
 * or sales document on a contact to a user who doesn't own it makes that
 * user a collaborator (reason `deal:123`, …). A collaboration outlives the
 * record it came from — the history stays theirs — until a manager or the
 * owner removes it.
 */
final class ContactCollaboratorService {

	public const REASON_MANUAL = 'manual';

	/**
	 * @return string Fully prefixed table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_contact_collaborators';
	}

	/**
	 * Make a user a collaborator on a contact (idempotent).
	 *
	 * @param int      $contact_id Contact id.
	 * @param int      $user_id    User id.
	 * @param string   $reason     Why: 'manual', or '<entity>:<id>'.
	 * @param int|null $added_by   Acting user (null = current user).
	 * @return bool True when a row was added.
	 */
	public static function add( int $contact_id, int $user_id, string $reason = self::REASON_MANUAL, ?int $added_by = null ): bool {
		global $wpdb;

		if ( $contact_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		$table  = self::table();
		$reason = substr( sanitize_key( $reason ) ?: self::REASON_MANUAL, 0, 64 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` (contact_id, user_id, added_by, reason) VALUES (%d, %d, %d, %s)",
				$contact_id,
				$user_id,
				(int) ( $added_by ?? get_current_user_id() ),
				$reason
			)
		);

		if ( $inserted ) {
			/**
			 * Fires when a user becomes a collaborator on a contact.
			 *
			 * @param int    $contact_id Contact id.
			 * @param int    $user_id    New collaborator.
			 * @param string $reason     Why they were added.
			 */
			do_action( 'doublescale_contact_collaborator_added', $contact_id, $user_id, $reason );
		}

		return (bool) $inserted;
	}

	/**
	 * Remove a collaborator from a contact.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $user_id    User id.
	 * @return bool True when a row was removed.
	 */
	public static function remove( int $contact_id, int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = (bool) $wpdb->delete(
			self::table(),
			array(
				'contact_id' => $contact_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( $removed ) {
			do_action( 'doublescale_contact_collaborator_removed', $contact_id, $user_id );
		}

		return $removed;
	}

	/**
	 * Ids of every contact a user collaborates on.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function contact_ids_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT contact_id FROM `{$table}` WHERE user_id = %d", $user_id ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Collaborators of a contact, with why and when they were added.
	 *
	 * @param int $contact_id Contact id.
	 * @return array<int, array{user_id:int, reason:string, added_by:int, created_at:string}>
	 */
	public static function for_contact( int $contact_id ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, reason, added_by, created_at FROM `{$table}` WHERE contact_id = %d ORDER BY created_at ASC", $contact_id ), ARRAY_A );

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
	 * Whether a user collaborates on a contact.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $user_id    User id.
	 * @return bool
	 */
	public static function is_collaborator( int $contact_id, int $user_id ): bool {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$table}` WHERE contact_id = %d AND user_id = %d LIMIT 1", $contact_id, $user_id ) );
	}

	/**
	 * A record on a contact was assigned to a user: make them a collaborator
	 * unless they own the contact already.
	 *
	 * Call this from every assignment path (deal owner, task assignee, ticket
	 * agent, booking host, sales document agent). Safe to call with an empty
	 * or unchanged assignee.
	 *
	 * @param string   $entity      Entity type ('deal', 'task', 'ticket', 'booking', 'invoice', 'proposal', …).
	 * @param int      $entity_id   Entity id.
	 * @param int      $contact_id  Contact the entity is about.
	 * @param int|null $new_user_id New assignee (null/0 = unassigned, nothing to do).
	 * @return void
	 */
	public static function sync_from_assignment( string $entity, int $entity_id, int $contact_id, ?int $new_user_id ): void {
		$new_user_id = (int) $new_user_id;
		if ( $contact_id <= 0 || $new_user_id <= 0 ) {
			return;
		}

		// Only agent-level users need the row (managers see every contact).
		// Recorded whatever the visibility mode, so switching to private
		// later keeps everyone's working contacts reachable.
		if ( ! \DoubleScale\Core\UserRoles\Permissions::is_agent_tier( $new_user_id ) ) {
			return;
		}

		global $wpdb;
		$contacts = $wpdb->prefix . 'doublescale_contacts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT owner_id FROM `{$contacts}` WHERE id = %d", $contact_id ) );
		if ( $owner === $new_user_id ) {
			return;
		}

		self::add( $contact_id, $new_user_id, sanitize_key( $entity ) . ':' . $entity_id );
	}

	/**
	 * Move every collaboration of one user to another (offboarding), or drop
	 * them when the target is the unassigned pool.
	 *
	 * @param int      $from_user_id Leaving user.
	 * @param int|null $to_user_id   New collaborator, or null to drop.
	 * @return int Rows affected.
	 */
	public static function reassign_all( int $from_user_id, ?int $to_user_id ): int {
		global $wpdb;

		$table = self::table();

		if ( null === $to_user_id || $to_user_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->delete( $table, array( 'user_id' => $from_user_id ), array( '%d' ) );
		}

		// Drop the rows the target already holds, then move the rest.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE a FROM `{$table}` a INNER JOIN `{$table}` b ON a.contact_id = b.contact_id AND b.user_id = %d WHERE a.user_id = %d",
				$to_user_id,
				$from_user_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->update( $table, array( 'user_id' => $to_user_id ), array( 'user_id' => $from_user_id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Drop every collaboration on a contact (contact deleted).
	 *
	 * @param int $contact_id Contact id.
	 * @return void
	 */
	public static function purge_contact( int $contact_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'contact_id' => $contact_id ), array( '%d' ) );
	}
}
