<?php
/**
 * Hand everything a user owns to someone else (or to the unassigned pool).
 *
 * @package DoubleScale\Core\UserRoles\Services
 */

namespace DoubleScale\Core\UserRoles\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Services\ContactCollaboratorService;

/**
 * When an agent leaves, nothing is deleted: their contacts, deals, tasks,
 * tickets and documents move to the person the manager picks. Contacts may
 * also go to the unassigned pool (null); records whose column cannot be
 * empty keep pointing at a user, so for those the pool means "leave as is".
 */
final class OwnershipReassigner {

	/**
	 * How many records of each kind a user owns.
	 *
	 * @param int $user_id User id.
	 * @return array<string, array{label:string, count:int}> Only kinds with records.
	 */
	public static function summary( int $user_id ): array {
		global $wpdb;

		$summary = array();
		if ( $user_id <= 0 ) {
			return $summary;
		}

		foreach ( OwnershipRegistry::columns() as $slug => $entry ) {
			$table = $wpdb->prefix . $entry['table'];
			if ( ! OwnershipRegistry::table_exists( $table ) ) {
				continue;
			}

			$column = esc_sql( $entry['column'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %d", $user_id ) );

			if ( $count > 0 ) {
				$summary[ $slug ] = array(
					'label' => $entry['label'],
					'count' => $count,
				);
			}
		}

		return $summary;
	}

	/**
	 * Total records a user owns.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function total( int $user_id ): int {
		return (int) array_sum( array_column( self::summary( $user_id ), 'count' ) );
	}

	/**
	 * Move every record from one user to another.
	 *
	 * @param int      $from_user_id Leaving user.
	 * @param int|null $to_user_id   New owner; null sends contacts to the
	 *                               unassigned pool and drops collaborations.
	 * @return array<string, int> Rows moved per kind.
	 */
	public static function reassign_all( int $from_user_id, ?int $to_user_id ): array {
		global $wpdb;

		$moved = array();
		if ( $from_user_id <= 0 || $from_user_id === $to_user_id ) {
			return $moved;
		}

		// No explicit transaction: COMMIT would also end any transaction a
		// caller already opened. Each UPDATE matches `= $from_user_id`, so a
		// run that stops half-way is completed by simply running it again.
		foreach ( OwnershipRegistry::columns() as $slug => $entry ) {
			$table = $wpdb->prefix . $entry['table'];
			if ( ! OwnershipRegistry::table_exists( $table ) ) {
				continue;
			}

			// Only contacts have a pool; everything else needs a person.
			if ( null === $to_user_id && 'contacts' !== $slug ) {
				continue;
			}

			$column = esc_sql( $entry['column'] );
			if ( null === $to_user_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `{$column}` = NULL WHERE `{$column}` = %d", $from_user_id ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `{$column}` = %d WHERE `{$column}` = %d", $to_user_id, $from_user_id ) );
			}

			if ( false === $rows ) {
				throw new \RuntimeException( $wpdb->last_error );
			}

			$moved[ $slug ] = (int) $rows;
		}

		$moved['collaborations'] = ContactCollaboratorService::reassign_all( $from_user_id, $to_user_id );

		/**
		 * Fires after a user's records were handed to someone else.
		 *
		 * @param int                $from_user_id Leaving user.
		 * @param int|null           $to_user_id   New owner (null = pool).
		 * @param array<string, int> $moved        Rows moved per kind.
		 */
		do_action( 'doublescale_records_reassigned', $from_user_id, $to_user_id, $moved );

		return $moved;
	}
}
