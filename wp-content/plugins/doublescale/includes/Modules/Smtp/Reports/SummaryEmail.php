<?php
/**
 * Weekly summary email (parity with SMTP Summary_Email).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Reports;

use DoubleScale\Modules\Smtp\EmailLog\EmailLogHandler;
use DoubleScale\Modules\Smtp\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and sends a weekly HTML summary of SMTP email log stats.
 */
final class SummaryEmail {

	private const HOOK = 'doublescale_smtp_summary_email';

	private const AS_GROUP = 'doublescale_smtp';

	/**
	 * Register scheduling and callback.
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
	}

	/**
	 * Schedule recurring weekly job when Action Scheduler is available.
	 */
	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, array(), self::AS_GROUP ) ) {
			return;
		}

		try {
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( self::wp_timezone_string() );
			$date = new \DateTime( 'next monday 2am', $tz );
		} catch ( \Exception $e ) {
			$date = new \DateTime( 'next monday 2am' );
		}

		as_schedule_recurring_action(
			$date->getTimestamp(),
			WEEK_IN_SECONDS,
			self::HOOK,
			array(),
			self::AS_GROUP
		);
	}

	/**
	 * Send weekly summary to site admin email.
	 */
	public static function send(): void {
		if ( Settings::get( 'disable_summary_email', false ) ) {
			return;
		}

		$email   = (string) get_option( 'admin_email', '' );
		$message = self::build_email();
		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			return;
		}

		wp_mail(
			$email,
			__( 'DoubleScale SMTP Weekly Summary', 'doublescale' ),
			$message,
			self::get_headers()
		);
	}

	/**
	 * Email headers.
	 *
	 * @return string
	 */
	private static function get_headers(): string {
		$admin_name  = get_option( 'blogname', '' );
		$admin_email = get_option( 'admin_email', '' );

		$headers  = 'From: ' . $admin_name . ' <' . $admin_email . ">\r\n";
		$headers .= 'Content-Type: ' . self::get_content_type() . "; charset=utf-8\r\n";

		/**
		 * Filters summary email headers.
		 *
		 * @param string $headers Headers string.
		 */
		return (string) apply_filters( 'doublescale_smtp_summary_email_headers', $headers );
	}

	/**
	 * Content-Type header value.
	 *
	 * @return string
	 */
	private static function get_content_type(): string {
		$content_type = 'text/html';

		/**
		 * Filters summary email content type.
		 *
		 * @param string $content_type MIME type.
		 */
		return (string) apply_filters( 'doublescale_smtp_summary_email_content_type', $content_type );
	}

	/**
	 * Build HTML body from template.
	 *
	 * @return string
	 */
	private static function build_email(): string {
		ob_start();
		$template = DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Smtp/Reports/templates/summary-email.php';
		/**
		 * Filters path to summary email PHP template.
		 *
		 * @param string $template Absolute path.
		 */
		$template = (string) apply_filters( 'doublescale_smtp_summary_email_template', $template );

		if ( is_readable( $template ) ) {
			include $template;
		}

		$message = (string) ob_get_clean();

		/**
		 * Filters summary email HTML body.
		 *
		 * @param string $message HTML.
		 */
		return (string) apply_filters( 'doublescale_smtp_summary_email_message', $message );
	}

	/**
	 * WordPress timezone string fallback when wp_timezone() is unavailable.
	 *
	 * @return string
	 */
	private static function wp_timezone_string(): string {
		if ( function_exists( 'wp_timezone_string' ) ) {
			return wp_timezone_string();
		}

		$timezone_string = get_option( 'timezone_string', '' );
		if ( $timezone_string ) {
			return $timezone_string;
		}

		$offset  = (float) get_option( 'gmt_offset', 0 );
		$hours   = (int) $offset;
		$minutes = ( $offset - $hours );

		$sign     = ( $offset < 0 ) ? '-' : '+';
		$abs_hour = abs( $hours );
		$abs_mins = abs( $minutes * 60 );

		return sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );
	}
}
