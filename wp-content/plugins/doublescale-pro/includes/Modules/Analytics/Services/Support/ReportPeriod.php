<?php
/**
 * Report period value object.
 *
 * Owns the current/previous date-range maths and the bucket generation used by
 * every entity report. The logic mirrors the private helpers on
 * RestReportsController (get_report_date_ranges / generate_date_range /
 * get_date_format_by_frequency / format_date_by_frequency) so period-over-period
 * numbers stay consistent with the existing deals reports, but lives here where
 * it is reusable and testable in isolation.
 *
 * The comparison baseline is deliberately "the same period one year ago" rather
 * than "the immediately preceding period" — that is the existing semantic on the
 * deals reports and changing it would silently move every existing number.
 *
 * @since 2.1.0
 * @package DoubleScale\Pro\Modules\Analytics\Services\Support
 */

namespace DoubleScale\Pro\Modules\Analytics\Services\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable current/previous period pair plus bucket helpers.
 */
final class ReportPeriod {

	const DAILY   = 'daily';
	const WEEKLY  = 'weekly';
	const MONTHLY = 'monthly';

	/**
	 * Default look-back window when no explicit range is supplied.
	 */
	const DEFAULT_DAYS_BACK = 30;

	/**
	 * @var string Y-m-d H:i:s
	 */
	private $current_start;

	/**
	 * @var string Y-m-d H:i:s
	 */
	private $current_end;

	/**
	 * @var string Y-m-d H:i:s
	 */
	private $previous_start;

	/**
	 * @var string Y-m-d H:i:s
	 */
	private $previous_end;

	/**
	 * @var string One of daily|weekly|monthly.
	 */
	private $frequency;

	/**
	 * @param string $current_start  Y-m-d H:i:s.
	 * @param string $current_end    Y-m-d H:i:s.
	 * @param string $previous_start Y-m-d H:i:s.
	 * @param string $previous_end   Y-m-d H:i:s.
	 * @param string $frequency      daily|weekly|monthly.
	 */
	public function __construct( $current_start, $current_end, $previous_start, $previous_end, $frequency = self::DAILY ) {
		$this->current_start  = (string) $current_start;
		$this->current_end    = (string) $current_end;
		$this->previous_start = (string) $previous_start;
		$this->previous_end   = (string) $previous_end;
		$this->frequency      = self::normalize_frequency( $frequency );
	}

	/**
	 * Build a period from REST filters.
	 *
	 * @param array<string, mixed> $filters          May contain date_from, date_to, frequency, days_back.
	 * @param int                  $default_days_back Fallback window.
	 * @return self
	 */
	public static function from_filters( array $filters, $default_days_back = self::DEFAULT_DAYS_BACK ) {
		$frequency = self::normalize_frequency( $filters['frequency'] ?? self::DAILY );
		$days_back = isset( $filters['days_back'] ) ? absint( $filters['days_back'] ) : 0;
		if ( $days_back <= 0 ) {
			$days_back = (int) $default_days_back;
		}

		$date_from = isset( $filters['date_from'] ) ? trim( (string) $filters['date_from'] ) : '';
		$date_to   = isset( $filters['date_to'] ) ? trim( (string) $filters['date_to'] ) : '';

		if ( '' !== $date_from && '' !== $date_to ) {
			$current_start = $date_from . ' 00:00:00';
			$current_end   = $date_to . ' 23:59:59';

			$from = new \DateTime( $date_from );
			$to   = new \DateTime( $date_to );
			$diff = (int) $from->diff( $to )->days;

			$previous_end   = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year', strtotime( $current_end ) ) );
			$previous_start = gmdate( 'Y-m-d H:i:s', strtotime( "-{$diff} days", strtotime( $previous_end ) ) );

			return new self( $current_start, $current_end, $previous_start, $previous_end, $frequency );
		}

		$now = current_time( 'mysql' );

		return new self(
			gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_back} days", strtotime( $now ) ) ),
			$now,
			gmdate( 'Y-m-d H:i:s', strtotime( "-1 year -{$days_back} days", strtotime( $now ) ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-1 year', strtotime( $now ) ) ),
			$frequency
		);
	}

	/**
	 * @return string
	 */
	public function current_start() {
		return $this->current_start;
	}

	/**
	 * @return string
	 */
	public function current_end() {
		return $this->current_end;
	}

	/**
	 * @return string
	 */
	public function previous_start() {
		return $this->previous_start;
	}

	/**
	 * @return string
	 */
	public function previous_end() {
		return $this->previous_end;
	}

	/**
	 * @return string
	 */
	public function frequency() {
		return $this->frequency;
	}

	/**
	 * Whole days spanned by the current window.
	 *
	 * @return int
	 */
	public function current_days() {
		$start = strtotime( $this->current_start );
		$end   = strtotime( $this->current_end );
		if ( ! $start || ! $end || $end < $start ) {
			return 0;
		}

		return (int) floor( ( $end - $start ) / DAY_IN_SECONDS );
	}

	/**
	 * Pre-seeded bucket labels covering the current window.
	 *
	 * Callers seed their series from this so gaps render as zeros rather than
	 * collapsing the x-axis.
	 *
	 * Returns a sequential-key list: the equivalent helper on RestReportsController
	 * returns array_unique() without array_values(), which leaves sparse keys and
	 * makes the value JSON-encode as an object instead of an array whenever a
	 * duplicate label is dropped.
	 *
	 * @return string[]
	 */
	public function buckets() {
		return self::generate_buckets( $this->current_start, $this->current_end, $this->frequency );
	}

	/**
	 * Bucket label for a single date under this period's frequency.
	 *
	 * @param string $date Any strtotime-parsable date.
	 * @return string
	 */
	public function bucket_for( $date ) {
		return self::format_by_frequency( $date, $this->frequency );
	}

	/**
	 * @param string $start_date Y-m-d or Y-m-d H:i:s.
	 * @param string $end_date   Y-m-d or Y-m-d H:i:s.
	 * @param string $frequency  daily|weekly|monthly.
	 * @return string[]
	 */
	public static function generate_buckets( $start_date, $end_date, $frequency ) {
		$frequency = self::normalize_frequency( $frequency );
		$current   = strtotime( $start_date );
		$end       = strtotime( $end_date );

		if ( ! $current || ! $end || $end < $current ) {
			return array();
		}

		$interval = '+1 day';
		if ( self::WEEKLY === $frequency ) {
			$interval = '+1 week';
			$current  = strtotime( 'monday this week', $current );
		} elseif ( self::MONTHLY === $frequency ) {
			$interval = '+1 month';
			$current  = strtotime( gmdate( 'Y-m-01', $current ) );
		}

		$buckets = array();
		while ( $current <= $end ) {
			$buckets[] = self::format_by_frequency( gmdate( 'Y-m-d', $current ), $frequency );
			$current   = strtotime( $interval, $current );
		}

		return array_values( array_unique( $buckets ) );
	}

	/**
	 * @param string $date      Any strtotime-parsable date.
	 * @param string $frequency daily|weekly|monthly.
	 * @return string
	 */
	public static function format_by_frequency( $date, $frequency ) {
		$timestamp = is_numeric( $date ) ? (int) $date : strtotime( (string) $date );
		if ( ! $timestamp ) {
			return '';
		}

		return gmdate( self::date_format_for( $frequency ), $timestamp );
	}

	/**
	 * @param string $frequency daily|weekly|monthly.
	 * @return string
	 */
	public static function date_format_for( $frequency ) {
		switch ( self::normalize_frequency( $frequency ) ) {
			case self::WEEKLY:
				return 'Y-\WW';
			case self::MONTHLY:
				return 'Y-m';
			case self::DAILY:
			default:
				return 'Y-m-d';
		}
	}

	/**
	 * @param mixed $frequency Raw value.
	 * @return string
	 */
	public static function normalize_frequency( $frequency ) {
		$frequency = is_string( $frequency ) ? strtolower( trim( $frequency ) ) : '';

		return in_array( $frequency, array( self::DAILY, self::WEEKLY, self::MONTHLY ), true )
			? $frequency
			: self::DAILY;
	}

	/**
	 * Percentage change between two values.
	 *
	 * Returns 0 when the previous value is zero or negative — matching the
	 * existing deals-report behaviour. Because that is indistinguishable from a
	 * genuinely flat period, reports also ship the raw previous value so the UI
	 * can render "—" instead of a misleading "0%".
	 *
	 * @param float $current  Current value.
	 * @param float $previous Previous value.
	 * @return float
	 */
	public static function percentage_change( $current, $previous ) {
		$previous = (float) $previous;
		if ( $previous <= 0 ) {
			return 0.0;
		}

		return round( ( ( (float) $current - $previous ) / $previous ) * 100, 2 );
	}

	/**
	 * @return array<string, string>
	 */
	public function to_array() {
		return array(
			'current_start'  => $this->current_start,
			'current_end'    => $this->current_end,
			'previous_start' => $this->previous_start,
			'previous_end'   => $this->previous_end,
			'frequency'      => $this->frequency,
		);
	}
}
