<?php
/**
 * Input validation for write abilities.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Validates ability input BEFORE it reaches a model.
 *
 * Two reasons this has to happen up front rather than relying on the model:
 *
 * 1. Several models validate inside their Eloquent `saving()` hook and THROW —
 *    TaskModel does exactly this. {@see AbilityGuard::wrap_execute()} converts
 *    a throw into an opaque 500 with a correlation id, which tells the agent
 *    nothing it can act on and leaves the user reading logs.
 * 2. An agent that is told "invalid status" retries blindly; one told
 *    "status must be one of: pending, completed" corrects itself on the next
 *    call. Every message here therefore names the field AND lists what would
 *    have been accepted.
 */
final class AbilityInput {

	/**
	 * Require a set of fields to be present and non-empty.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input  Ability input.
	 * @param array<int, string>   $fields Required field names.
	 * @return WP_Error|null Null when every field is present.
	 */
	public static function required( array $input, array $fields ): ?WP_Error {
		$missing = array();

		foreach ( $fields as $field ) {
			// 0 and '0' are legitimate values; only absent/blank is missing.
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				$missing[] = $field;
			}
		}

		if ( array() === $missing ) {
			return null;
		}

		return new WP_Error(
			'doublescale_missing_field',
			sprintf(
				/* translators: %s: comma-separated field names */
				__( 'Missing required input: %s', 'doublescale' ),
				implode( ', ', $missing )
			),
			array(
				'status'  => 400,
				'missing' => $missing,
			)
		);
	}

	/**
	 * Validate a value against an allowed set.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed              $value   Supplied value.
	 * @param array<int, string> $allowed Accepted values.
	 * @param string             $field   Field name, for the message.
	 * @return WP_Error|null Null when the value is acceptable.
	 */
	public static function enum( $value, array $allowed, string $field ): ?WP_Error {
		if ( null === $value || '' === $value ) {
			return null; // Absent is not invalid; use required() for that.
		}

		if ( in_array( (string) $value, array_map( 'strval', $allowed ), true ) ) {
			return null;
		}

		return new WP_Error(
			'doublescale_invalid_value',
			sprintf(
				/* translators: 1: field name, 2: comma-separated allowed values */
				__( '%1$s must be one of: %2$s', 'doublescale' ),
				$field,
				implode( ', ', $allowed )
			),
			array(
				'status'  => 400,
				'field'   => $field,
				'allowed' => array_values( $allowed ),
			)
		);
	}

	/**
	 * Validate a YYYY-MM-DD date.
	 *
	 * Rejects impossible calendar dates too — "2026-02-31" matches the shape
	 * but is not a day, and letting it through produces a silently wrong due
	 * date rather than an error the agent can fix.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value Supplied value.
	 * @param string $field Field name, for the message.
	 * @return WP_Error|null Null when the value is acceptable.
	 */
	public static function date( $value, string $field ): ?WP_Error {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = (string) $value;

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		return new WP_Error(
			'doublescale_invalid_date',
			sprintf(
				/* translators: %s: field name */
				__( '%s must be a real date in YYYY-MM-DD format.', 'doublescale' ),
				$field
			),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * Validate a positive integer id.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value Supplied value.
	 * @param string $field Field name, for the message.
	 * @return WP_Error|null Null when the value is acceptable.
	 */
	public static function id( $value, string $field ): ?WP_Error {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return null;
		}

		return new WP_Error(
			'doublescale_invalid_id',
			sprintf(
				/* translators: %s: field name */
				__( '%s must be a positive record id.', 'doublescale' ),
				$field
			),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * Run several checks and return the first failure.
	 *
	 * Returning only the first keeps the message actionable — an agent handed
	 * five simultaneous complaints tends to rewrite the whole call rather than
	 * fix the one field that was wrong.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, WP_Error|null> $results Validation results.
	 * @return WP_Error|null
	 */
	public static function first_error( array $results ): ?WP_Error {
		foreach ( $results as $result ) {
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		return null;
	}
}
