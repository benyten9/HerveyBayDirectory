<?php
/**
 * Copy enrolments out of each sequence's settings JSON into rows.
 *
 * Older releases kept `contact_ids`, `contact_enrollments` and
 * `contact_exit_reasons` inside the parent sequence's `settings`. Each
 * sequence's entries become rows in `doublescale_sequence_contacts`, then
 * the three keys are removed from its settings. Safe to run again: rows are
 * inserted with INSERT IGNORE and keys are only removed once copied.
 *
 * @package DoubleScale\Pro\Modules\SequenceEnrollments\Migrations
 */

namespace DoubleScale\Pro\Modules\SequenceEnrollments\Migrations;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time data migration on our own tables.

/**
 * SequenceContactsBackfill migration.
 */
class SequenceContactsBackfill {

	/** Campaign type of a parent email sequence (CampaignChannel::CHANNEL_EMAIL_SEQUENCE). */
	const EMAIL_SEQUENCE_TYPE = 5;

	/** Rows per INSERT statement. */
	const CHUNK = 500;

	/** Option set once every sequence has been copied. */
	const DONE_OPTION = 'doublescale_sequence_contacts_backfilled';

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$campaigns = $wpdb->prefix . 'doublescale_campaigns';
		$table     = $wpdb->prefix . 'doublescale_sequence_contacts';

		foreach ( array( $campaigns, $table ) as $required ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $required ) ) !== $required ) {
				return;
			}
		}

		$sequences = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, settings, updated_at FROM {$campaigns} WHERE type = %d AND settings LIKE %s",
				self::EMAIL_SEQUENCE_TYPE,
				'%contact_%'
			)
		);

		foreach ( (array) $sequences as $sequence ) {
			$settings = json_decode( (string) $sequence->settings, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			if ( ! array_key_exists( 'contact_ids', $settings )
				&& ! array_key_exists( 'contact_enrollments', $settings )
				&& ! array_key_exists( 'contact_exit_reasons', $settings ) ) {
				continue;
			}

			$rows = self::rows_for( (int) $sequence->id, $settings, (string) $sequence->updated_at );
			if ( ! self::insert_rows( $table, $rows ) ) {
				// Keep the JSON so the next run can try again.
				continue;
			}

			unset( $settings['contact_ids'], $settings['contact_enrollments'], $settings['contact_exit_reasons'] );
			$wpdb->update(
				$campaigns,
				array( 'settings' => wp_json_encode( (object) $settings ) ),
				array( 'id' => (int) $sequence->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		update_option( self::DONE_OPTION, 1, false );
	}

	/**
	 * Turn one sequence's settings into enrolment rows.
	 *
	 * @param int    $sequence_id Parent sequence id.
	 * @param array  $settings    Decoded settings.
	 * @param string $fallback    Time to use when a join time is missing.
	 * @return array<int, array{0:int,1:int,2:string,3:string,4:?string,5:?string}>
	 */
	private static function rows_for( int $sequence_id, array $settings, string $fallback ): array {
		$fallback    = '' !== $fallback ? $fallback : current_time( 'mysql' );
		$active      = array_map( 'intval', (array) ( $settings['contact_ids'] ?? array() ) );
		$enrollments = (array) ( $settings['contact_enrollments'] ?? array() );
		$exits       = (array) ( $settings['contact_exit_reasons'] ?? array() );
		$rows        = array();

		foreach ( array_unique( $active ) as $contact_id ) {
			if ( $contact_id <= 0 ) {
				continue;
			}
			$joined                = $enrollments[ $contact_id ] ?? $enrollments[ (string) $contact_id ] ?? $fallback;
			$rows[ $contact_id ] = array( $sequence_id, $contact_id, 'active', (string) $joined, null, null );
		}

		// Contacts who left: their exit reason is an array {reason, at}.
		foreach ( $exits as $contact_id => $exit ) {
			$contact_id = (int) $contact_id;
			if ( $contact_id <= 0 || isset( $rows[ $contact_id ] ) || ! is_array( $exit ) ) {
				continue;
			}
			$at                    = (string) ( $exit['at'] ?? $fallback );
			$rows[ $contact_id ] = array( $sequence_id, $contact_id, 'exited', $at, $at, substr( (string) ( $exit['reason'] ?? 'manual' ), 0, 50 ) );
		}

		return array_values( $rows );
	}

	/**
	 * INSERT IGNORE the rows in chunks.
	 *
	 * @param string $table Table name.
	 * @param array  $rows  Rows from rows_for().
	 * @return bool False when a statement failed.
	 */
	private static function insert_rows( string $table, array $rows ): bool {
		global $wpdb;

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $row ) {
				$placeholders[] = '(%d, %d, %s, %s, ' . ( null === $row[4] ? 'NULL' : '%s' ) . ', ' . ( null === $row[5] ? 'NULL' : '%s' ) . ')';
				array_push( $values, $row[0], $row[1], $row[2], $row[3] );
				if ( null !== $row[4] ) {
					$values[] = $row[4];
				}
				if ( null !== $row[5] ) {
					$values[] = $row[5];
				}
			}

			$sql = "INSERT IGNORE INTO {$table} (sequence_id, contact_id, status, enrolled_at, exited_at, exit_reason) VALUES " . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built per row above.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
				return false;
			}
		}

		return true;
	}
}
