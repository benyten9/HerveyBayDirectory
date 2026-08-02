<?php
/**
 * Booking input helpers.
 *
 * Centralizes sanitization of incoming AJAX/form payloads so the four-step
 * "unslash, decode, recursively sanitize, type-check" sequence happens in
 * one lexically obvious place instead of being spread across each caller.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for reading user-supplied input from $_POST.
 */
class BookingInput {

	/**
	 * Read a JSON-encoded field from $_POST and return it as a sanitized array.
	 *
	 * Steps in order:
	 *   1. Check the key exists in $_POST; return $default if not.
	 *   2. wp_unslash() to strip WordPress' magic-quotes slashes.
	 *   3. json_decode() into an associative array.
	 *   4. Reject non-array decoded values (scalars, null, JSON parse errors).
	 *   5. map_deep( sanitize_text_field ) on every scalar leaf.
	 *
	 * Callers receive an already-sanitized array (or $default), so they never
	 * touch raw superglobal data.
	 *
	 * Caller responsibility: nonce/permission/CSRF checks. This helper
	 * intentionally does NOT verify a nonce — the surrounding endpoint must
	 * do that BEFORE calling this. See WordPress.Security.NonceVerification
	 * for context.
	 *
	 * @param string $key     The $_POST key to read.
	 * @param array  $default Value returned when the key is missing, the
	 *                        JSON is invalid, or the decoded value is not
	 *                        an array. Defaults to an empty array.
	 * @return array Sanitized associative array, or $default.
	 */
	public static function get_json_post( $key, $default = array() ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is the caller's responsibility; this helper only handles sanitization of an already-authorized request.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST is wp_unslash()ed and the decoded result is recursively sanitized below; raw JSON has no meaningful sanitize_text_field at the string level.
		$raw = wp_unslash( $_POST[ $key ] );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $default;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return $default;
		}

		return map_deep( $decoded, 'sanitize_text_field' );
	}
}
