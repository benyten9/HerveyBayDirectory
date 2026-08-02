<?php
/**
 * Phone Number Validator
 * Centralized utility for validating phone numbers in E.164 format
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Validators;

defined( 'ABSPATH' ) || exit;

/**
 * PhoneValidator class
 *
 * Provides consistent phone number validation across the entire plugin.
 * Validates E.164 international phone number format.
 *
 * @since 1.0.0
 */
class PhoneValidator {

	/**
	 * E.164 format regex pattern
	 * Format: +[country code][number]
	 * - Must start with +
	 * - Country code: 1-3 digits (1-9)
	 * - Total length: 8-15 digits (including country code)
	 *
	 * Examples:
	 * - +1234567890 (US)
	 * - +447975777666 (UK)
	 * - +33612345678 (France)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const E164_PATTERN = '/^\+[1-9]\d{1,14}$/';

	/**
	 * Validate phone number in E.164 format
	 *
	 * @since 1.0.0
	 * @param string|null $phone Phone number to validate
	 * @return bool True if valid E.164 format, false otherwise
	 */
	public static function is_valid( $phone ) {
		if ( empty( $phone ) ) {
			return false;
		}

		return (bool) preg_match( self::E164_PATTERN, $phone );
	}

	/**
	 * Validate phone number and return validation result with error message
	 *
	 * @since 1.0.0
	 * @param string|null $phone Phone number to validate
	 * @param string      $context Optional context for logging (e.g., 'campaign', 'action', 'contact')
	 * @return array{valid: bool, error: string|null} Validation result
	 */
	public static function validate( $phone, $context = '' ) {
		if ( empty( $phone ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Phone number is required', 'doublescale' ),
			);
		}

		if ( ! self::is_valid( $phone ) ) {
			$error_message = sprintf(
				/* translators: %s: example phone number */
				__( 'Invalid phone number format. Please use E.164 format (e.g., %s)', 'doublescale' ),
				'+1234567890'
			);

			// Log validation failure if context provided
			if ( ! empty( $context ) ) {
				doublescale_get_logger()->debug(
					'Phone number validation failed',
					array(
						'code'    => 'invalid_phone_format',
						'context' => $context,
						'phone'   => $phone,
					)
				);
			}

			return array(
				'valid' => false,
				'error' => $error_message,
			);
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}

	/**
	 * Sanitize phone number to E.164 format
	 * Attempts to clean and format phone number
	 *
	 * @since 1.0.0
	 * @param string      $phone Phone number to sanitize
	 * @param string|null $default_country_code Optional default country code (e.g., '1' for US)
	 * @return string|null Sanitized phone number or null if invalid
	 */
	public static function sanitize( $phone, $default_country_code = null ) {
		if ( empty( $phone ) ) {
			return null;
		}

		// Remove all non-numeric characters except +
		$cleaned = preg_replace( '/[^0-9+]/', '', $phone );

		// If already starts with +, validate and return
		if ( strpos( $cleaned, '+' ) === 0 ) {
			return self::is_valid( $cleaned ) ? $cleaned : null;
		}

		// Try to add default country code if provided
		if ( ! empty( $default_country_code ) ) {
			$with_country_code = '+' . $default_country_code . ltrim( $cleaned, '0' );
			if ( self::is_valid( $with_country_code ) ) {
				return $with_country_code;
			}
		}

		// Try with + prefix only
		$with_plus = '+' . $cleaned;
		return self::is_valid( $with_plus ) ? $with_plus : null;
	}

	/**
	 * Normalize a raw phone value for the contact `phone` column (loose format).
	 *
	 * Keeps an optional leading "+" plus digits. This matches the contact
	 * `phone` rule (^\+?[0-9]+$) and is intentionally lenient so national
	 * WooCommerce billing numbers are still stored even without a country code.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $phone Raw phone value.
	 *
	 * @return string Normalized value, or '' when nothing usable remains.
	 */
	public static function normalize_loose( $phone ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}

		$has_plus = ( '+' === substr( $phone, 0, 1 ) );
		$digits   = preg_replace( '/[^0-9]/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		return $has_plus ? '+' . $digits : $digits;
	}

	/**
	 * Convert a raw phone value to strict E.164 for the `whatsapp_phone` column.
	 *
	 * Numbers already carrying a leading "+" are sanitized as-is. National
	 * numbers (e.g. "(202) 555-0143") are completed using the supplied country
	 * code so they satisfy the strict E.164 rule instead of being dropped. The
	 * country code may be an ISO-3166 alpha-2 country (e.g. "US") or a calling
	 * code (e.g. "1" / "+1"); WooCommerce, when active, resolves the country to
	 * its calling code.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $phone        Raw phone value.
	 * @param string      $country_hint Optional ISO country or calling code used
	 *                                  to complete plus-less numbers.
	 *
	 * @return string|null E.164 value, or null when it cannot be validated.
	 */
	public static function to_e164( $phone, $country_hint = '' ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return null;
		}

		// Already international: clean + validate it directly.
		if ( '+' === substr( $phone, 0, 1 ) ) {
			return self::sanitize( $phone );
		}

		$calling_code = self::resolve_calling_code( $country_hint );
		if ( '' === $calling_code ) {
			return null;
		}

		return self::sanitize( $phone, $calling_code );
	}

	/**
	 * Resolve a country/calling-code hint into a bare numeric calling code.
	 *
	 * Accepts an ISO-3166 alpha-2 country code (resolved via WooCommerce when
	 * available) or a raw calling code with/without a leading "+". Falls back to
	 * the WooCommerce store base country, then the
	 * doublescale_default_calling_code filter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hint Country or calling code hint.
	 *
	 * @return string Digits-only calling code, or '' when none can be determined.
	 */
	public static function resolve_calling_code( $hint = '' ) {
		$hint = trim( (string) $hint );

		// A two-letter token is treated as an ISO country code.
		if ( 2 === strlen( $hint ) && ctype_alpha( $hint ) ) {
			$code = self::country_to_calling_code( strtoupper( $hint ) );
			if ( '' !== $code ) {
				return $code;
			}
		} elseif ( '' !== $hint ) {
			// Otherwise treat it as a calling code (strip "+" and non-digits).
			$code = preg_replace( '/[^0-9]/', '', $hint );
			if ( '' !== $code ) {
				return $code;
			}
		}

		// Fall back to the WooCommerce store base country.
		$base_code = '';
		if ( function_exists( 'wc_get_base_location' ) ) {
			$base    = wc_get_base_location();
			$country = ( is_array( $base ) && ! empty( $base['country'] ) ) ? $base['country'] : '';
			if ( '' !== $country ) {
				$base_code = self::country_to_calling_code( $country );
			}
		}

		/**
		 * Filter the default calling code (digits only, no "+") used to complete
		 * national phone numbers into E.164 when writing whatsapp_phone.
		 *
		 * @since 1.0.0
		 *
		 * @param string $base_code Derived calling code (e.g. "1", "44"), or '' if none.
		 */
		$base_code = apply_filters( 'doublescale_default_calling_code', $base_code );

		// Backwards-compatible alias for the previous, action-specific filter name.
		$base_code = apply_filters( 'doublescale_update_contact_default_calling_code', $base_code );

		return preg_replace( '/[^0-9]/', '', (string) $base_code );
	}

	/**
	 * Resolve an ISO-3166 alpha-2 country code to its calling code (digits only).
	 *
	 * @since 1.0.0
	 *
	 * @param string $country ISO alpha-2 country code (e.g. "US").
	 *
	 * @return string Digits-only calling code, or '' when unavailable.
	 */
	private static function country_to_calling_code( $country ) {
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
			$code = WC()->countries->get_country_calling_code( $country );
			return preg_replace( '/[^0-9]/', '', (string) $code );
		}

		return '';
	}

	/**
	 * Get E.164 format description for user-facing messages
	 *
	 * @since 1.0.0
	 * @return string Human-readable format description
	 */
	public static function get_format_description() {
		return __( 'E.164 format: +[country code][number] (e.g., +1234567890)', 'doublescale' );
	}

	/**
	 * Get E.164 example phone number
	 *
	 * @since 1.0.0
	 * @param string $country Optional country code (us, uk, fr, etc.)
	 * @return string Example phone number
	 */
	public static function get_example( $country = 'us' ) {
		$examples = array(
			'us' => '+1234567890',
			'uk' => '+447975777666',
			'fr' => '+33612345678',
			'de' => '+4915123456789',
			'ca' => '+14165551234',
			'au' => '+61412345678',
		);

		return $examples[ strtolower( $country ) ] ?? $examples['us'];
	}
}
