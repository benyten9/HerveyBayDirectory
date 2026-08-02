<?php
/**
 * Curl Multi Email Sender
 *
 * Handles sending emails in parallel via cURL Multi for mailers that don't have
 * native bulk Api support but do have HTTP Api (e.g., SMTP2GO).
 *
 * This significantly improves performance compared to sending one email at a time
 * by sending multiple concurrent HTTP requests through the CRM SMTP module.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Curlmulti\AbstractCurlMultiMailer;
use DoubleScale\Modules\Emails\Curlmulti\Smtp2goCurlMultiMailer;
use DoubleScale\Modules\Emails\Traits\SmtpModuleActive;
use WP_Error;

/**
 * CurlMultiEmailSender class
 *
 * @since 1.0.0
 */
class CurlMultiEmailSender {

	use SmtpModuleActive;

	/**
	 * Registered curl multi mailer classes
	 *
	 * @var array
	 */
	private static $mailer_classes = array(
		'smtp2go' => Smtp2goCurlMultiMailer::class,
	);

	/**
	 * Cached mailer instance
	 *
	 * @var AbstractCurlMultiMailer|null
	 */
	private static $mailer_instance = null;

	/**
	 * Slug for which {@see self::$mailer_instance} was built.
	 *
	 * @var string|null
	 */
	private static $cached_mailer_slug = null;

	/**
	 * Check if curl multi sending is available (SMTP module or legacy stack, plus cURL).
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return bool True if a curl multi-capable mailer is active
	 */
	public static function is_available( $from_email = null ) {

		if ( ! self::is_smtp_module_active() ) {
			return false;
		}

		if ( ! self::is_smtp_module_version_supported() ) {
			return false;
		}

		// 3. Check if cURL extension is available
		if ( ! function_exists( 'curl_multi_init' ) ) {
			return false;
		}

		$mailer = self::get_mailer_instance( $from_email );
		return $mailer !== null && $mailer->is_available();
	}

	/**
	 * Get the active curl multi-capable mailer slug
	 *
	 * Uses SMTP module smart routing for the appropriate connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return string|null Mailer slug or null if not available
	 */
	public static function get_active_mailer_slug( $from_email = null ) {

		if ( ! self::is_smtp_module_active() || ! self::is_smtp_module_version_supported() ) {
			return null;
		}

		$route = self::get_smtp_smart_route( $from_email );
		if ( ! is_array( $route ) ) {
			return null;
		}

		return self::pick_routed_mailer_slug( $route, array_fill_keys( array_keys( self::$mailer_classes ), true ) );
	}

	/**
	 * Get the active curl multi mailer instance
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return AbstractCurlMultiMailer|null Mailer instance or null if not available
	 */
	public static function get_mailer_instance( $from_email = null ) {
		$mailer_slug = self::get_active_mailer_slug( $from_email );

		if ( ! $mailer_slug || ! isset( self::$mailer_classes[ $mailer_slug ] ) ) {
			self::$mailer_instance    = null;
			self::$cached_mailer_slug = null;
			return null;
		}

		if ( self::$mailer_instance !== null && self::$cached_mailer_slug === $mailer_slug ) {
			if ( $from_email !== null ) {
				self::$mailer_instance->set_from_email( $from_email );
			}
			return self::$mailer_instance;
		}

		$mailer_class             = self::$mailer_classes[ $mailer_slug ];
		self::$mailer_instance    = new $mailer_class();
		self::$cached_mailer_slug = $mailer_slug;

		if ( $from_email !== null ) {
			self::$mailer_instance->set_from_email( $from_email );
		}

		return self::$mailer_instance;
	}

	/**
	 * Get curl multi mailer information
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Mailer info array or null if not available
	 */
	public static function get_curl_multi_mailer_info() {
		$mailer = self::get_mailer_instance();

		if ( ! $mailer ) {
			return null;
		}

		return array(
			'slug'              => $mailer->get_slug(),
			'max_batch_size'    => $mailer->get_max_batch_size(),
			'max_concurrent'    => $mailer->get_max_concurrent(),
			'supports_tracking' => $mailer->supports_tracking(),
		);
	}

	/**
	 * Get maximum batch size for current mailer
	 *
	 * @since 1.0.0
	 *
	 * @return int Maximum batch size (default 100 if unknown)
	 */
	public static function get_max_batch_size() {
		$mailer = self::get_mailer_instance();

		if ( ! $mailer ) {
			return 100; // Safe default
		}

		return $mailer->get_max_batch_size();
	}

	/**
	 * Get maximum concurrent requests for current mailer
	 *
	 * @since 1.0.0
	 *
	 * @return int Maximum concurrent requests (default 10 if unknown)
	 */
	public static function get_max_concurrent() {
		$mailer = self::get_mailer_instance();

		if ( ! $mailer ) {
			return 10; // Safe default
		}

		return $mailer->get_max_concurrent();
	}

	/**
	 * Process merge tags for curl multi (personalize content)
	 *
	 * Unlike BulkEmailSender which converts merge tags to mailer format,
	 * curl multi processes merge tags by replacing them with actual values
	 * since each email is personalized individually.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content   Content with CRM merge tags
	 * @param array  $variables Variables to replace
	 *
	 * @return string Content with replaced variables
	 */
	public static function process_merge_tags( $content, $variables ) {
		$mailer = self::get_mailer_instance();

		if ( ! $mailer ) {
			return $content;
		}

		return $mailer->process_merge_tags( $content, $variables );
	}

	/**
	 * Send batch of emails
	 *
	 * @since 1.0.0
	 *
	 * @param array $batch_data {
	 *     Batch email data.
	 *
	 *     @type string $subject             Email subject (with merge tags)
	 *     @type string $html                HTML body (with merge tags)
	 *     @type string $text                Plain text body (optional)
	 *     @type string $from_email          Sender email address
	 *     @type string $from_name           Sender name
	 *     @type string $reply_to            Reply-to address (optional)
	 *     @type array  $recipients          Array of recipient email addresses
	 *     @type array  $recipient_variables Associative array keyed by email with variable values
	 *     @type array  $tags                Tags for tracking (optional)
	 *     @type int    $campaign_id         Campaign ID for logging (optional)
	 * }
	 *
	 * @return array|WP_Error {
	 *     Result array or WP_Error on failure.
	 *
	 *     @type bool   $success      Whether all emails were sent successfully
	 *     @type int    $sent_count   Number of successfully sent emails
	 *     @type array  $failed       Array of failed recipients with errors
	 *     @type array  $message_ids  Array of message IDs (email => id)
	 * }
	 */
	public static function send_batch( $batch_data ) {
		// Extract from_email for smart routing
		$from_email = $batch_data['from_email'] ?? null;
		$mailer     = self::get_mailer_instance( $from_email );

		if ( ! $mailer ) {
			return new WP_Error(
				'no_curl_multi_mailer',
				__( 'No cURL Multi-capable mailer is configured in the SMTP module for this sender.', 'doublescale' )
			);
		}

		if ( ! $mailer->is_available() ) {
			return new WP_Error(
				'mailer_not_available',
				sprintf(
					/* translators: %s: mailer name */
					__( '%s is not properly configured.', 'doublescale' ),
					ucfirst( $mailer->get_slug() )
				)
			);
		}

		return $mailer->send_batch( $batch_data );
	}

	/**
	 * Register a custom curl multi mailer class
	 *
	 * Allows third-party plugins to register their own curl multi mailers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug        Mailer slug identifier
	 * @param string $class_name  Fully qualified class name (must extend AbstractCurlMultiMailer)
	 *
	 * @return bool True if registered successfully, false if slug already exists
	 */
	public static function register_mailer( $slug, $class_name ) {
		if ( isset( self::$mailer_classes[ $slug ] ) ) {
			return false;
		}

		self::$mailer_classes[ $slug ] = $class_name;

		// Clear cached instance if we're registering a new mailer
		self::$mailer_instance = null;

		return true;
	}

	/**
	 * Get list of supported mailer slugs
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of mailer slugs
	 */
	public static function get_supported_mailers() {
		return array_keys( self::$mailer_classes );
	}

	/**
	 * Reset cached mailer instance
	 *
	 * Useful for testing or when mailer configuration changes.
	 *
	 * @since 1.0.0
	 */
	public static function reset() {
		self::$mailer_instance    = null;
		self::$cached_mailer_slug = null;
	}
}
