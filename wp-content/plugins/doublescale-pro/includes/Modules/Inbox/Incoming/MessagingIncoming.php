<?php
/**
 * Generic Messaging Incoming Webhook Handler
 * Provider-agnostic handler for incoming Sms/Whatsapp messages
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Incoming;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\Validators\PhoneValidator;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * MessagingIncoming class
 *
 * Handles incoming message webhooks from ANY provider (Twilio, Vonage, AWS SNS, etc.)
 * Uses MessageProviderRegistry for provider-agnostic processing.
 */
class MessagingIncoming {

	/**
	 * Singleton instance
	 *
	 * @var MessagingIncoming|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return MessagingIncoming
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks
	 */
	private function register_hooks() {
		// Generic incoming message endpoint (supports any provider).
		add_action( 'wp_ajax_nopriv_doublescale_message_incoming', array( $this, 'handle_incoming_message' ) );
		add_action( 'wp_ajax_doublescale_message_incoming', array( $this, 'handle_incoming_message' ) );

		// Listen for incoming messages from channel-specific webhooks (Meta WhatsApp, etc.).
		add_action( 'doublescale_inbox_incoming_message_process', array( $this, 'process_incoming_from_webhook' ), 10, 3 );
	}

	/**
	 * Process incoming message from channel webhook (e.g., WhatsApp webhook)
	 * Called via doublescale_inbox_incoming_message_process action.
	 *
	 * @param array  $parsed_data Parsed message data from provider.
	 * @param string $channel     Channel type (sms, whatsapp).
	 * @param object $provider    Provider instance.
	 * @return void
	 */
	public function process_incoming_from_webhook( $parsed_data, $channel, $provider ) {
		$this->process_incoming_message( $parsed_data, $channel );
	}

	/**
	 * Get webhook URL for a specific channel
	 *
	 * @param string $channel Channel type (sms, whatsapp)
	 * @return string Webhook URL
	 */
	public static function get_webhook_url( $channel = 'sms' ) {
		return admin_url( 'admin-ajax.php?action=doublescale_message_incoming&channel=' . $channel );
	}

	/**
	 * Handle incoming message webhook (provider-agnostic)
	 */
	public function handle_incoming_message() {
		// Get channel from query param
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$channel = isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : 'sms';

		// Get active provider for this channel
		$provider = MessageProviderRegistry::instance()->get_provider( $channel );

		if ( ! $provider ) {
			doublescale_get_logger()->error(
				'Incoming message webhook: no provider configured',
				array(
					'code'    => 'messaging_incoming_no_provider',
					'channel' => $channel,
				)
			);
			$this->send_error_response();
			return;
		}

		// Get full URL for signature verification
		// Build URL from scheme + host + request URI to avoid double path issues
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$scheme      = is_ssl() ? 'https' : 'http';
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$url         = $scheme . '://' . $host . $request_uri;

		// Verify webhook signature using provider
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$webhook_payload = $this->get_webhook_payload();
		$signature_valid = $provider->verify_webhook_signature( $_SERVER, $webhook_payload, $url );

		if ( ! $signature_valid ) {
			// In dev mode, allow through (ngrok URLs can cause signature mismatches)
			if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				doublescale_get_logger()->info(
					'Incoming message webhook: signature verification failed',
					array(
						'code'     => 'messaging_incoming_signature_failed',
						'channel'  => $channel,
						'provider' => get_class( $provider ),
					)
				);
				$provider->send_webhook_response();
				return;
			}
		}

		// Parse webhook data using provider (Meta sends JSON body, Twilio sends form POST).
		$parsed_data = $provider->parse_incoming_webhook( $webhook_payload );

		// Validate required fields
		if ( empty( $parsed_data['from_number'] ) || empty( $parsed_data['message_id'] ) ) {
			doublescale_get_logger()->info(
				'Incoming message webhook: missing required fields',
				array(
					'code'    => 'messaging_incoming_missing_fields',
					'channel' => $channel,
					'data'    => $parsed_data,
				)
			);
			$provider->send_webhook_response();
			return;
		}

		// Process the message (provider-agnostic)
		$this->process_incoming_message( $parsed_data, $channel );

		// Send response using provider
		$provider->send_webhook_response();
	}

	/**
	 * Process incoming message (provider-agnostic logic)
	 *
	 * @param array  $data Parsed message data
	 * @param string $channel Channel type (sms, whatsapp)
	 * @return array|false Processing result
	 */
	private function process_incoming_message( $data, $channel ) {
		// Find or create contact (pass channel for proper field lookup)
		$contact = $this->find_or_create_contact( $data['from_number'], $channel );

		if ( ! $contact ) {
			doublescale_get_logger()->error(
				'Incoming message: failed to find or create contact',
				array(
					'code'        => 'messaging_incoming_contact_failed',
					'from_number' => $data['from_number'],
					'channel'     => $channel,
				)
			);
			return false;
		}

		$tracking_mode = $this->get_tracking_mode_for_channel( $channel );
		$activity      = null;
		$tracking      = null;

		// Reuse existing inbound record when Meta retries the same message_id.
		$existing_tracking = CommunicationTrackingModel::where( 'external_id', $data['message_id'] )
			->where( 'mode', $tracking_mode )
			->where( 'direction', MessageDirection::INBOUND )
			->first();

		if ( $existing_tracking ) {
			$tracking = $existing_tracking;
			if ( ! empty( $existing_tracking->source_id ) ) {
				$activity = ActivityModel::find( $existing_tracking->source_id );
			}

			doublescale_get_logger()->debug(
				'Incoming message already stored, processing keywords only',
				array(
					'code'        => 'messaging_incoming_duplicate',
					'channel'     => $channel,
					'contact_id'  => $contact->id,
					'message_id'  => $data['message_id'],
					'tracking_id' => $existing_tracking->id,
				)
			);
		} else {
			$activity_type = $channel . '_received';

			try {
				$activity = ActivityModel::create(
					array(
						'contact_id'    => $contact->id,
						'activity_type' => $activity_type,
						'data'          => array(
							'body'       => $data['message_body'],
							'from'       => $data['from_number'],
							'to'         => $data['to_number'],
							'media_urls' => $data['media_urls'] ?? array(),
						),
						'user_id'       => null,
					)
				);

				$tracking = CommunicationTrackingModel::create(
					array(
						'contact_id'  => $contact->id,
						'mode'        => $tracking_mode,
						'direction'   => MessageDirection::INBOUND,
						'source_type' => MessageSourceTypes::INDIVIDUAL,
						'source_id'   => $activity->id,
						'recipient'   => $data['to_number'],
						'external_id' => $data['message_id'],
						'hash_key'    => \DoubleScale\Pro\Utils::generate_hash_key(),
						'status'      => TrackingStatus::DELIVERED,
						'sent_at'     => current_time( 'mysql', true ),
					)
				);

				doublescale_get_logger()->info(
					'Incoming message received and stored',
					array(
						'code'        => 'messaging_incoming_success',
						'channel'     => $channel,
						'contact_id'  => $contact->id,
						'activity_id' => $activity->id,
						'tracking_id' => $tracking->id,
					)
				);
			} catch ( \Exception $e ) {
				$existing_tracking = CommunicationTrackingModel::where( 'external_id', $data['message_id'] )
					->where( 'mode', $tracking_mode )
					->where( 'direction', MessageDirection::INBOUND )
					->first();

				if ( $existing_tracking ) {
					$tracking = $existing_tracking;
					if ( ! empty( $existing_tracking->source_id ) ) {
						$activity = ActivityModel::find( $existing_tracking->source_id );
					}
				} else {
					doublescale_get_logger()->error(
						'Failed to store incoming message',
						array(
							'code'       => 'messaging_incoming_store_failed',
							'channel'    => $channel,
							'contact_id' => $contact->id,
							'message_id' => $data['message_id'],
							'error'      => $e->getMessage(),
						)
					);
				}
			}
		}

		// Always process subscription keywords (STOP/START), even on webhook retries.
		$keyword_result = $this->handle_subscription_keywords( $contact, $data['message_body'], $channel );

		if ( $activity && $tracking ) {
			/**
		 * Fires when an Sms or WhatsApp message is received.
		 *
		 * Hook names: doublescale_sms_received, doublescale_whatsapp_received
		 *
		 * @since 1.0.0
		 *
		 * @param \DoubleScale\Modules\Contacts\Models\ContactModel                $contact  Contact who sent the message.
		 * @param \DoubleScale\Modules\Activities\Models\ActivityModel               $activity Activity record created for this message.
		 * @param \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel $tracking Tracking record for delivery status.
		 * @param array                                         $message_data {
		 *     Parsed message data from the provider.
		 *
		 *     @type string   $from_number  Sender's phone number (with + prefix).
		 *     @type string   $to_number    Recipient phone number (your business number).
		 *     @type string   $message_body The text content of the message.
		 *     @type string   $message_id   External provider message ID.
		 *     @type string[] $media_urls   Array of media attachment URLs (images, videos, etc.).
		 * }
		 */
		do_action( "doublescale_{$channel}_received", $contact, $activity, $tracking, $data );
		}

		// Fire resubscribe action (unsubscribe action is fired by unsubscribe_from_mode)
		if ( $keyword_result && 'subscribed' === $keyword_result['action'] ) {
			do_action( "doublescale_{$channel}_resubscribed", $contact, $data );
		}

		return array(
			'contact'        => $contact,
			'activity'       => $activity,
			'tracking'       => $tracking,
			'keyword_action' => $keyword_result,
		);
	}

	/**
	 * Find contact by phone number or create new one
	 *
	 * Channel-specific lookup strategy:
	 * - WhatsApp: ONLY searches whatsapp_phone field (strict matching)
	 * - Sms: Searches phone field
	 *
	 * Uses indexed queries for performance.
	 *
	 * @param string $phone_number Phone number
	 * @param string $channel      Channel type (sms, whatsapp) - determines which field to search
	 * @return ContactModel|null
	 */
	private function find_or_create_contact( $phone_number, $channel = 'sms' ) {
		$is_whatsapp  = ( CampaignChannel::STR_WHATSAPP === $channel );
		$search_field = $is_whatsapp ? 'whatsapp_phone' : 'phone';

		$lookup_candidates = $this->build_phone_lookup_candidates( $phone_number, $is_whatsapp );

		foreach ( $lookup_candidates as $candidate ) {
			$contact = ContactModel::where( $search_field, $candidate )->first();
			if ( $contact ) {
				return $contact;
			}
		}

		if ( ! empty( $lookup_candidates ) ) {
			$contact = ContactModel::whereIn( $search_field, $lookup_candidates )->first();
			if ( $contact ) {
				return $contact;
			}
		}

		$stored_phone = $lookup_candidates[0] ?? preg_replace( '/[^\d+]/', '', $phone_number );

		doublescale_get_logger()->debug(
			'No contact found for phone number, creating new contact',
			array(
				'code'             => 'messaging_incoming_no_match',
				'original'         => $phone_number,
				'normalized'       => $stored_phone,
				'channel'          => $channel,
				'search_field'     => $search_field,
				'variations_tried' => count( $lookup_candidates ),
			)
		);

		try {
			$contact_data = array(
				'status' => 'subscribed',
			);

			if ( $is_whatsapp ) {
				$contact_data['whatsapp_phone'] = $stored_phone;
			} else {
				$contact_data['phone'] = $stored_phone;
			}

			$contact = ContactModel::create( $contact_data );

			doublescale_get_logger()->info(
				'New contact created from incoming message',
				array(
					'code'       => 'messaging_incoming_contact_created',
					'contact_id' => $contact->id,
					'channel'    => $channel,
					'phone'      => $stored_phone,
				)
			);

			return $contact;
		} catch ( \Exception $e ) {
			// Unique constraint or validation failure — contact likely exists under a different format.
			foreach ( $lookup_candidates as $candidate ) {
				$contact = ContactModel::where( $search_field, $candidate )->first();
				if ( $contact ) {
					doublescale_get_logger()->info(
						'Contact found after create failure (duplicate phone format)',
						array(
							'code'       => 'messaging_incoming_contact_found_on_retry',
							'contact_id' => $contact->id,
							'channel'    => $channel,
							'phone'      => $candidate,
						)
					);
					return $contact;
				}
			}

			doublescale_get_logger()->error(
				'Failed to create contact from incoming message',
				array(
					'code'    => 'messaging_incoming_contact_create_failed',
					'phone'   => $stored_phone,
					'channel' => $channel,
					'error'   => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Build phone number lookup candidates for contact matching.
	 *
	 * @param string $phone_number Raw phone from provider.
	 * @param bool   $is_whatsapp  Whether this is a WhatsApp channel lookup.
	 * @return string[] Unique candidate values, best match first.
	 */
	private function build_phone_lookup_candidates( $phone_number, $is_whatsapp ) {
		$candidates = array();

		if ( $is_whatsapp ) {
			$e164 = PhoneValidator::sanitize( $phone_number );
			if ( $e164 ) {
				$candidates[] = $e164;
			}
		}

		$normalized  = preg_replace( '/[^\d+]/', '', $phone_number );
		$digits_only = preg_replace( '/[^\d]/', '', $normalized );

		if ( $normalized ) {
			$candidates[] = $normalized;
		}

		if ( $phone_number !== $normalized && $phone_number ) {
			$candidates[] = $phone_number;
		}

		$without_plus = ltrim( $normalized, '+' );
		$with_plus    = '+' . $without_plus;

		if ( $with_plus ) {
			$candidates[] = $with_plus;
		}
		if ( $without_plus ) {
			$candidates[] = $without_plus;
		}

		if ( strlen( $digits_only ) === 11 && '1' === substr( $digits_only, 0, 1 ) ) {
			$national_number = substr( $digits_only, 1 );
			$candidates[]    = $national_number;
			$candidates[]    = '+1' . $national_number;
		}

		if ( strlen( $digits_only ) === 10 ) {
			$candidates[] = '+1' . $digits_only;
			$candidates[] = '1' . $digits_only;
		}

		if ( ! $is_whatsapp ) {
			$loose = PhoneValidator::normalize_loose( $phone_number );
			if ( $loose ) {
				$candidates[] = $loose;
			}
		}

		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * Read webhook payload from JSON body or form POST.
	 *
	 * @return array
	 */
	private function get_webhook_payload() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- php://input required for Meta JSON webhooks.
		$raw_body = file_get_contents( 'php://input' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by provider after signature verification.
		$json_data = json_decode( $raw_body, true );

		if ( is_array( $json_data ) && json_last_error() === JSON_ERROR_NONE ) {
			return $json_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Webhook receiver; auth via provider signature.
		if ( ! empty( $_POST ) ) {
			return map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		}

		return array();
	}

	/**
	 * Get tracking mode constant for channel
	 *
	 * @param string $channel Channel type
	 * @return int Tracking mode constant
	 */
	private function get_tracking_mode_for_channel( $channel ) {
		$mode_map = array(
			CampaignChannel::STR_SMS      => CommunicationTrackingModel::MODE_SMS,
			CampaignChannel::STR_WHATSAPP => CommunicationTrackingModel::MODE_WHATSAPP,
		);

		return $mode_map[ $channel ] ?? CommunicationTrackingModel::MODE_SMS;
	}

	/**
	 * Send error response (generic XML for compatibility)
	 */
	private function send_error_response() {
		header( 'Content-Type: text/xml' );
		echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
		exit;
	}

	/**
	 * Handle subscription keywords (STOP/START) in incoming messages
	 *
	 * Detects opt-out and opt-in keywords and updates contact subscription status.
	 * This is the industry standard for Sms/Whatsapp compliance (TCPA, carrier requirements).
	 *
	 * @since 1.0.0
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact Contact model
	 * @param string                         $message_body Message body to check for keywords
	 * @param string                         $channel Channel type (sms, whatsapp)
	 * @return array|null Result array with 'action' key, or null if no keyword detected
	 */
	private function handle_subscription_keywords( $contact, $message_body, $channel ) {
		// Normalize message for comparison (trim, uppercase, remove extra whitespace)
		$normalized_message = strtoupper( trim( preg_replace( '/\s+/', ' ', $message_body ) ) );

		// Define opt-out keywords (industry standard)
		$opt_out_keywords = apply_filters(
			'doublescale_opt_out_keywords',
			array( 'STOP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'STOPALL', 'STOP ALL' ),
			$channel
		);

		// Define opt-in keywords (industry standard)
		$opt_in_keywords = apply_filters(
			'doublescale_opt_in_keywords',
			array( 'START', 'SUBSCRIBE', 'YES', 'UNSTOP', 'OPTIN', 'OPT IN' ),
			$channel
		);

		// Get the status field for this channel
		$status_field = $channel . '_status';

		// Check for opt-out keywords
		if ( in_array( $normalized_message, $opt_out_keywords, true ) ) {
			if (
				CampaignChannel::STR_WHATSAPP === $channel
				&& ! Settings::is_whatsapp_auto_keyword_unsubscribe_enabled()
			) {
				return null;
			}

			return $this->process_opt_out( $contact, $channel, $status_field );
		}

		// Check for opt-in keywords
		if ( in_array( $normalized_message, $opt_in_keywords, true ) ) {
			return $this->process_opt_in( $contact, $channel, $status_field );
		}

		// No keyword detected
		return null;
	}

	/**
	 * Process opt-out request
	 *
	 * Uses ContactModel::unsubscribe_from_mode() to ensure consistent behavior:
	 * - Updates contact status
	 * - Records in contact_unsubscribes table
	 * - Creates activity note
	 * - Fires doublescale_{channel}_unsubscribed action
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact Contact model
	 * @param string                         $channel Channel type
	 * @param string                         $status_field Status field name
	 * @return array Result array
	 */
	private function process_opt_out( $contact, $channel, $status_field ) {
		$previous_status = $contact->getAttribute( $status_field );

		// Only process if not already unsubscribed
		if ( 'unsubscribed' === $previous_status ) {
			doublescale_get_logger()->info(
				'Contact already unsubscribed, no action taken',
				array(
					'code'       => 'messaging_keyword_already_unsubscribed',
					'channel'    => $channel,
					'contact_id' => $contact->id,
				)
			);

			return array(
				'action'          => 'already_unsubscribed',
				'previous_status' => $previous_status,
			);
		}

		// Map channel to mode constant
		$mode_map = array(
			CampaignChannel::STR_SMS      => CommunicationTrackingModel::MODE_SMS,
			CampaignChannel::STR_WHATSAPP => CommunicationTrackingModel::MODE_WHATSAPP,
		);
		$mode = $mode_map[ $channel ] ?? null;

		if ( ! $mode ) {
			doublescale_get_logger()->error(
				'Invalid channel for opt-out',
				array(
					'code'       => 'messaging_keyword_invalid_channel',
					'channel'    => $channel,
					'contact_id' => $contact->id,
				)
			);
			return array(
				'action' => 'error',
				'error'  => 'Invalid channel',
			);
		}

		// Use the standard unsubscribe method which:
		// 1. Updates contact status
		// 2. Records in contact_unsubscribes table
		// 3. Creates activity note
		// 4. Fires doublescale_{channel}_unsubscribed action
		$contact->unsubscribe_from_mode(
			$mode,
			'stop_keyword', // Reason
			null,           // source_type - null for keyword unsubscribe (not from campaign/automation)
			null            // source_id
		);

		// Log the keyword-specific unsubscribe
		doublescale_get_logger()->info(
			sprintf( 'Contact unsubscribed from %s via STOP keyword', $channel ),
			array(
				'code'            => 'messaging_keyword_unsubscribed',
				'channel'         => $channel,
				'contact_id'      => $contact->id,
				'previous_status' => $previous_status,
			)
		);

		return array(
			'action'          => 'unsubscribed',
			'previous_status' => $previous_status,
		);
	}

	/**
	 * Process opt-in request
	 *
	 * Uses ContactModel::subscribe_to_channel() to ensure consistent behavior:
	 * - Updates contact status
	 * - Creates activity note
	 * - Fires doublescale_{channel}_subscribed action
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact Contact model
	 * @param string                         $channel Channel type
	 * @param string                         $status_field Status field name
	 * @return array Result array
	 */
	private function process_opt_in( $contact, $channel, $status_field ) {
		$previous_status = $contact->getAttribute( $status_field );

		// Only process if currently unsubscribed (don't override 'blocked')
		if ( 'subscribed' === $previous_status ) {
			doublescale_get_logger()->info(
				'Contact already subscribed, no action taken',
				array(
					'code'       => 'messaging_keyword_already_subscribed',
					'channel'    => $channel,
					'contact_id' => $contact->id,
				)
			);

			return array(
				'action'          => 'already_subscribed',
				'previous_status' => $previous_status,
			);
		}

		// Don't allow re-subscribe if blocked (admin decision)
		if ( 'blocked' === $previous_status ) {
			doublescale_get_logger()->info(
				'Contact is blocked, cannot re-subscribe via keyword',
				array(
					'code'       => 'messaging_keyword_blocked',
					'channel'    => $channel,
					'contact_id' => $contact->id,
				)
			);

			return array(
				'action'          => 'blocked',
				'previous_status' => $previous_status,
			);
		}

		// Use the standard subscribe method which:
		// 1. Updates contact status
		// 2. Creates activity note
		// 3. Fires doublescale_{channel}_subscribed action
		$contact->subscribe_to_channel( $channel );

		// Log the keyword-specific re-subscribe
		doublescale_get_logger()->info(
			sprintf( 'Contact re-subscribed to %s via START keyword', $channel ),
			array(
				'code'            => 'messaging_keyword_resubscribed',
				'channel'         => $channel,
				'contact_id'      => $contact->id,
				'previous_status' => $previous_status,
			)
		);

		return array(
			'action'          => 'subscribed',
			'previous_status' => $previous_status,
		);
	}
}
