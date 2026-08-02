<?php
/**
 * Bulk Email Sender
 *
 * Sends campaign batches through the CRM SMTP module’s bulk-capable mailers (Mailgun,
 * SendGrid, AWS SES, etc.), using the same smart routing as transactional mail.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Bulkmailers\AbstractBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\MailgunBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\MailersendBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\SendinblueBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\SendgridBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\PostmarkBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\SparkpostBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\MailjetBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\ElasticemailBulkMailer;
use DoubleScale\Modules\Emails\Bulkmailers\AwsBulkMailer;
use DoubleScale\Modules\Emails\Traits\SmtpModuleActive;
use WP_Error;

/**
 * BulkEmailSender class
 *
 * @since 1.0.0
 */
class BulkEmailSender {

	use SmtpModuleActive;

	/**
	 * Registered bulk mailer classes
	 *
	 * @var array
	 */
	private static $mailer_classes = array(
		'mailgun'      => MailgunBulkMailer::class,
		'mailersend'   => MailersendBulkMailer::class,
		'sendinblue'   => SendinblueBulkMailer::class,
		'sendgrid'     => SendgridBulkMailer::class,
		'postmark'     => PostmarkBulkMailer::class,
		'sparkpost'    => SparkpostBulkMailer::class,
		'mailjet'      => MailjetBulkMailer::class,
		'elasticemail' => ElasticemailBulkMailer::class,
		'aws'          => AwsBulkMailer::class,
	);

	/**
	 * Cached mailer instance
	 *
	 * @var AbstractBulkMailer|null
	 */
	private static $mailer_instance = null;

	/**
	 * Slug for which {@see self::$mailer_instance} was constructed (invalidates cache when routing changes).
	 *
	 * @var string|null
	 */
	private static $cached_mailer_slug = null;

	/**
	 * Check if bulk sending is available
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return bool True if a bulk-capable mailer is active
	 */
	public static function is_available( $from_email = null ) {
		if ( ! self::is_smtp_module_active() ) {
			return false;
		}

		if ( ! self::is_smtp_module_version_supported() ) {
			return false;
		}

		$mailer = self::get_mailer_instance( $from_email );
		return $mailer !== null && $mailer->is_available();
	}

	/**
	 * Get the active bulk-capable mailer slug
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
	 * Get the active bulk mailer instance
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return AbstractBulkMailer|null Mailer instance or null if not available
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
	 * Get bulk mailer information
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Mailer info array or null if not available
	 */
	public static function get_bulk_mailer_info() {
		$mailer = self::get_mailer_instance();

		if ( ! $mailer ) {
			return null;
		}

		return array(
			'slug'              => $mailer->get_slug(),
			'max_batch_size'    => $mailer->get_max_batch_size(),
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
	 * Convert Plugin merge tags to mailer-specific recipient variable format
	 *
	 * This method auto-detects the active mailer and converts merge tags accordingly:
	 * - Mailgun: {{contact:first_name}} -> %recipient.first_name%
	 * - MailerSend: {{contact:first_name}} -> {{first_name}}
	 * - Sendinblue/Brevo: {{contact:first_name}} -> {{params.first_name}}
	 * - SendGrid: {{contact:first_name}} -> {{first_name}}
	 * - Postmark: {{contact:first_name}} -> {{first_name}}
	 * - SparkPost: {{contact:first_name}} -> {{first_name}}
	 * - Mailjet: {{contact:first_name}} -> {{var:first_name}}
	 * - ElasticEmail: {{contact:first_name}} -> {first_name}
	 * - AWS SES: {{contact:first_name}} -> {{first_name}}
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Content with CRM merge tags
	 *
	 * @return string Content with mailer-specific recipient variables
	 */
	public static function convert_merge_tags_to_recipient_variables( $content ) {
		$mailer = self::get_mailer_instance();

		if ( ! $mailer ) {
			return $content;
		}

		return $mailer->convert_merge_tags( $content );
	}

	/**
	 * Send batch of emails
	 *
	 * @since 1.0.0
	 *
	 * @param array $batch_data {
	 *     Batch email data.
	 *
	 *     @type string $subject             Email subject (with merge tags or recipient variables)
	 *     @type string $html                HTML body (with merge tags or recipient variables)
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
	 *     @type bool   $success      Whether the batch was sent successfully
	 *     @type string $message_id   Message ID from the mailer
	 *     @type int    $sent_count   Number of recipients in the batch
	 *     @type array  $failed       Array of failed recipients (if any)
	 * }
	 */
	public static function send_batch( $batch_data ) {
		// Extract from_email for smart routing
		$from_email = $batch_data['from_email'] ?? null;
		$mailer     = self::get_mailer_instance( $from_email );

		if ( ! $mailer ) {
			return new WP_Error(
				'no_bulk_mailer',
				__( 'No bulk-capable mailer is configured in the SMTP module for this sender.', 'doublescale' )
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
	 * Register a custom bulk mailer class
	 *
	 * Allows third-party plugins to register their own bulk mailers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug        Mailer slug identifier
	 * @param string $class_name  Fully qualified class name (must extend AbstractBulkMailer)
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
