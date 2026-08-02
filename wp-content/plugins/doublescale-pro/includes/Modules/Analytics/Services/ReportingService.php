<?php
/**
 * Reporting service — centralises analytics query logic.
 *
 * Heavy Eloquent queries that were previously embedded inside
 * RestReportsController belong here so they are testable in isolation
 * and reusable from non-REST contexts (WP-CLI, cron jobs, etc.).
 *
 * @since 2.0.0
 * @package DoubleScale\Pro\Modules\Analytics\Services
 */

namespace DoubleScale\Pro\Modules\Analytics\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Core\Utils\Utils;

class ReportingService {

	/**
	 * Get contact growth data for a date range.
	 *
	 * @param string $start_date Y-m-d format.
	 * @param string $end_date   Y-m-d format.
	 * @return array{ dates: string[], type: string, data: array }
	 */
	public function get_contact_growth( string $start_date, string $end_date ): array {
		$date_info = Utils::get_dates_between_dates( $start_date, $end_date );

		$query = ContactModel::query()
			->whereBetween( 'created_at', array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) );

		if ( 'hour' === $date_info['type'] ) {
			$query->selectRaw( 'HOUR(created_at) as period, COUNT(*) as total' )
				->groupByRaw( 'HOUR(created_at)' );
		} elseif ( 'month' === $date_info['type'] ) {
			$query->selectRaw( "DATE_FORMAT(created_at, '%Y-%m-%d') as period, COUNT(*) as total" )
				->groupByRaw( "DATE_FORMAT(created_at, '%Y-%m-01')" );
		} else {
			$query->selectRaw( 'DATE(created_at) as period, COUNT(*) as total' )
				->groupByRaw( 'DATE(created_at)' );
		}

		return array(
			'dates'   => $date_info['dates'],
			'type'    => $date_info['type'],
			'results' => $query->get()->toArray(),
		);
	}

	/**
	 * Get activity count by type for a date range.
	 *
	 * @param string $start_date Y-m-d format.
	 * @param string $end_date   Y-m-d format.
	 * @return array<string, int>
	 */
	public function get_activity_summary( string $start_date, string $end_date ): array {
		return ActivityModel::query()
			->whereBetween( 'created_at', array( $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) )
			->selectRaw( 'type, COUNT(*) as total' )
			->groupBy( 'type' )
			->pluck( 'total', 'type' )
			->toArray();
	}
}
