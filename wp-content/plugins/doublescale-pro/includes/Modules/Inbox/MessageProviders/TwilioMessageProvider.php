<?php
/**
 * Twilio Message Provider
 * Adapter for Twilio integration
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MessageProviders;

use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractMessageProvider;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * TwilioMessageProvider class
 *
 * Wraps the existing Twilio integration to implement the MessageProviderInterface.
 * This adapter pattern allows Twilio to work with the new provider system without
 * modifying the existing Twilio integration code.
 *
 * @since 1.0.0
 */
class TwilioMessageProvider extends AbstractMessageProvider {

	/**
	 * Provider slug
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $provider_slug = 'twilio';

	/**
	 * Provider name
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $provider_name = 'Twilio';

	/**
	 * Supported channels
	 *
	 * TWILIO_WHATSAPP_DISABLED: WhatsApp removed - only Meta WhatsApp is supported.
	 * Original: array( 'sms', 'whatsapp' )
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $supported_channels = array( 'sms' );

	/**
	 * Mapping of channels to tracking classes
	 *
	 * TWILIO_WHATSAPP_DISABLED: WhatsApp tracking removed - only Meta WhatsApp is supported.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $tracking_classes = array(
		'sms' => '\DoubleScale\Modules\Tracking\Sms',
		// 'whatsapp' => '\DoubleScale\Modules\Tracking\Whatsapp', // TWILIO_WHATSAPP_DISABLED
	);

	/**
	 * Twilio Api instance (lazy loaded)
	 *
	 * @since 1.0.0
	 *
	 * @var mixed
	 */
	private $api;

	/**
	 * Send message via Twilio (unified method for all channels)
	 *
	 * TWILIO_WHATSAPP_DISABLED: WhatsApp support removed - only Sms is supported.
	 * For WhatsApp, use Meta WhatsApp provider instead.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $channel Channel type ('sms')
	 * @param array         $data Message data
	 * @param ContactModel $contact Contact model
	 * @return array Result array
	 */
	public function send_message( string $channel, array $data, ContactModel $contact ): array {
		try {
			// Validate channel support
			if ( ! $this->supports_channel( $channel ) ) {
				return $this->error_result(
					sprintf( 'Twilio provider does not support channel: %s', $channel )
				);
			}

			// Get Twilio Api instance
			$api = $this->get_api();
			if ( ! $api ) {
				return $this->error_result( 'Twilio not configured' );
			}

			// Call appropriate Twilio Api method based on channel
			switch ( $channel ) {
				case 'sms':
					$result = $api->send_sms( $data );
					break;

				/*
				 * TWILIO_WHATSAPP_DISABLED: WhatsApp case removed.
				 * WhatsApp is now handled by Meta WhatsApp provider only.
				 *
				 * Original code:
				 * case 'whatsapp':
				 *     // WhatsApp supports two message types:
				 *     // 1. Template messages (ContentSid) - for business-initiated conversations
				 *     // 2. Session messages (Body) - free-text within 24h of last inbound message
				 *     $has_content_sid = ! empty( $data['ContentSid'] );
				 *     $has_body        = ! empty( $data['Body'] );
				 *     $is_session_msg  = ! empty( $data['is_session_message'] );
				 *
				 *     if ( $has_content_sid ) {
				 *         $result = $api->send_whatsapp_template( $data );
				 *         $this->log( 'debug', 'Sending WhatsApp business template message', ... );
				 *     } elseif ( $has_body && $is_session_msg ) {
				 *         $result = $api->send_whatsapp( $data );
				 *         $this->log( 'debug', 'Sending WhatsApp session message (within 24h window)', ... );
				 *     } else {
				 *         return $this->error_result( 'Whatsapp messages require...' );
				 *     }
				 *     break;
				 */

				default:
					return $this->error_result( sprintf( 'Unknown channel: %s', $channel ) );
			}

			// Map Twilio response to standard format
			return $this->map_twilio_response( $result, $contact, $channel );

		} catch ( \Exception $e ) {
			$this->log(
				'error',
				sprintf( 'Twilio %s send failed', ucfirst( $channel ) ),
				array(
					'channel'    => $channel,
					'error'      => $e->getMessage(),
					'contact_id' => $contact->id,
				)
			);

			return $this->error_result( $e->getMessage() );
		}
	}

	// is_configured() is now inherited from AbstractMessageProvider
	// It uses $this->get_integration() automatically via provider_slug

	// get_webhook_url() is now inherited from AbstractMessageProvider
	// It uses $this->tracking_classes mapping automatically

	/**
	 * Process webhook from Twilio
	 *
	 * TWILIO_WHATSAPP_DISABLED: WhatsApp webhook processing removed - only Sms is supported.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type ('sms')
	 * @param array  $webhook_data Raw webhook data from Twilio
	 * @return array Standardized webhook result
	 */
	public function process_webhook( string $channel, array $webhook_data ): array {
		// Validate channel support
		if ( ! $this->supports_channel( $channel ) ) {
			return $this->webhook_error_result( 'Channel not supported' );
		}

		// Build webhook URL for signature verification
		// Use the full URL that Twilio called (scheme + host + request URI)
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$scheme      = is_ssl() ? 'https' : 'http';
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$webhook_url = $scheme . '://' . $host . $request_uri;

		// Verify Twilio webhook signature for security
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $this->verify_webhook_signature( $_SERVER, $webhook_data, $webhook_url ) ) {
			$this->log(
				'warning',
				sprintf( 'Twilio %s webhook signature verification failed', ucfirst( $channel ) ),
				array(
					'channel'     => $channel,
					'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
				)
			);
			return $this->webhook_error_result( 'Signature verification failed' );
		}

		// Extract Twilio webhook fields
		$message_sid    = $webhook_data['MessageSid'] ?? '';
		$message_status = $webhook_data['MessageStatus'] ?? '';
		$error_code     = $webhook_data['ErrorCode'] ?? null;
		$error_message  = $webhook_data['ErrorMessage'] ?? null;

		// Validate required fields
		if ( empty( $message_sid ) || empty( $message_status ) ) {
			$this->log(
				'warning',
				sprintf( 'Twilio %s webhook missing required data', ucfirst( $channel ) ),
				array(
					'channel'        => $channel,
					'message_sid'    => $message_sid,
					'message_status' => $message_status,
				)
			);
			return $this->webhook_error_result( 'Missing required webhook fields' );
		}

		// Map Twilio status to standard status
		$standard_status = $this->map_twilio_status( $message_status );

		$this->log(
			'debug',
			sprintf( 'Twilio %s webhook processed', ucfirst( $channel ) ),
			array(
				'channel'        => $channel,
				'message_sid'    => $message_sid,
				'message_status' => $message_status,
				'standard_status' => $standard_status,
			)
		);

		// Return standardized webhook result
		return $this->webhook_success_result(
			$message_sid,
			$standard_status,
			$error_code,
			$error_message,
			array(
				'twilio_status' => $message_status,
				'raw_data'      => $webhook_data,
			)
		);
	}

	/**
	 * Verify Twilio webhook signature (HMAC-SHA1)
	 * Implements abstract method from AbstractMessageProvider
	 *
	 * @since 1.0.0
	 *
	 * @param array  $server $_SERVER data (headers)
	 * @param array  $post $_POST data (body)
	 * @param string $url Full webhook URL
	 * @return bool True if signature valid
	 */
	public function verify_webhook_signature( array $server, array $post, string $url): bool {
		// Get auth token from integration settings
		$integration = $this->get_integration();
		if ( ! $integration ) {
			// Allow in dev mode
			return defined( 'WP_DEBUG' ) && WP_DEBUG;
		}

		$auth_token = $integration->get_setting( 'auth_token' );
		if ( ! $auth_token ) {
			return defined( 'WP_DEBUG' ) && WP_DEBUG;
		}

		// Get signature from header
		$signature = isset( $server['HTTP_X_TWILIO_SIGNATURE'] )
			? sanitize_text_field( $server['HTTP_X_TWILIO_SIGNATURE'] )
			: '';

		if ( ! $signature ) {
			// In dev mode, allow requests without signature (like manual curl tests)
			return defined( 'WP_DEBUG' ) && WP_DEBUG;
		}

		// Build data string (sorted POST params)
		ksort( $post );
		$data_string = '';
		foreach ( $post as $key => $value ) {
			$data_string .= $key . $value;
		}

		// Calculate expected signature
		$expected_signature = base64_encode(
			hash_hmac( 'sha1', $url . $data_string, $auth_token, true )
		);

		return hash_equals( $expected_signature, $signature );
	}

	/**
	 * Map Twilio status to standard status
	 *
	 * @since 1.0.0
	 *
	 * @param string $twilio_status Twilio status string
	 * @return string Standard status
	 */
	private function map_twilio_status( string $twilio_status ): string {
		$status_map = array(
			'queued'      => 'pending',
			'sending'     => 'pending',
			'sent'        => 'sent',
			'delivered'   => 'delivered',
			'read'        => 'read',
			'failed'      => 'failed',
			'undelivered' => 'failed',
		);

		return $status_map[ $twilio_status ] ?? 'unknown';
	}

	/**
	 * Get Twilio Api instance (lazy loaded)
	 *
	 * @since 1.0.0
	 *
	 * @return mixed|null Twilio Api instance or null if not available
	 */
	private function get_api() {
		if ( ! $this->api ) {
			$integration = $this->get_integration();
			$this->api = $integration ? $integration->connect() : null;
		}
		return $this->api;
	}

	/**
	 * Map Twilio response to standard provider response format
	 *
	 * @since 1.0.0
	 *
	 * @param array         $result Twilio Api result
	 * @param ContactModel $contact Contact model
	 * @param string        $channel Channel type
	 * @return array Standardized result
	 */
	private function map_twilio_response( array $result, ContactModel $contact, string $channel): array {
		// Check if send was successful
		if ( isset( $result['success'] ) && $result['success'] ) {
			// Extract message ID (Twilio's SID)
			$message_id = $result['data']['sid'] ?? '';

			$this->log(
				'debug',
				sprintf( 'Twilio %s sent successfully', ucfirst( $channel ) ),
				array(
					'message_id' => $message_id,
					'contact_id' => $contact->id,
					'channel'    => $channel,
				)
			);

			return $this->success_result(
				$message_id,
				$result['data'] ?? array()
			);
		}

		// Handle failure - extract detailed error message from Twilio response
		$error = 'Unknown Twilio error';

		// Twilio returns error details in result['data'] from the Api response
		if ( isset( $result['data']['message'] ) ) {
			$error = $result['data']['message'];
			// Add error code if available for more context
			if ( isset( $result['data']['code'] ) ) {
				$error = sprintf( '[%d] %s', $result['data']['code'], $error );
			}
		} elseif ( isset( $result['error'] ) ) {
			$error = $result['error'];
		}

		$this->log(
			'error',
			sprintf( 'Twilio %s send failed', ucfirst( $channel ) ),
			array(
				'error'      => $error,
				'contact_id' => $contact->id,
				'channel'    => $channel,
				'result'     => $result,
			)
		);

		// Pass Twilio error data in metadata for proper error formatting
		$metadata = array();
		if ( isset( $result['data'] ) ) {
			$metadata['error_details'] = $result['data'];
		}
		if ( isset( $result['code'] ) ) {
			$metadata['http_code'] = $result['code'];
		}

		return $this->error_result( $error, $metadata );
	}

	/**
	 * Parse Twilio incoming webhook data
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data Raw $_POST from Twilio
	 * @return array Normalized data
	 */
	public function parse_incoming_webhook( array $post_data): array {
		$num_media = isset( $post_data['NumMedia'] ) ? absint( $post_data['NumMedia'] ) : 0;

		// Collect media URLs (MMS support)
		$media_urls = array();
		for ( $i = 0; $i < $num_media; $i++ ) {
			$media_key = 'MediaUrl' . $i;
			if ( isset( $post_data[ $media_key ] ) ) {
				$media_urls[] = esc_url_raw( $post_data[ $media_key ] );
			}
		}

		// Extract and sanitize phone numbers
		$from_number = sanitize_text_field( $post_data['From'] ?? '' );
		$to_number   = sanitize_text_field( $post_data['To'] ?? '' );

		// Strip "whatsapp:" prefix from WhatsApp numbers
		// Twilio sends WhatsApp numbers as "whatsapp:+1234567890"
		$from_number = $this->strip_whatsapp_prefix( $from_number );
		$to_number   = $this->strip_whatsapp_prefix( $to_number );

		return array(
			'from_number'  => $from_number,
			'to_number'    => $to_number,
			'message_body' => sanitize_textarea_field( $post_data['Body'] ?? '' ),
			'message_id'   => sanitize_text_field( $post_data['MessageSid'] ?? '' ),
			'media_urls'   => $media_urls,
		);
	}

	/**
	 * Strip "whatsapp:" prefix from phone number
	 *
	 * Twilio sends WhatsApp messages with numbers formatted as "whatsapp:+1234567890"
	 * We need to strip this prefix to match contacts by phone number.
	 *
	 * @since 1.0.0
	 *
	 * @param string $phone_number Phone number (may have whatsapp: prefix)
	 * @return string Clean phone number
	 */
	private function strip_whatsapp_prefix( string $phone_number ): string {
		if ( strpos( $phone_number, 'whatsapp:' ) === 0 ) {
			return substr( $phone_number, 9 ); // Length of "whatsapp:"
		}
		return $phone_number;
	}

	/**
	 * Send TwiML response to Twilio
	 *
	 * @since 1.0.0
	 *
	 * @param string $reply_message Optional auto-reply message
	 * @return void
	 */
	public function send_webhook_response( string $reply_message = ''): void {
		header( 'Content-Type: text/xml' );

		if ( ! empty( $reply_message ) ) {
			echo '<?xml version="1.0" encoding="UTF-8"?>';
			echo '<Response>';
			echo '<Message>' . esc_html( $reply_message ) . '</Message>';
			echo '</Response>';
		} else {
			// Empty response = acknowledge without auto-reply
			echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
		}

		exit;
	}
}
