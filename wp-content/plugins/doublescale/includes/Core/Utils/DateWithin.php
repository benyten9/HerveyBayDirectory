<?php
/**
 * Relative "within N days" helper for date conditions.
 *
 * Used by Last Email Open/Clicked/Sent (and any date operator named `within`)
 * so "Within 14 days" means a rolling window from now, not a calendar date.
 *
 * @package DoubleScale\Core\Utils
 */

namespace DoubleScale\Core\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * DateWithin helper.
 */
final class DateWithin {

	/**
	 * Highest number of days accepted for a relative window.
	 */
	const MAX_DAYS = 3650;

	/**
	 * Parse a rule/filter value as a positive day count.
	 *
	 * Date strings, ranges, and out-of-range numbers return null so callers
	 * can fall back to legacy calendar-range behaviour.
	 *
	 * @param mixed $value Raw condition value.
	 * @return int|null
	 */
	public static function parse_days( $value ) {
		if ( is_array( $value ) || is_object( $value ) || '' === $value || null === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$days = (int) $value;
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			return null;
		}

		return $days;
	}

	/**
	 * GMT datetime N days ago, suitable for `column >= cutoff`.
	 *
	 * @param int $days Positive day count.
	 * @return string Y-m-d H:i:s
	 */
	public static function cutoff_datetime( $days ) {
		$days = max( 1, (int) $days );
		return gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	/**
	 * Whether $actual (timestamp or datetime string) falls in the last N days.
	 *
	 * @param int|string $actual Unix timestamp or parseable datetime.
	 * @param int        $days   Positive day count.
	 * @return bool
	 */
	public static function is_within_days( $actual, $days ) {
		$days = (int) $days;
		if ( $days < 1 ) {
			return false;
		}

		if ( $actual instanceof \DateTimeInterface ) {
			$timestamp = $actual->getTimestamp();
		} elseif ( is_numeric( $actual ) && (int) $actual > 100000 ) {
			$timestamp = (int) $actual;
		} else {
			$timestamp = strtotime( (string) $actual );
		}

		if ( false === $timestamp || $timestamp <= 0 ) {
			return false;
		}

		return $timestamp >= strtotime( "-{$days} days" );
	}
}
