<?php
/**
 * Trait DateWithinContactFilter
 *
 * Applies the `within` date operator as a rolling N-day window, with a
 * fallback for legacy calendar ranges saved before this change.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\Traits;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Utils\DateWithin;
use Illuminate\Database\Eloquent\Builder;

/**
 * DateWithinContactFilter trait
 */
trait DateWithinContactFilter {

	/**
	 * Restrict a relation's datetime column to the last N days (or a legacy range).
	 *
	 * @since 1.0.0
	 *
	 * @param Builder $query    Contact query.
	 * @param mixed   $value    Day count, or legacy [from, to] dates.
	 * @param string  $relation Eloquent relation name.
	 * @param string  $column   Datetime column on the related table.
	 * @return Builder
	 */
	protected function apply_within_on_relation( Builder $query, $value, $relation, $column ) {
		$days = DateWithin::parse_days( $value );
		if ( null !== $days ) {
			$cutoff = DateWithin::cutoff_datetime( $days );
			$query->whereHas(
				$relation,
				function ( $related ) use ( $column, $cutoff ) {
					$related->whereNotNull( $column )->where( $column, '>=', $cutoff );
				}
			);
			return $query;
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value, $value );
		}
		if ( count( $value ) < 2 || empty( $value[0] ) || empty( $value[1] ) ) {
			return $query;
		}

		try {
			$from = ( new \DateTime( (string) $value[0] ) )->format( 'Y-m-d' );
			$to   = ( new \DateTime( (string) $value[1] ) )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return $query;
		}

		$query->whereHas(
			$relation,
			function ( $related ) use ( $column, $from, $to ) {
				$related->whereNotNull( $column )
					->whereDate( $column, '>=', $from )
					->whereDate( $column, '<=', $to );
			}
		);

		return $query;
	}
}
