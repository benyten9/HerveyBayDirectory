<?php
/**
 * Trait LastEmailDateContactFilter
 *
 * Shared by Last Email Open / Sent / Clicked: each compares the date of the
 * contact's latest email event, not of any email.
 *
 * @package DoubleScale\Modules\Contacts\Filters\Traits
 */

namespace DoubleScale\Modules\Contacts\Filters\Traits;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * LastEmailDateContactFilter trait
 */
trait LastEmailDateContactFilter {

	use DateWithinContactFilter;

	/**
	 * Restrict contacts by the date of their latest event in `$column`.
	 *
	 * "Before" and "On" used to match any email on that side of the date, so a
	 * contact who opened something 40 days ago and again yesterday counted as
	 * "last opened before 30 days ago". "Between" passed a single date to a
	 * range query and was a fatal error.
	 *
	 * @since 1.0.0
	 *
	 * @param Builder $query    Contact query.
	 * @param string  $operator before | after | on | between | within.
	 * @param mixed   $value    A date, or [from, to] for between, or a day count for within.
	 * @param string  $relation Relation holding the events.
	 * @param string  $column   Datetime column of the event.
	 * @return Builder
	 */
	protected function apply_last_email_date( Builder $query, $operator, $value, $relation, $column ) {
		if ( 'within' === $operator ) {
			return $this->apply_within_on_relation( $query, $value, $relation, $column );
		}

		if ( 'between' === $operator ) {
			$range = $this->last_email_date_range( $value );
			if ( null === $range ) {
				return $query;
			}

			return $this->latest_event_between( $query, $relation, $column, $range[0], $range[1] );
		}

		$dates = $this->last_email_dates( $value );
		if ( empty( $dates ) ) {
			return $query;
		}
		$day = $dates[0];

		switch ( $operator ) {
			case 'before':
				$query->whereHas(
					$relation,
					function ( $events ) use ( $column, $day ) {
						$events->whereDate( $column, '<', $day );
					}
				)->whereDoesntHave(
					$relation,
					function ( $events ) use ( $column, $day ) {
						$events->whereDate( $column, '>=', $day );
					}
				);
				break;

			case 'after':
				// The latest event is after the day exactly when any event is.
				$query->whereHas(
					$relation,
					function ( $events ) use ( $column, $day ) {
						$events->whereDate( $column, '>', $day );
					}
				);
				break;

			case 'on':
				$query = $this->latest_event_between( $query, $relation, $column, $day, $day );
				break;
		}

		return $query;
	}

	/**
	 * Latest event falls on a day from `$from` to `$to`: one inside, none after.
	 *
	 * @param Builder $query    Contact query.
	 * @param string  $relation Relation holding the events.
	 * @param string  $column   Datetime column of the event.
	 * @param string  $from     First day, Y-m-d.
	 * @param string  $to       Last day, Y-m-d, counted in full.
	 * @return Builder
	 */
	private function latest_event_between( Builder $query, $relation, $column, $from, $to ) {
		return $query->whereHas(
			$relation,
			function ( $events ) use ( $column, $from, $to ) {
				$events->whereDate( $column, '>=', $from )->whereDate( $column, '<=', $to );
			}
		)->whereDoesntHave(
			$relation,
			function ( $events ) use ( $column, $to ) {
				$events->whereDate( $column, '>', $to );
			}
		);
	}

	/**
	 * [from, to] for "between": two dates in either order, or one date meaning
	 * that day (what the date field sent before it offered two).
	 *
	 * @param mixed $value Rule value.
	 * @return string[]|null Two Y-m-d dates, or null when none is readable.
	 */
	private function last_email_date_range( $value ) {
		$dates = $this->last_email_dates( $value );
		if ( empty( $dates ) ) {
			return null;
		}

		$from = $dates[0];
		$to   = $dates[1] ?? $dates[0];

		return $from <= $to ? array( $from, $to ) : array( $to, $from );
	}

	/**
	 * Every readable date in a rule value, as Y-m-d, in order.
	 *
	 * @param mixed $value A date string, or a (possibly nested) list of them.
	 * @return string[]
	 */
	private function last_email_dates( $value ) {
		$dates = array();

		foreach ( (array) $value as $item ) {
			if ( is_array( $item ) ) {
				$dates = array_merge( $dates, $this->last_email_dates( $item ) );
				continue;
			}
			if ( ! is_scalar( $item ) || '' === trim( (string) $item ) ) {
				continue;
			}
			try {
				$dates[] = ( new \DateTime( (string) $item ) )->format( 'Y-m-d' );
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return $dates;
	}
}
