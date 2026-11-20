<?php
/**
 * Delete help assistant conversations idle for longer than the retention
 * period (HelpSettings retention_days, 180 by default), once a day. Idle =
 * since the last message (updated_at), so a chat still in use is kept.
 *
 * A conversation handed to support also lives on as the ticket's opening
 * message; that copy belongs to the ticket and is not touched.
 *
 * Runs through the plugin's task queue (group doublescale_help, hook
 * doublescale_help_purge) and deletes in batches (filter
 * doublescale_help_purge_batch), up to RUNS batches per day.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Tasks;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables.

/**
 * Retention class.
 */
final class Retention {

	private const HOOK = 'purge';

	/**
	 * Conversations deleted per run.
	 */
	private const BATCH = 1000;

	/**
	 * Batches per daily run.
	 */
	private const RUNS = 20;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_task' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ), 30 );
	}

	/**
	 * @return void
	 */
	public static function register_task(): void {
		( new Tasks( Indexer::GROUP ) )->register_callback( self::HOOK, array( self::class, 'purge' ), true );
	}

	/**
	 * Queue the daily run once.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		// Checked once a day, not on every admin page.
		if ( ! function_exists( 'as_schedule_recurring_action' ) || get_transient( 'doublescale_help_purge_checked' ) ) {
			return;
		}
		set_transient( 'doublescale_help_purge_checked', 1, DAY_IN_SECONDS );
		$tasks = new Tasks( Indexer::GROUP );
		if ( false === $tasks->get_next_timestamp( self::HOOK ) ) {
			$tasks->schedule_recurring( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Delete conversations older than the retention period.
	 *
	 * @return int Conversations deleted.
	 */
	public static function purge(): int {
		Schema::ensure();
		global $wpdb;
		$conv = ConversationStore::conversations();
		$days = (int) HelpSettings::get()['retention_days'];

		$batch   = max( 1, (int) apply_filters( 'doublescale_help_purge_batch', self::BATCH ) );
		$deleted = 0;

		for ( $run = 0; $run < self::RUNS; $run++ ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$conv} WHERE updated_at < NOW() - INTERVAL %d DAY ORDER BY id ASC LIMIT %d", $days, $batch ) ) );
			foreach ( $ids as $id ) {
				ConversationStore::delete( $id );
			}
			$deleted += count( $ids );
			if ( count( $ids ) < $batch ) {
				break;
			}
		}

		return $deleted;
	}
}
