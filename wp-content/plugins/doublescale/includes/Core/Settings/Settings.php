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
	 * Settings keys a settings screen's request may never write.
	 *
	 * Each of these has its own admin-only endpoint. The Booking and General
	 * settings endpoints merge whatever JSON they receive and are open to
	 * Booking and CRM Managers, so without this a non-admin could set
	 * `client_portal.role` to `administrator`, switch on automatic accounts and
	 * book with their own email to receive an administrator account.
	 * `client_ip_header` decides which address public rate limits count, so
	 * only a site administrator may change it.
	 *
	 * @var string[]
	 */
	const REQUEST_PROTECTED_KEYS = array( 'client_portal', 'client_ip_header' );

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
	 * Merge settings that came in a request: like update_many(), minus the
	 * keys only their own endpoints may write ({@see REQUEST_PROTECTED_KEYS}).
	 *
	 * @param array $new_settings Settings from the request body.
	 * @return boolean
	 */
	public static function update_from_request( $new_settings ) {
		if ( ! is_array( $new_settings ) ) {
			$new_settings = array();
		}

		foreach ( self::REQUEST_PROTECTED_KEYS as $key ) {
			unset( $new_settings[ $key ] );
		}

		return self::update_many( $new_settings );
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
	 * Whether website tracking is on. The Website Tracking module switch
	 * (Control Modules) decides; a site that never saved that switch keeps
	 * the choice it made in the old Settings → Website Tracking toggle (on
	 * by default). Pro's module follows the same rule.
	 *
	 * @return bool
	 */
	public static function is_website_tracking_enabled() {
		$modules = get_option( 'doublescale_enabled_modules', array() );
		$enabled = is_array( $modules ) && array_key_exists( 'websitetracking', $modules )
			? (bool) $modules['websitetracking']
			: self::website_tracking_setting_on();

		return (bool) apply_filters( 'doublescale_website_tracking_enabled', $enabled );
	}

	/**
	 * The old Settings → Website Tracking toggle (on unless it was switched
	 * off). Only read for sites that never saved the module switch.
	 *
	 * @return bool
	 */
	public static function website_tracking_setting_on() {
		$tracking = self::get( 'website_tracking', array() );
		$raw      = is_array( $tracking ) && isset( $tracking['enabled'] ) ? $tracking['enabled'] : true;
		$enabled  = filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return null === $enabled ? (bool) $raw : $enabled;
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
	 * A non-empty stored code is an explicit choice (or a value frozen on send).
	 * NULL/empty means inherit the current global setting. Freeze happens on the
	 * write path (send), so `$sent_at` is unused for resolution — kept in the
	 * signature because ~25 call sites pass it.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $stored_currency Currency stored on the record (NULL = inherit).
	 * @param mixed       $sent_at         Unused; freeze is on the write path.
	 * @return string Currency code (e.g., 'USD', 'EUR').
	 */
	public static function document_currency( $stored_currency, $sent_at ) {
		unset( $sent_at );
		if ( ! empty( $stored_currency ) ) {
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

	/**
	 * Whether incoming WhatsApp STOP-style keywords should auto-unsubscribe contacts.
	 *
	 * When disabled, use the Unsubscribe WhatsApp automation action instead.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public static function is_whatsapp_auto_keyword_unsubscribe_enabled() {
		$whatsapp = self::get( 'whatsapp', array() );

		if ( ! is_array( $whatsapp ) || ! array_key_exists( 'auto_keyword_unsubscribe', $whatsapp ) ) {
			return true;
		}

		return (bool) $whatsapp['auto_keyword_unsubscribe'];
	}
}
