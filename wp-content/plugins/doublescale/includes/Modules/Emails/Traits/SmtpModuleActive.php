<?php
/**
 * Bundled SMTP module detection for campaign senders
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails\Traits
 */

namespace DoubleScale\Modules\Emails\Traits;

defined( 'ABSPATH' ) || exit;

trait SmtpModuleActive {

	/**
	 * Settings class that implements smart routing (bundled SMTP module).
	 *
	 * @return string|null Fully qualified class name.
	 */
	public static function get_smtp_settings_class() {
		if ( class_exists( '\DoubleScale\Modules\Smtp\Settings' ) ) {
			return '\DoubleScale\Modules\Smtp\Settings';
		}
		return null;
	}

	/**
	 * Smart route array from the active SMTP settings backend, or null.
	 *
	 * @param string|null $from_email Optional From address for routing.
	 * @return array<string, mixed>|null
	 */
	public static function get_smtp_smart_route( $from_email = null ) {
		$settings_class = self::get_smtp_settings_class();
		if ( ! $settings_class || ! method_exists( $settings_class, 'get_smart_route' ) ) {
			return null;
		}
		return call_user_func( array( $settings_class, 'get_smart_route' ), $from_email );
	}

	/**
	 * First routed connection (default then fallback) whose mailer slug exists in $allowed.
	 *
	 * @param array<string, mixed> $route   Result of {@see self::get_smtp_smart_route()}.
	 * @param array<string, true>  $allowed Mailer slug => true.
	 * @return string|null Mailer slug.
	 */
	public static function pick_routed_mailer_slug( array $route, array $allowed ) {
		foreach ( array( 'default_connection', 'fallback_connection' ) as $field ) {
			$connection = $route[ $field ] ?? null;
			if ( empty( $connection ) || ! is_array( $connection ) ) {
				continue;
			}
			$mailer = isset( $connection['mailer'] ) ? (string) $connection['mailer'] : '';
			if ( $mailer !== '' && isset( $allowed[ $mailer ] ) ) {
				return $mailer;
			}
		}
		return null;
	}

	/**
	 * Whether the bundled SMTP module is present.
	 *
	 * @return bool
	 */
	public static function is_smtp_module_active() {
		return class_exists( '\DoubleScale\Modules\Smtp\Settings' )
			|| class_exists( '\DoubleScale\Modules\Smtp\Module' );
	}

	/**
	 * Whether Pro-tier mailer code (e.g. AWS SES) is available in the bundled module.
	 *
	 * @return bool
	 */
	public static function is_smtp_pro_mailers_active() {
		if ( ! self::is_smtp_module_active() ) {
			return false;
		}
		return class_exists( '\DoubleScale\Modules\Smtp\Providers\Aws\Accounts' );
	}

	/**
	 * Whether the bundled SMTP module meets minimum requirements (always true when loaded).
	 *
	 * @return bool
	 */
	public static function is_smtp_module_version_supported() {
		return self::is_smtp_module_active();
	}

	/**
	 * Whether Pro mailer code is available in the bundled module.
	 *
	 * @return bool
	 */
	public static function is_smtp_pro_mailers_version_supported() {
		return class_exists( '\DoubleScale\Modules\Smtp\Providers\Aws\Accounts' );
	}
}
