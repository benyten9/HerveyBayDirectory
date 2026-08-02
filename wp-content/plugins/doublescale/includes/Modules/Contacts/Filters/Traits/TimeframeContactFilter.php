<?php
/**
 * Trait TimeframeContactFilter
 *
 * Provides common timeframe SQL building and binding methods for activity filters.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * TimeframeContactFilter trait
 */
trait TimeframeContactFilter {

	/**
	 * Build timeframe SQL condition
	 *
	 * @since 1.0.0
	 *
	 * @param array  $timeframe_data Timeframe data containing type and optional date values.
	 * @param string $column_name    The column name to use in the SQL condition (default: 'created_at').
	 *
	 * @return string SQL condition string.
	 */
	protected function build_timeframe_sql( $timeframe_data, $column_name = 'created_at' ) {
		$type = $timeframe_data['type'] ?? 'at_any_time';

		switch ( $type ) {
			case 'at_any_time':
				return '';

			case 'today':
			case 'this_week':
			case 'this_month':
			case 'this_year':
			case 'in_the_last_24_hours':
			case 'in_the_last_7_days':
			case 'in_the_last_14_days':
			case 'in_the_last_30_days':
			case 'in_the_last_60_days':
			case 'in_the_last_90_days':
			case 'in_the_last_365_days':
			case 'in_the_last_x_days':
				return " AND {$column_name} >= ?";

			case 'before':
				return " AND {$column_name} < ?";

			case 'after':
				return " AND {$column_name} > ?";

			case 'yesterday':
			case 'last_week':
			case 'last_month':
			case 'between':
			case 'day_of':
				return " AND {$column_name} BETWEEN ? AND ?";

			default:
				return '';
		}
	}

	/**
	 * Get timeframe bindings for prepared statements
	 *
	 * @since 1.0.0
	 *
	 * @param array $timeframe_data Timeframe data containing type and optional date values.
	 *
	 * @return array Array of binding values for the SQL query.
	 */
	protected function get_timeframe_bindings( $timeframe_data ) {
		$type = $timeframe_data['type'] ?? 'at_any_time';

		switch ( $type ) {
			case 'at_any_time':
				return array();

			case 'today':
				return array( gmdate( 'Y-m-d 00:00:00' ) );

			case 'yesterday':
				return array(
					gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) ),
					gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) ),
				);

			case 'this_week':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) ) );

			case 'last_week':
				return array(
					gmdate( 'Y-m-d 00:00:00', strtotime( 'monday last week' ) ),
					gmdate( 'Y-m-d 23:59:59', strtotime( 'sunday last week' ) ),
				);

			case 'this_month':
				return array( gmdate( 'Y-m-01 00:00:00' ) );

			case 'last_month':
				return array(
					gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of last month' ) ),
					gmdate( 'Y-m-t 23:59:59', strtotime( 'last day of last month' ) ),
				);

			case 'this_year':
				return array( gmdate( 'Y-01-01 00:00:00' ) );

			case 'in_the_last_24_hours':
				return array( gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) ) );

			case 'in_the_last_7_days':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) ) );

			case 'in_the_last_14_days':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( '-14 days' ) ) );

			case 'in_the_last_30_days':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) ) );

			case 'in_the_last_60_days':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( '-60 days' ) ) );

			case 'in_the_last_90_days':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( '-90 days' ) ) );

			case 'in_the_last_365_days':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( '-365 days' ) ) );

			case 'in_the_last_x_days':
				$days = intval( $timeframe_data['count'] ?? 0 );
				return $days > 0
					? array( gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) ) )
					: array();

			case 'before':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date'] ) ) );

			case 'after':
				return array( gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date'] ) ) );

			case 'between':
				return array(
					gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date_from'] ) ),
					gmdate( 'Y-m-d 23:59:59', strtotime( $timeframe_data['date_to'] ) ),
				);

			case 'day_of':
				return array(
					gmdate( 'Y-m-d 00:00:00', strtotime( $timeframe_data['date'] ) ),
					gmdate( 'Y-m-d 23:59:59', strtotime( $timeframe_data['date'] ) ),
				);

			default:
				return array();
		}
	}

	/**
	 * Get comparison operator SQL string from count type
	 *
	 * @since 1.0.0
	 *
	 * @param string $count_type The count type (exactly, less_than, more_than, at_least, at_most).
	 *
	 * @return string SQL comparison operator.
	 */
	protected function get_comparison_operator( $count_type ) {
		switch ( $count_type ) {
			case 'extactly': // Accept misspelling as an alias for 'exactly'.
			case 'exactly':
				return '=';

			case 'less_than':
				return '<';

			case 'more_than':
				return '>';

			case 'at_least':
				return '>=';

			case 'at_most':
				return '<=';

			default:
				return '>=';
		}
	}

	/**
	 * Get SQL operator from filter operator string
	 *
	 * @since 1.0.0
	 *
	 * @param string $operator The filter operator.
	 *
	 * @return string SQL operator.
	 */
	protected function get_sql_operator( $operator ) {
		$operators = array(
			'is'                       => '=',
			'is_not'                   => '!=',
			'greater_than'             => '>',
			'lower_than'               => '<',
			'lower_than_or_equal_to'   => '<=',
			'greater_than_or_equal_to' => '>=',
		);

		return $operators[ $operator ] ?? '=';
	}
}
