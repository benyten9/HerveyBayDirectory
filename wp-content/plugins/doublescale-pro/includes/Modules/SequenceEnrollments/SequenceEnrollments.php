<?php
/**
 * Read and write email sequence enrolments.
 *
 * One row per contact per parent sequence. Joining is one INSERT and the
 * unique (sequence_id, contact_id) key decides "already enrolled", so
 * concurrent workflow batches never wait on a shared row.
 *
 * @package DoubleScale\Pro\Modules\SequenceEnrollments
 */

namespace DoubleScale\Pro\Modules\SequenceEnrollments;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\SequenceEnrollments\Migrations\SequenceContactsBackfill;
use DoubleScale\Pro\Modules\SequenceEnrollments\Migrations\SequenceContactsTable;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- enrolment rows are written and read on every send; the table name is our own.

/**
 * SequenceEnrollments repository.
 */
final class SequenceEnrollments {

	const ACTIVE = 'active';
	const EXITED = 'exited';

	/** enroll() results. */
	const ENROLLED = 'enrolled';
	const ALREADY  = 'already';

	/** @var bool Table and backfill confirmed for this request. */
	private static $ready = false;

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_sequence_contacts';
	}

	/**
	 * Create the table and copy old enrolments if the install step has not yet.
	 *
	 * @return void
	 */
	public static function ensure_ready(): void {
		if ( self::$ready ) {
			return;
		}
		self::$ready = true;

		if ( get_option( SequenceContactsBackfill::DONE_OPTION ) ) {
			return;
		}

		require_once __DIR__ . '/Migrations/SequenceContactsTable.php';
		require_once __DIR__ . '/Migrations/SequenceContactsBackfill.php';
		( new SequenceContactsTable() )->run();
		( new SequenceContactsBackfill() )->run();
	}

	/**
	 * Enrol a contact. Re-enrols one who left; with $force also restarts an
	 * active one's clock.
	 *
	 * @param int  $sequence_id Parent sequence id.
	 * @param int  $contact_id  Contact id.
	 * @param bool $force       Restart an active enrolment.
	 * @return string|false ENROLLED, ALREADY, or false on a database error.
	 */
	public static function enroll( int $sequence_id, int $contact_id, bool $force = false ) {
		global $wpdb;
		self::ensure_ready();

		$table = self::table();
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (sequence_id, contact_id, status, enrolled_at) VALUES (%d, %d, %s, %s)",
				$sequence_id,
				$contact_id,
				self::ACTIVE,
				$now
			)
		);
		if ( false === $inserted ) {
			return false;
		}
		if ( 1 === (int) $inserted ) {
			return self::ENROLLED;
		}

		// The row exists: bring back a contact who left, or restart when forced.
		$where_status = $force ? '' : $wpdb->prepare( ' AND status <> %s', self::ACTIVE );
		$updated      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, enrolled_at = %s, exited_at = NULL, exit_reason = NULL WHERE sequence_id = %d AND contact_id = %d" . $where_status,
				self::ACTIVE,
				$now,
				$sequence_id,
				$contact_id
			)
		);
		if ( false === $updated ) {
			return false;
		}

		return (int) $updated > 0 ? self::ENROLLED : self::ALREADY;
	}

	/**
	 * Mark an active enrolment as left.
	 *
	 * @param int    $sequence_id Parent sequence id.
	 * @param int    $contact_id  Contact id.
	 * @param string $reason      Exit reason.
	 * @return bool False on a database error.
	 */
	public static function exit_contact( int $sequence_id, int $contact_id, string $reason ): bool {
		global $wpdb;
		self::ensure_ready();

		$table = self::table();

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, exited_at = %s, exit_reason = %s WHERE sequence_id = %d AND contact_id = %d AND status = %s",
				self::EXITED,
				current_time( 'mysql' ),
				substr( $reason, 0, 50 ),
				$sequence_id,
				$contact_id,
				self::ACTIVE
			)
		);
	}

	/**
	 * @param int $sequence_id Parent sequence id.
	 * @param int $contact_id  Contact id.
	 * @return bool
	 */
	public static function is_enrolled( int $sequence_id, int $contact_id ): bool {
		global $wpdb;
		self::ensure_ready();

		$table = self::table();

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE sequence_id = %d AND contact_id = %d AND status = %s LIMIT 1",
				$sequence_id,
				$contact_id,
				self::ACTIVE
			)
		);
	}

	/**
	 * Number of contacts currently enrolled.
	 *
	 * @param int $sequence_id Parent sequence id.
	 * @return int
	 */
	public static function active_count( int $sequence_id ): int {
		global $wpdb;
		self::ensure_ready();

		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE sequence_id = %d AND status = %s",
				$sequence_id,
				self::ACTIVE
			)
		);
	}

	/**
	 * Sub-query for `whereIn( 'id', … )`: contacts enrolled in the sequence,
	 * optionally only those who joined at or before $joined_by.
	 *
	 * @param int         $sequence_id Parent sequence id.
	 * @param string|null $joined_by   WordPress local datetime, or null for all.
	 * @return \Closure
	 */
	public static function active_ids( int $sequence_id, ?string $joined_by = null ): \Closure {
		self::ensure_ready();
		$table = self::table();

		return static function ( $query ) use ( $table, $sequence_id, $joined_by ) {
			$query->select( 'contact_id' )
				->from( $table )
				->where( 'sequence_id', $sequence_id )
				->where( 'status', self::ACTIVE );
			if ( null !== $joined_by ) {
				$query->where( 'enrolled_at', '<=', $joined_by );
			}
		};
	}

	/**
	 * Remove a deleted contact's enrolments (hooked on doublescale_contact_deleting).
	 *
	 * @param object $contact The contact being deleted.
	 * @return void
	 */
	public static function forget_contact( $contact ): void {
		global $wpdb;

		$contact_id = (int) ( $contact->id ?? 0 );
		if ( $contact_id <= 0 ) {
			return;
		}
		self::ensure_ready();

		$wpdb->delete( self::table(), array( 'contact_id' => $contact_id ), array( '%d' ) );
	}

	/**
	 * Remove every enrolment of a deleted sequence.
	 *
	 * @param int $sequence_id Parent sequence id.
	 * @return void
	 */
	public static function delete_for_sequence( int $sequence_id ): void {
		global $wpdb;
		self::ensure_ready();

		$wpdb->delete( self::table(), array( 'sequence_id' => $sequence_id ), array( '%d' ) );
	}
}
