<?php
/**
 * Client Portal → the welcome message under "Welcome back, {name}".
 *
 * Empty means the built-in line. Plain text only (line breaks kept), capped so
 * the portal header stays a header.
 *
 * Persisted under `doublescale_settings['client_portal']`, next to the keys
 * {@see PortalUserProvisioner} and {@see PortalUploadSettings} own; only this
 * class's key is written here.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * PortalWelcomeSettings.
 */
final class PortalWelcomeSettings {

	/** Settings key shared with the other client portal settings. */
	public const SETTINGS_KEY = 'client_portal';

	/** Longest message the header shows. */
	public const MAX_LENGTH = 300;

	/**
	 * The built-in line, used while the admin has not written their own.
	 *
	 * @return string
	 */
	public static function default_message(): string {
		return __( 'Everything is updated and aligned so you can focus on what matters most.', 'doublescale' );
	}

	/**
	 * The admin's own message, or '' for the built-in line.
	 *
	 * @return string
	 */
	public static function custom_message(): string {
		$raw = Settings::get( self::SETTINGS_KEY, array() );
		return is_array( $raw ) && isset( $raw['welcome_message'] ) ? (string) $raw['welcome_message'] : '';
	}

	/**
	 * What the portal shows.
	 *
	 * @return string
	 */
	public static function message(): string {
		$custom = self::custom_message();
		return '' !== $custom ? $custom : self::default_message();
	}

	/**
	 * Settings payload for the admin screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		return array(
			'welcome_message' => self::custom_message(),
			'default_message' => self::default_message(),
			'max_length'      => self::MAX_LENGTH,
		);
	}

	/**
	 * Save the message ('' restores the built-in line).
	 *
	 * @param array<string, mixed> $input `welcome_message`.
	 * @return array<string, mixed>
	 */
	public static function update( array $input ): array {
		$raw = Settings::get( self::SETTINGS_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$message = trim( sanitize_textarea_field( (string) ( $input['welcome_message'] ?? '' ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, self::MAX_LENGTH );
		} else {
			$message = substr( $message, 0, self::MAX_LENGTH );
		}

		$raw['welcome_message'] = $message;
		Settings::update( self::SETTINGS_KEY, $raw );

		return self::settings();
	}
}
