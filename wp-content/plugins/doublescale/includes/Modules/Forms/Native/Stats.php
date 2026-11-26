<?php
/**
 * Daily counters per form: views, starts (first answer typed) and completions.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

/**
 * Form stats.
 */
final class Stats {

	public const COUNTERS = array( 'views', 'starts', 'completions' );

	/**
	 * Add one to a counter for today.
	 *
	 * @param int    $form_id Form id.
	 * @param string $counter views, starts or completions.
	 * @param string $variant A/B variant ('' for none).
	 * @return void
	 */
	public static function bump( int $form_id, string $counter, string $variant = '' ): void {
		if ( $form_id <= 0 || ! in_array( $counter, self::COUNTERS, true ) || ! doublescale_table_exists( 'form_stats_daily' ) ) {
			return;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'doublescale_form_stats_daily';
		$variant = substr( sanitize_key( $variant ), 0, 40 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counter upsert; column name from the allow-list above.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (form_id, day, variant, {$counter}) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE {$counter} = {$counter} + 1", $form_id, current_time( 'Y-m-d' ), $variant ) );
	}

	/**
	 * Totals for a form over a period, plus one row per day.
	 *
	 * @param int    $form_id Form id.
	 * @param string $from    First day (Y-m-d).
	 * @param string $to      Last day (Y-m-d).
	 * @return array{totals: array, days: array}
	 */
	public static function summary( int $form_id, string $from, string $to ): array {
		$totals = array_fill_keys( self::COUNTERS, 0 );
		$days   = array();
		if ( doublescale_table_exists( 'form_stats_daily' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'doublescale_form_stats_daily';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read of our own table.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) views, SUM(starts) starts, SUM(completions) completions FROM {$table} WHERE form_id = %d AND day BETWEEN %s AND %s GROUP BY day ORDER BY day", $form_id, $from, $to ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$day = array( 'day' => $row['day'] );
				foreach ( self::COUNTERS as $counter ) {
					$day[ $counter ]     = (int) $row[ $counter ];
					$totals[ $counter ] += (int) $row[ $counter ];
				}
				$days[] = $day;
			}
		}
		$totals['conversion'] = $totals['views'] > 0 ? round( $totals['completions'] / $totals['views'] * 100, 1 ) : 0;

		return array(
			'totals' => $totals,
			'days'   => $days,
		);
	}

	/**
	 * Delete a form's counters.
	 *
	 * @param int $form_id Form id.
	 * @return void
	 */
	public static function forget( int $form_id ): void {
		if ( ! doublescale_table_exists( 'form_stats_daily' ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clean-up of our own table.
		$wpdb->delete( $wpdb->prefix . 'doublescale_form_stats_daily', array( 'form_id' => $form_id ) );
	}
}
