<?php
/**
 * Email Individual Message Sender
 * Handles sending individual email messages to contacts
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\IndividualMessaging;

use WP_Error;
use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractIndividualMessageSender;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Email as Email_Tracking;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Emails\EmailTrackingHelper;
use DoubleScale\Pro\Modules\Inbox\Oauth\EmailOauth;
use DoubleScale\Pro\Settings;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Pro\Core\Communication\EmailIdentityResolver;

/**
 * EmailIndividualSender class
 *
 * Concrete implementation for email individual message sending.
 * Extends abstract base class with email-specific validation and sending logic.
 *
 * @since 1.0.0
 */
class EmailIndividualSender extends AbstractIndividualMessageSender {

	/**
	 * Which email identity to use: 'personal' or 'shared'.
	 *
	 * When 'personal', sends from the user's connected personal email account.
	 * When 'shared', sends from the global CRM email settings.
	 * When null, auto-detects (personal if available, otherwise shared).
	 *
	 * @since 1.6.1
	 *
	 * @var string|null
	 */
	protected $from_account = null;

	/**
	 * Get channel type
	 *
	 * @since 1.0.0
	 *
	 * @return string Channel type
	 */
	protected function get_channel_type() {
		return CampaignChannel::STR_EMAIL;
	}

	/**
	 * Get activity type
	 *
	 * @since 1.0.0
	 *
	 * @return string Activity type
	 */
	protected function get_activity_type() {
		return 'email_sent';
	}

	/**
	 * Get tracking mode
	 *
	 * @since 1.0.0
	 *
	 * @return int Tracking mode constant
	 */
	protected function get_tracking_mode() {
		return CommunicationTrackingModel::MODE_EMAIL;
	}

	/**
	 * Get tracking class
	 *
	 * @since 1.0.0
	 *
	 * @return string Tracking class name
	 */
	protected function get_tracking_class() {
		return Email_Tracking::class;
	}

	/**
	 * Validate recipient email address
	 *
	 * @since 1.0.0
	 *
	 * @param string $recipient Email address to validate
	 * @return true|WP_Error True if valid, WP_Error if invalid
	 */
	protected function validate_recipient( $recipient ) {
		if ( ! filter_var( $recipient, FILTER_VALIDATE_EMAIL ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Invalid email address', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate email-specific requirements
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return true|WP_Error True if valid, WP_Error if invalid
	 */
	protected function validate_email_requirements( $request ) {
		$subject = $request->get_param( 'subject' );

		if ( empty( $subject ) || ! trim( $subject ) ) {
			return new WP_Error(
				'missing_subject',
				__( 'Subject is required for email messages.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Send email with subject validation
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|WP_Error
	 */
	public function send( $request ) {
		// Validate email-specific requirements
		$validation = $this->validate_email_requirements( $request );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Read optional from_account preference ('personal' or 'shared').
		$this->from_account = $request->get_param( 'from_account' ) ?? null;

		// Call parent send logic
		return parent::send( $request );
	}

	/**
	 * Override add_tracking_elements to add email-specific tracking (pixel + click tracking)
	 *
	 * Adds tracking pixel and click tracking URLs for sending.
	 * These elements are NEVER stored in activity - only used for sending.
	 *
	 * @since 1.0.0
	 *
	 * @param string                     $message        Processed message (merge tags already resolved)
	 * @param ContactModel               $contact        Contact model
	 * @param CommunicationTrackingModel $tracking_entry Tracking record
	 * @return string Message with tracking elements added
	 */
	protected function add_tracking_elements( $message, $contact, $tracking_entry ) {
		// Add tracking pixel (email-specific)
		$message = EmailTrackingHelper::add_tracking_pixel( $message, $tracking_entry );

		// Add click tracking to all links
		$message = EmailTrackingHelper::add_click_tracking( $message, $tracking_entry->hash_key, $contact );

		return $message;
	}


	// COMMENTED OUT: Role-based validation - pending product owner decision
	// /**
	// * Check if current user is a sales rep
	// *
	// * @since 1.0.0
	// *
	// * @return bool True if user has sales rep role, false otherwise
	// */
	// protected function is_sales_rep() {
	// $user = wp_get_current_user();
	// if ( ! $user || ! $user->ID ) {
	// return false;
	// }
	//
	// return in_array( \DoubleScale\Core\UserRoles\UserRoles::SALES_REP, (array) $user->roles, true );
	// }

	/**
	 * Override send_via_provider to use WordPress email system
	 *
	 * Email uses wp_mail directly rather than the MessageProviderInterface pattern
	 * used by Sms/Whatsapp. This is because email sending is handled by WordPress core
	 * and various SMTP plugins, while Sms/Whatsapp require third-party Api providers.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed        $provider Not used for email (null)
	 * @param string       $to Recipient email address
	 * @param string       $body Processed message body
	 * @param string|null  $subject Processed subject
	 * @param ContactModel $contact Contact model
	 * @return array Provider result
	 */
	protected function send_via_provider( $provider, $to, $body, $subject, $contact ) {
		try {
			// Note: smtp connection validation removed - now handled as warning in settings.
			// Email will attempt to send using WordPress default mail system if no SMTP configured.

			$emails = new Emails();

			// Resolve sender identity via the shared resolver.
			// `from_account = 'shared'` forces the shared/admin chain (skip personal lookup).
			// Otherwise the current user's personal mailbox is preferred when enabled.
			$current_user = wp_get_current_user();
			$host_id      = ( 'shared' !== $this->from_account && $current_user && $current_user->ID )
				? (int) $current_user->ID
				: null;
			$identity     = EmailIdentityResolver::resolve( $host_id );

			$emails->from_address = $identity['from_address'];
			$emails->from_name    = $identity['from_name'];
			$emails->reply_to     = $identity['reply_to'];

			// Generate a unique Message-ID before sending.
			// This ID is set on the Emails class (which passes it to PHPMailer via phpmailer_init hook)
			// and returned in the result array. The parent's handle_result() stores it as external_id
			// on the Communication_Tracking record, enabling reply matching for incoming emails.
			$message_id         = '<' . md5( uniqid( wp_rand(), true ) ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . '>';
			$emails->message_id = $message_id;

			// Set threading headers when replying to an existing email.
			if ( ! empty( $this->in_reply_to ) ) {
				$emails->in_reply_to = $this->in_reply_to;
			}

			// Send the email
			$result = $emails->send( $to, $subject, $body );

			// Validate email send result
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => 'WP Mail Error: ' . $result->get_error_message(),
				);
			} elseif ( $result === false || $result === null ) {
				// Build a helpful error message that guides the user.
				$error  = __( 'Email sending failed.', 'doublescale' );
				$detail = Emails::get_last_send_failure_detail();

				if ( ! EmailOauth::smtp_settings_class() ) {
					$error .= ' ' . __( 'No SMTP backend is available. Ensure the DoubleScale SMTP module is enabled, or install and configure SMTP.', 'doublescale' );
				} else {
					$connection = EmailOauth::smtp_get_connection_by_from_email( $emails->from_address );
					if ( empty( $connection ) ) {
						$error .= ' ' . sprintf(
							/* translators: %s: the from email address */
							__( 'No SMTP connection is configured for "%s". Add a connection in CRM SMTP settings whose From email matches this address.', 'doublescale' ),
							$emails->from_address
						);
					} else {
						$error .= ' ' . __( 'The SMTP connection may be misconfigured or the mail server rejected the message. Check your SMTP connection settings.', 'doublescale' );
					}
				}

				if ( '' !== $detail ) {
					$error .= ' ' . sprintf(
						/* translators: %s: technical detail from PHPMailer or WordPress */
						__( 'Details: %s', 'doublescale' ),
						$detail
					);
				}

				return array(
					'success' => false,
					'error'   => $error,
				);
			}

			// Return the generated Message-ID (not the wp_mail boolean result).
			// wp_mail() returns true/false, not a message ID string.
			return array(
				'success'    => true,
				'message_id' => $message_id,
				'from_email' => $emails->from_address,
				'from_name'  => $emails->from_name,
			);

		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
