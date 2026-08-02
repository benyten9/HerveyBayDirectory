<?php

/**
 * Trait TimeframeFilter
 *
 * This trait provides shared timeframe filtering functionality for rules
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * TimeframeFilter trait
 */
trait TimeframeFilter {

	/**
	 * Apply timeframe filter to query
	 *
	 * @since 1.0.0
	 *
	 * @param object $query Query object.
	 * @param array  $timeframe_data Timeframe data.
	 * @param string $meta_key The column name to filter by (e.g., 'opened_at', 'clicked_at', 'created_at').
	 *
	 * @return object
	 */
	protected function apply_timeframe_filter( $query, $timeframe_data, $meta_key = 'created_at' ) {
		$type = $timeframe_data['type'] ?? 'at_any_time';

		switch ( $type ) {
			case 'at_any_time':
				// No filter needed
				break;

			case 'today':
				$query->where( $meta_key, '>=', gmdate( 'Y-m-d 00:00:00' ) );
				break;

			case 'yesterday':
				$yesterday_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );
				$yesterday_end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) );
				$query->whereBetween( $meta_key, array( $yesterday_start, $yesterday_end ) );
				break;

			case 'this_week':
				$week_start = gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
				$query->where( $meta_key, '>=', $week_start );
				break;

			case 'last_week':
				$last_week_start = gmdate( 'Y-m-d 00:00:00', strtotime( 'monday last week' ) );
				$last_week_end   = gmdate( 'Y-m-d 23:59:59', strtotime( 'sunday last week' ) );
				$query->whereBetween( $meta_key, array( $last_week_start, $last_week_end ) );
				break;

			case 'this_month':
				$month_start = gmdate( 'Y-m-01 00:00:00' );
				$query->where( $meta_key, '>=', $month_start );
				break;

			case 'last_month':
				$last_month_start = gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of last month' ) );
				$last_month_end   = gmdate( 'Y-m-t 23:59:59', strtotime( 'last day of last month' ) );
				$query->whereBetween( $meta_key, array( $last_month_start, $last_month_end ) );
				break;

			case 'this_year':
				$year_start = gmdate( 'Y-01-01 00:00:00' );
				$query->where( $meta_key, '>=', $year_start );
				break;

			case 'in_the_last_24_hours':
				$start_time = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
				$query->where( $meta_key, '>=', $start_time );
				break;

			case 'in_the_last_7_days':
				$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
				$query->where( $meta_key, '>=', $start_date );
				break;

			case 'in_the_last_14_days':
				$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-14 days' ) );
				$query->where( $meta_key, '>=', $start_date );
				break;

			case 'in_the_last_30_days':
				$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) );
				$query->where( $meta_key, '>=', $start_date );
				break;

			case 'in_the_last_60_days':
				$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-60 days' ) );
				$query->where( $meta_key, '>=', $start_date );
				break;

			case 'in_the_last_90_days':
				$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-90 days' ) );
				$query->where( $meta_key, '>=', $start_date );
				break;

			case 'in_the_last_365_days':
				$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-365 days' ) );
				$query->where( $meta_key, '>=', $start_date );
				break;

			case 'in_the_last_x_days':
				if ( isset( $timeframe_data['count'] ) && $timeframe_data['count'] > 0 ) {
					$days       = intval( $timeframe_data['count'] );
					$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
					$query->where( $meta_key, '>=', $start_date );
				}
				break;

			case 'before':
				if ( isset( $timeframe_data['date'] ) && ! empty( $timeframe_data['date'] ) ) {
					$date = gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date'] ) );
					$query->where( $meta_key, '<', $date );
				}
				break;

			case 'after':
				if ( isset( $timeframe_data['date'] ) && ! empty( $timeframe_data['date'] ) ) {
					$date = gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date'] ) );
					$query->where( $meta_key, '>', $date );
				}
				break;

			case 'between':
				if ( isset( $timeframe_data['date_from'] ) && isset( $timeframe_data['date_to'] ) ) {
					$date_from = gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date_from'] ) );
					$date_to   = gmdate( 'Y-m-d 23:59:59', strtotime( $timeframe_data['date_to'] ) );
					$query->whereBetween( $meta_key, array( $date_from, $date_to ) );
				}
				break;

			case 'day_of':
				if ( isset( $timeframe_data['date'] ) && ! empty( $timeframe_data['date'] ) ) {
					$date_start = gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date'] ) );
					$date_end   = gmdate( 'Y-m-d 23:59:59', strtotime( $timeframe_data['date'] ) );
					$query->whereBetween( $meta_key, array( $date_start, $date_end ) );
				}
				break;
		}

		return $query;
	}
}
