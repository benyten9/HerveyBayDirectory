<?php
/**
 * Meta WhatsApp Message Provider
 *
 * Implements the message provider interface for Meta WhatsApp Business Api
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MessageProviders;

use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractMessageProvider;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Integrations\MetaWhatsapp\Api;
use DoubleScale\Core\Constants\MetaWhatsappErrorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * MetaWhatsappProvider class
 */
class MetaWhatsappProvider extends AbstractMessageProvider {

	/**
	 * Provider slug
	 *
	 * @var string
	 */
	protected $provider_slug = 'meta-whatsapp';

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $provider_name = 'Meta WhatsApp';

	/**
	 * Supported channels
	 *
	 * @var array
	 */
	protected $supported_channels = array( 'whatsapp' );

	/**
	 * Tracking classes for each channel
	 *
	 * @var array
	 */
	protected $tracking_classes = array(
		'whatsapp' => '\DoubleScale\Modules\Tracking\Whatsapp',
	);

	/**
	 * Api instance
	 *
	 * @var Api|null
	 */
	private $api = null;

	/**
	 * Send a message via Meta WhatsApp Api
	 *
	 * @param string                         $channel Channel type (whatsapp).
	 * @param array                          $data    Message data.
	 * @param ContactModel $contact Contact model.
	 *
	 * @return array Result array with success status.
	 */
	public function send_message( string $channel, array $data, ContactModel $contact ): array {
		try {
			if ( ! $this->supports_channel( $channel ) ) {
				return $this->error_result( "Meta provider does not support channel: $channel" );
			}

			$api = $this->get_api();
			if ( ! $api ) {
				return $this->error_result( __( 'Meta WhatsApp is not configured', 'doublescale') );
			}

			// Parse ContentSid format: "template_name:language_code"
			$content_sid     = $data['ContentSid'] ?? '';
			$has_content_sid = ! empty( $content_sid ) && strpos( $content_sid, ':' ) !== false;
			$has_body        = ! empty( $data['Body'] );
			$is_session_msg  = ! empty( $data['is_session_message'] );

			if ( $has_content_sid ) {
				// Template message
				list( $template_name, $language ) = explode( ':', $content_sid, 2 );
				$components                       = $this->build_components( $data['ContentVariables'] ?? array() );

				$result = $api->send_template_message(
					$data['To'],
					$template_name,
					$language,
					$components
				);

				$this->log( 'debug', 'Sending Meta WhatsApp template message', array(
					'contact_id' => $contact->id ?? null,
					'template'   => $template_name,
					'language'   => $language,
				) );

			} elseif ( $has_body && $is_session_msg ) {
				// Session message (within 24h window)
				$result = $api->send_text_message( $data['To'], $data['Body'] );

				$this->log( 'debug', 'Sending Meta WhatsApp session message', array(
					'contact_id'   => $contact->id ?? null,
					'body_preview' => substr( $data['Body'], 0, 50 ),
				) );
			} else {
				return $this->error_result(
					__( 'Whatsapp requires template (ContentSid) or session message (Body with 24h window)', 'doublescale')
				);
			}

			if ( $result['success'] ) {
				$message_id = $result['data']['messages'][0]['id'] ?? '';
				
				$this->log( 'debug', 'Meta WhatsApp message sent successfully', array(
					'message_id' => $message_id,
					'contact_id' => $contact->id ?? null,
				) );
				
				return $this->success_result( $message_id, array( 'provider' => 'meta-whatsapp' ) );
			}

			$error_code    = isset( $result['error_code'] ) ? (int) $result['error_code'] : 0;
			$error_message = $result['error'] ?? 'Unknown error';

			// Turn the opaque "(#133010) Account not registered" into actionable guidance.
			if ( $error_code && MetaWhatsappErrorCodes::is_registration_error( $error_code ) ) {
				$error_message = MetaWhatsappErrorCodes::get_error_message( $error_code );
			}

			$this->log( 'error', 'Meta WhatsApp send failed', array(
				'error'      => $result['error'],
				'error_code' => $error_code,
				'contact_id' => $contact->id ?? null,
				'source'     => 'inbox-meta-whatsapp',
			) );

			return $this->error_result( $error_message, array( 'error_code' => $error_code ) );

		} catch ( \Exception $e ) {
			$this->log( 'error', 'Meta WhatsApp send exception', array(
				'error'      => $e->getMessage(),
				'contact_id' => $contact->id ?? null,
			) );
			return $this->error_result( $e->getMessage() );
		}
	}

	/**
	 * Build Meta template components from variables
	 *
	 * Supports both positional ({{1}}, {{2}}) and named ({{name}}, {{order}}) variables.
	 * - Positional: {"1": "John", "2": "Order #123"} - sorted numerically
	 * - Named: {"name": "John", "order": "Order #123"} - includes parameter_name
	 *
	 * @param array|string $variables Variables array or JSON string.
	 *
	 * @return array Components array for Meta Api.
	 */
	private function build_components( $variables ): array {
		if ( empty( $variables ) ) {
			return array();
		}

		// Handle both JSON string and array
		if ( is_string( $variables ) ) {
			$variables = json_decode( $variables, true ) ?? array();
		}

		// Ensure we have an array at this point
		if ( ! is_array( $variables ) ) {
			return array();
		}

		// Detect if using positional or named variables
		$is_positional = true;
		foreach ( array_keys( $variables ) as $key ) {
			if ( ! is_numeric( $key ) ) {
				$is_positional = false;
				break;
			}
		}

		// Sort positional variables numerically (1, 2, 10 not 1, 10, 2)
		if ( $is_positional ) {
			uksort(
				$variables,
				function ( $a, $b ) {
					return (int) $a - (int) $b;
				}
			);
		}

		$parameters = array();
		foreach ( $variables as $key => $value ) {
			$param = array(
				'type' => 'text',
				'text' => (string) $value,
			);

			// Named variables require parameter_name in Meta Api
			if ( ! is_numeric( $key ) ) {
				$param['parameter_name'] = $key;
			}

			$parameters[] = $param;
		}

		if ( empty( $parameters ) ) {
			return array();
		}

		return array(
			array(
				'type'       => 'body',
				'parameters' => $parameters,
			),
		);
	}

	/**
	 * Get the Api instance
	 *
	 * @return Api|null Api instance or null.
	 */
	private function get_api() {
		if ( ! $this->api ) {
			$integration = $this->get_integration();
			if ( ! $integration ) {
				return null;
			}

			$this->api = $integration->connect();
		}
		return $this->api;
	}

	/**
	 * Parse incoming webhook data from Meta
	 *
	 * @param array $post_data Raw webhook data.
	 *
	 * @return array Normalized message data.
	 */
	public function parse_incoming_webhook( array $post_data ): array {
		// Shortcut when caller already isolated a single message + change value.
		if ( isset( $post_data['_message'], $post_data['_value'] ) && is_array( $post_data['_message'] ) && is_array( $post_data['_value'] ) ) {
			return $this->parse_message_data( $post_data['_message'], $post_data['_value'] );
		}

		// Meta webhook format (first message in first change — legacy single-message path).
		$entry   = $post_data['entry'][0] ?? array();
		$changes = $entry['changes'][0] ?? array();
		$value   = $changes['value'] ?? array();
		$message = $value['messages'][0] ?? array();

		return $this->parse_message_data( $message, $value );
	}

	/**
	 * Parse a single inbound Meta message from a change value block.
	 *
	 * @param array $message Message object from Meta webhook.
	 * @param array $value   Change value containing metadata.
	 * @return array Normalized message data.
	 */
	public function parse_message_data( array $message, array $value ): array {
		$from_number = $message['from'] ?? '';
		$to_number   = $value['metadata']['display_phone_number'] ?? '';

		if ( ! empty( $from_number ) && strpos( $from_number, '+' ) !== 0 ) {
			$from_number = '+' . $from_number;
		}
		if ( ! empty( $to_number ) && strpos( $to_number, '+' ) !== 0 ) {
			$to_number = '+' . $to_number;
		}

		$message_body = '';
		$message_type = $message['type'] ?? 'text';

		switch ( $message_type ) {
			case 'text':
				$message_body = $message['text']['body'] ?? '';
				break;
			case 'button':
				$message_body = $message['button']['text'] ?? '';
				break;
			case 'interactive':
				$interactive  = $message['interactive'] ?? array();
				$message_body = $interactive['button_reply']['title'] ?? $interactive['list_reply']['title'] ?? '';
				break;
		}

		return array(
			'from_number'  => $from_number,
			'to_number'    => $to_number,
			'message_body' => $message_body,
			'message_id'   => $message['id'] ?? '',
			'media_urls'   => array(),
		);
	}

	/**
	 * Verify webhook signature from Meta
	 *
	 * @param array  $server Server variables.
	 * @param array  $post   POST data.
	 * @param string $url    Request URL.
	 *
	 * @return bool True if signature is valid.
	 */
	public function verify_webhook_signature( array $server, array $post, string $url ): bool {
		$signature = $server['HTTP_X_HUB_SIGNATURE_256'] ?? '';

		if ( empty( $signature ) ) {
			return false;
		}

		$integration = $this->get_integration();
		$app_secret  = $integration ? $integration->get_setting( 'app_secret' ) : '';

		if ( empty( $app_secret ) ) {
			$this->log( 'warning', 'Meta webhook: app_secret not configured' );
			return false;
		}

		// Get raw body for signature verification
		$raw_body = file_get_contents( 'php://input' );
		$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $app_secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Send webhook response
	 *
	 * @param string $reply_message Optional reply message.
	 *
	 * @return void
	 */
	public function send_webhook_response( string $reply_message = '' ): void {
		status_header( 200 );
		echo 'OK';
		exit;
	}

	/**
	 * Process webhook data from Meta
	 *
	 * @param string $channel      Channel type.
	 * @param array  $webhook_data Webhook data.
	 *
	 * @return array Processed webhook result.
	 */
	public function process_webhook( string $channel, array $webhook_data ): array {
		if ( ! $this->supports_channel( $channel ) ) {
			return $this->webhook_error_result( 'Channel not supported' );
		}

		$results = array();

		foreach ( $webhook_data['entry'] ?? array() as $entry ) {
			foreach ( $entry['changes'] ?? array() as $change ) {
				$value = $change['value'] ?? array();

				if ( ! empty( $value['statuses'] ) && is_array( $value['statuses'] ) ) {
					foreach ( $value['statuses'] as $status ) {
						$status_result = $this->build_status_webhook_result( $status );
						if ( $status_result ) {
							$results[] = $status_result;
						}
					}
				}

				if ( ! empty( $value['messages'] ) && is_array( $value['messages'] ) ) {
					foreach ( $value['messages'] as $message ) {
						$incoming_result = $this->build_incoming_webhook_result( $message, $value );
						if ( $incoming_result ) {
							$results[] = $incoming_result;
						}
					}
				}
			}
		}

		if ( empty( $results ) ) {
			return $this->webhook_error_result( 'Unknown webhook type' );
		}

		if ( 1 === count( $results ) ) {
			return $results[0];
		}

		return array(
			'valid'   => true,
			'results' => $results,
		);
	}

	/**
	 * Build a standardized webhook result for a Meta delivery status update.
	 *
	 * @param array $status Status object from Meta webhook.
	 * @return array|null Webhook result or null when status id is missing.
	 */
	private function build_status_webhook_result( array $status ): ?array {
		if ( empty( $status['id'] ) ) {
			return null;
		}

		$error_code = isset( $status['errors'][0]['code'] ) ? (int) $status['errors'][0]['code'] : null;
		$error_msg  = $status['errors'][0]['message'] ?? null;

		$is_opt_out_error = $error_code && MetaWhatsappErrorCodes::is_opt_out_error( $error_code );

		$metadata = array();
		if ( $is_opt_out_error ) {
			$metadata['is_opt_out']     = true;
			$metadata['opt_out_reason'] = MetaWhatsappErrorCodes::get_opt_out_reason( $error_code );
			$metadata['recipient_id']   = $status['recipient_id'] ?? null;

			$this->log(
				'info',
				'Meta WhatsApp opt-out detected from error code',
				array(
					'error_code'   => $error_code,
					'error_msg'    => $error_msg,
					'recipient_id' => $status['recipient_id'] ?? null,
					'reason'       => $metadata['opt_out_reason'],
				)
			);
		}

		if ( ! empty( $status['conversation'] ) ) {
			$conversation             = $status['conversation'];
			$metadata['conversation'] = array(
				'id'                   => $conversation['id'] ?? null,
				'origin_type'          => $conversation['origin']['type'] ?? null,
				'expiration_timestamp' => $conversation['expiration_timestamp'] ?? null,
			);

			$recipient_phone = $status['recipient_id'] ?? null;
			if ( $recipient_phone && ! empty( $conversation['expiration_timestamp'] ) ) {
				$this->store_conversation_expiration( $recipient_phone, $conversation );
			}
		}

		return $this->webhook_success_result(
			$status['id'],
			$this->map_meta_status( $status['status'] ?? '' ),
			$error_code,
			$error_msg,
			$metadata
		);
	}

	/**
	 * Build a standardized webhook result for an inbound Meta message.
	 *
	 * @param array $message Inbound message object.
	 * @param array $value   Parent change value (metadata).
	 * @return array|null Webhook result or null when message id is missing.
	 */
	private function build_incoming_webhook_result( array $message, array $value ): ?array {
		if ( empty( $message['id'] ) ) {
			return null;
		}

		$from_phone = $message['from'] ?? null;
		$timestamp  = $message['timestamp'] ?? null;

		if ( $from_phone && $timestamp ) {
			$expiration_timestamp = (int) $timestamp + ( 24 * 3600 );
			$this->store_conversation_expiration(
				$from_phone,
				array(
					'id'                   => null,
					'origin_type'          => 'user_initiated',
					'expiration_timestamp' => (string) $expiration_timestamp,
				)
			);
		}

		$parsed_incoming = $this->parse_message_data( $message, $value );

		return $this->webhook_success_result(
			$message['id'],
			'received',
			null,
			null,
			array(
				'parsed_incoming' => $parsed_incoming,
			)
		);
	}

	/**
	 * Store conversation expiration for a contact
	 *
	 * @param string $phone_number   Contact's phone number.
	 * @param array  $conversation   Conversation data from Meta.
	 */
	private function store_conversation_expiration( string $phone_number, array $conversation ): void {
		// Find contact by WhatsApp phone number
		$contact = ContactModel::where( 'whatsapp_phone', $phone_number )
			->orWhere( 'whatsapp_phone', '+' . ltrim( $phone_number, '+' ) )
			->orWhere( 'whatsapp_phone', ltrim( $phone_number, '+' ) )
			->first();

		if ( ! $contact ) {
			// Try normalized phone match
			$normalized = ltrim( $phone_number, '+' );
			$contact    = ContactModel::where( 'whatsapp_phone', 'LIKE', '%' . $normalized )
				->first();
		}

		if ( ! $contact ) {
			$this->log( 'debug', 'Cannot store conversation expiration - contact not found', array(
				'phone_number' => $phone_number,
			) );
			return;
		}

		// Store in contact meta
		$expiration_data = array(
			'expiration_timestamp' => $conversation['expiration_timestamp'],
			'origin_type'          => $conversation['origin_type'] ?? 'unknown',
			'conversation_id'      => $conversation['id'] ?? null,
			'updated_at'           => time(),
		);

		doublescale_update_contact_meta( $contact->id, 'whatsapp_conversation_window', $expiration_data );

		$this->log( 'debug', 'Stored WhatsApp conversation window expiration', array(
			'contact_id'           => $contact->id,
			'expiration_timestamp' => $conversation['expiration_timestamp'],
			'origin_type'          => $conversation['origin_type'] ?? 'unknown',
		) );
	}

	/**
	 * Map Meta status to standard status
	 *
	 * @param string $meta_status Meta status string.
	 *
	 * @return string Standard status string.
	 */
	private function map_meta_status( string $meta_status ): string {
		$map = array(
			'sent'      => 'sent',
			'delivered' => 'delivered',
			'read'      => 'read',
			'failed'    => 'failed',
		);

		return $map[ $meta_status ] ?? 'pending';
	}
}

