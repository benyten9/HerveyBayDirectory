<?php
/**
 * Class Settings
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings Class
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Option name where to store all settings
	 *
	 * @since 1.0.0
	 */
	const OPTION_NAME = 'doublescale_settings';

	/**
	 * Get a setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = false ) {
		$settings = self::get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update a setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 * @return boolean
	 */
	public static function update( $key, $value ) {
		return self::update_many( array( $key => $value ) );
	}

	/**
	 * Delete a setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key.
	 * @return boolean
	 */
	public static function delete( $key ) {
		$settings = self::get_all();
		unset( $settings[ $key ] );
		return self::update_all( $settings );
	}

	/**
	 * Get all settings
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_all() {
		return get_option( self::OPTION_NAME, array() );
	}

	/**
	 * Update many settings
	 *
	 * @since 1.0.0
	 *
	 * @param array $new_settings New settings.
	 * @return boolean
	 */
	public static function update_many( $new_settings ) {
		$old_settings = self::get_all();

		// Ensure both old and new settings are arrays to avoid type errors.
		if ( ! is_array( $old_settings ) ) {
			$old_settings = array();
		}

		if ( ! is_array( $new_settings ) ) {
			$new_settings = array();
		}

		$settings = array_replace( $old_settings, $new_settings );
		return self::update_all( $settings );
	}

	/**
	 * Update all settings
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Settings.
	 * @return boolean
	 */
	public static function update_all( $settings ) {
		update_option( 'doublescale_flush_rewrite_rules', 1 );
		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Delete all settings
	 *
	 * @since 1.0.0
	 *
	 * @return boolean
	 */
	public static function delete_all() {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Get default email footer
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_default_email_footer() {
		return "<p>Don't want to stay in the loop? We'll be sad to see you go, but you can click here to <a href='{{contact:unsubscribe_link}}'>unsubscribe</a>.</p>";
	}

	/**
	 * Get default opt-in email subject
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_default_opt_in_subject() {
		return 'Please Confirm Your Subscription';
	}

	/**
	 * Get default opt-in email content
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_default_opt_in_content() {
		return '<p>Please confirm your subscription by clicking the link below:</p><p><a href="{{contact:subscribe_link}}">Confirm Subscription</a></p>';
	}

	/**
	 * Get default confirmation message
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_default_confirmation_message() {
		return 'Thank you for confirming your subscription!';
	}

	/**
	 * Get global currency setting
	 *
	 * @since 1.0.0
	 *
	 * @return string Currency code (e.g., 'USD', 'EUR')
	 */
	public static function get_currency() {
		$currency_settings = self::get( 'currency', array() );
		return isset( $currency_settings['currency'] ) ? $currency_settings['currency'] : 'USD';
	}

	/**
	 * First day of the week for CRM calendars (date-fns weekStartsOn).
	 *
	 * 0 = Sunday, 1 = Monday, … 6 = Saturday. Defaults to Monday.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function get_calendar_week_starts_on() {
		$calendar = self::get( 'calendar', array() );
		if ( ! is_array( $calendar ) ) {
			return 1;
		}

		$day = isset( $calendar['week_starts_on'] ) ? (int) $calendar['week_starts_on'] : 1;
		if ( $day < 0 || $day > 6 ) {
			return 1;
		}

		return $day;
	}

	/**
	 * Resolve the currency to display for a sales document.
	 *
	 * Currency is a single global setting (like deals), but once a document has
	 * been sent to the customer its currency is frozen to whatever was stored at
	 * that time — changing the global currency afterwards must not retroactively
	 * relabel money the customer has already seen or paid. Documents that have
	 * not been sent yet (drafts) always follow the current global currency.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $stored_currency Currency stored on the record.
	 * @param mixed       $sent_at         Truthy once the document was sent (e.g. `sent_at` timestamp).
	 * @return string Currency code (e.g., 'USD', 'EUR').
	 */
	public static function document_currency( $stored_currency, $sent_at ) {
		if ( ! empty( $sent_at ) && ! empty( $stored_currency ) ) {
			return (string) $stored_currency;
		}
		return self::get_currency();
	}

	/**
	 * Resolve the currency to display for a deal.
	 *
	 * Unlinked deals keep a NULL currency column and always follow the global
	 * settings currency. Once a deal is linked to a proposal or invoice the
	 * stored value is frozen so later settings changes cannot relabel it.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $stored_currency Currency stored on the deal (NULL = follow global).
	 * @return string Currency code (e.g., 'USD', 'EUR').
	 */
	public static function deal_currency( $stored_currency ) {
		if ( ! empty( $stored_currency ) ) {
			return (string) $stored_currency;
		}
		return self::get_currency();
	}

	/**
	 * Encrypt a value using AES-256-CBC with SECURE_AUTH_KEY.
	 *
	 * @param string $value Plaintext value.
	 * @return string Base64-encoded ciphertext, or empty string if input is empty.
	 */
	public static function encrypt_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		$key       = hash( 'sha256', SECURE_AUTH_KEY, true );
		$iv        = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, 0, $iv );
		// 16-byte IV prefix + ciphertext (no delimiter — IV bytes can be 0x3A).
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a value previously produced by {@see encrypt_value()}.
	 *
	 * Returns the raw value unchanged when the input is not a well-formed
	 * encrypted payload (e.g. user-supplied plaintext via filters), so callers
	 * do not need to defend against malformed input.
	 *
	 * @param string $value Possibly-encrypted value.
	 * @return string Decrypted plaintext, or the raw input when decryption is not applicable.
	 */
	public static function decrypt_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		$decoded = base64_decode( $value, true );
		if ( false === $decoded || strlen( $decoded ) < 17 ) {
			return $value;
		}

		$key = hash( 'sha256', SECURE_AUTH_KEY, true );

		// Legacy payloads: 16-byte IV + literal "::" + ciphertext.
		if ( strlen( $decoded ) >= 18 && '::' === substr( $decoded, 16, 2 ) ) {
			$decrypted = openssl_decrypt(
				substr( $decoded, 18 ),
				'aes-256-cbc',
				$key,
				0,
				substr( $decoded, 0, 16 )
			);
			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		// Current payloads: 16-byte IV prefix + ciphertext.
		$decrypted = openssl_decrypt(
			substr( $decoded, 16 ),
			'aes-256-cbc',
			$key,
			0,
			substr( $decoded, 0, 16 )
		);

		return false === $decrypted ? $value : $decrypted;
	}
}
