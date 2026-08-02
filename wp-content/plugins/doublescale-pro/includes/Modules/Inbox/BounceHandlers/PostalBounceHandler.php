<?php
/**
 * Postal Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * PostalBounceHandler class
 */
class PostalBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'Postal';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Configure Postal server bounce webhook';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://github.com/postalserver/postal/wiki/Webhooks';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'Add this webhook URL in your Postal server webhook settings.';

	/**
	 * Handle Postal webhook
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function handle() {
		// Guard clause for invalid data
		if ( ! $this->is_valid_webhook_data() ) {
			return false;
		}

		$event_type = $this->extract_event_type();
		if ( ! $event_type ) {
			return false;
		}

		if ( ! $this->is_supported_bounce_event( $event_type ) ) {
			return false;
		}

		$payload = $this->extract_payload();
		$email   = $this->extract_email_from_payload( $payload );
		if ( ! $email ) {
			return false;
		}

		$metadata = $this->build_bounce_metadata( $event_type, $payload );
		return $this->mark_contact_bounced( $email, $metadata );
	}

	/**
	 * Validate webhook data
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_valid_webhook_data() {
		if ( ! is_array( $this->data ) || empty( $this->data ) ) {
			$this->log( 'Empty or invalid data received' );
			return false;
		}

		return true;
	}

	/**
	 * Extract event type from webhook data
	 *
	 * @since 1.0.0
	 *
	 * @return string|false
	 */
	private function extract_event_type() {
		// Check for MessageBounced event (has different structure)
		if ( isset( $this->data['original_message'] ) && isset( $this->data['bounce'] ) ) {
			return 'MessageBounced';
		}

		// Check for status-based events (MessageSent, MessageDelayed, MessageDeliveryFailed, MessageHeld)
		if ( isset( $this->data['status'] ) ) {
			$status = $this->data['status'];
			switch ( strtolower( $status ) ) {
				case 'deliveryfailed':
					return 'MessageDeliveryFailed';
				case 'delayed':
					return 'MessageDelayed';
				case 'held':
					return 'MessageHeld';
				case 'sent':
					return 'MessageSent';
				default:
					$this->log( "Unknown status: {$status}" );
					return false;
			}
		}

		$this->log( 'No valid event type found in data: ' . wp_json_encode( $this->data ) );
		return false;
	}

	/**
	 * Extract payload from webhook data
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function extract_payload() {
		return $this->data['payload'] ?? $this->data;
	}

	/**
	 * Check if event type is supported for bounce processing
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 *
	 * @return bool
	 */
	private function is_supported_bounce_event( $event_type ) {
		$bounce_statuses = apply_filters(
			'doublescale_postal_bounce_statuses',
			array( 'MessageBounced', 'MessageDeliveryFailed', 'MessageDelayed' )
		);

		if ( ! in_array( $event_type, $bounce_statuses, true ) ) {
			$this->log( "Event type '{$event_type}' not in bounce statuses" );
			return false;
		}

		return true;
	}

	/**
	 * Validate and extract email from payload
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Payload data.
	 *
	 * @return string|false
	 */
	private function extract_email_from_payload( $payload ) {
		$email = $this->find_email_in_payload( $payload );

		if ( ! is_email( $email ) ) {
			$this->log( 'Invalid email in event: ' . wp_json_encode( $payload ) );
			return false;
		}

		return $email;
	}

	/**
	 * Find email address in various payload locations
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Payload data.
	 *
	 * @return string|null
	 */
	private function find_email_in_payload( $payload ) {
		// For MessageBounced events, email is in original_message.to
		if ( isset( $payload['original_message']['to'] ) ) {
			return $payload['original_message']['to'];
		}

		// For status events (MessageSent, MessageDelayed, etc.), email is in message.to
		if ( isset( $payload['message']['to'] ) ) {
			return $payload['message']['to'];
		}

		// Fallback to other possible locations
		$email_paths = array(
			'message.rcpt_to',
			'to',
			'rcpt_to',
			'recipient',
			'email',
		);

		foreach ( $email_paths as $path ) {
			$email = $this->get_nested_value( $payload, $path );
			if ( ! empty( $email ) ) {
				return $email;
			}
		}

		return null;
	}

	/**
	 * Get nested value from array using dot notation
	 *
	 * @since 1.0.0
	 *
	 * @param array  $array Array to search.
	 * @param string $path Dot notation path.
	 *
	 * @return mixed|null
	 */
	private function get_nested_value( $array, $path ) {
		$keys  = explode( '.', $path );
		$value = $array;

		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
				return null;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Build bounce metadata from event and payload
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 * @param array  $payload Payload data.
	 *
	 * @return array
	 */
	private function build_bounce_metadata( $event_type, $payload ) {
		$metadata = array(
			'provider'    => 'postal',
			'timestamp'   => $this->extract_timestamp( $payload ),
			'bounce_type' => $this->determine_bounce_type( $event_type, $payload ),
			'reason'      => $this->get_bounce_reason( $payload, $event_type ),
		);

		$this->add_diagnostic_info( $metadata, $payload );
		$this->add_tracking_info( $metadata, $payload );
		$this->add_server_info( $metadata, $payload );

		return $metadata;
	}

	/**
	 * Extract timestamp from payload
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Payload data.
	 *
	 * @return int
	 */
	private function extract_timestamp( $payload ) {
		$timestamp = $payload['timestamp'] ?? $payload['time'] ?? null;
		return $this->parse_timestamp( $timestamp );
	}

	/**
	 * Add diagnostic information to metadata
	 *
	 * @since 1.0.0
	 *
	 * @param array $metadata Metadata array (passed by reference).
	 * @param array $payload Payload data.
	 *
	 * @return void
	 */
	private function add_diagnostic_info( &$metadata, $payload ) {
		$diagnostic_fields = array( 'details', 'bounce_details', 'output' );

		foreach ( $diagnostic_fields as $field ) {
			if ( ! empty( $payload[ $field ] ) ) {
				$metadata['diagnostic_code'] = $payload[ $field ];
				break; // Use first available diagnostic info
			}
		}
	}

	/**
	 * Add tracking information to metadata
	 *
	 * @since 1.0.0
	 *
	 * @param array $metadata Metadata array (passed by reference).
	 * @param array $payload Payload data.
	 *
	 * @return void
	 */
	private function add_tracking_info( &$metadata, $payload ) {
		// Message ID
		$message_id = $this->get_nested_value( $payload, 'message.id' ) ?? $payload['message_id'] ?? null;
		if ( ! empty( $message_id ) ) {
			$metadata['message_id'] = $message_id;
		}

		// Message token
		$message_token = $this->get_nested_value( $payload, 'message.token' );
		if ( ! empty( $message_token ) ) {
			$metadata['message_token'] = $message_token;
		}

		// Subject
		$subject = $this->get_nested_value( $payload, 'message.subject' );
		if ( ! empty( $subject ) ) {
			$metadata['subject'] = $subject;
		}

		// Bounce code
		$bounce_code = $payload['bounce_code'] ?? $payload['code'] ?? null;
		if ( ! empty( $bounce_code ) ) {
			$metadata['bounce_code'] = $bounce_code;
		}
	}

	/**
	 * Add server information to metadata
	 *
	 * @since 1.0.0
	 *
	 * @param array $metadata Metadata array (passed by reference).
	 * @param array $payload Payload data.
	 *
	 * @return void
	 */
	private function add_server_info( &$metadata, $payload ) {
		if ( ! empty( $payload['server'] ) ) {
			$metadata['server'] = $payload['server'];
		}
	}


	/**
	 * Determine bounce type based on event type and payload details
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 * @param array  $payload Payload data.
	 *
	 * @return string
	 */
	private function determine_bounce_type( $event_type, $payload ) {
		// MessageDelayed is typically a soft bounce
		if ( $event_type === 'MessageDelayed' ) {
			return 'soft';
		}

		// Check bounce code if available
		$bounce_code = $payload['bounce_code'] ?? $payload['code'] ?? '';
		if ( ! empty( $bounce_code ) ) {
			$bounce_code = (string) $bounce_code;

			// Hard bounce SMTP codes (5xx)
			if ( preg_match( '/^5\d{2}/', $bounce_code ) ) {
				return 'hard';
			}

			// Soft bounce SMTP codes (4xx)
			if ( preg_match( '/^4\d{2}/', $bounce_code ) ) {
				return 'soft';
			}

			// Specific hard bounce codes
			$hard_codes = array( '550', '551', '552', '553', '554', '556' );
			foreach ( $hard_codes as $code ) {
				if ( strpos( $bounce_code, $code ) !== false ) {
					return 'hard';
				}
			}

			// Specific soft bounce codes
			$soft_codes = array( '421', '422', '431', '432', '450', '451', '452' );
			foreach ( $soft_codes as $code ) {
				if ( strpos( $bounce_code, $code ) !== false ) {
					return 'soft';
				}
			}
		}

		// Check bounce details/output for indicators
		$details = strtolower( $payload['details'] ?? $payload['bounce_details'] ?? $payload['output'] ?? '' );
		if ( ! empty( $details ) ) {
			// Hard bounce indicators
			$hard_indicators = array(
				'permanent',
				'invalid',
				'unknown user',
				'user unknown',
				'not exist',
				'disabled',
				'rejected',
				'no such user',
				'mailbox unavailable',
			);

			foreach ( $hard_indicators as $indicator ) {
				if ( strpos( $details, $indicator ) !== false ) {
					return 'hard';
				}
			}

			// Soft bounce indicators
			$soft_indicators = array(
				'temporary',
				'full',
				'quota',
				'deferred',
				'try again',
				'mailbox full',
				'over quota',
				'temporarily',
			);

			foreach ( $soft_indicators as $indicator ) {
				if ( strpos( $details, $indicator ) !== false ) {
					return 'soft';
				}
			}
		}

		// Check status if available
		$status = strtolower( $payload['status'] ?? '' );
		if ( ! empty( $status ) ) {
			if ( in_array( $status, array( 'hard', 'permanent', 'failed' ), true ) ) {
				return 'hard';
			}
			if ( in_array( $status, array( 'soft', 'temporary', 'deferred', 'delayed' ), true ) ) {
				return 'soft';
			}
		}

		// Default based on event type
		switch ( $event_type ) {
			case 'MessageDelayed':
				return 'soft';
			case 'MessageBounced':
			case 'MessageDeliveryFailed':
			default:
				return 'hard'; // Default to hard bounce
		}
	}

	/**
	 * Get bounce reason from payload data
	 *
	 * @since 1.0.0
	 *
	 * @param array  $payload Payload data.
	 * @param string $event_type Event type.
	 *
	 * @return string
	 */
	private function get_bounce_reason( $payload, $event_type ) {
		// Check various fields where reason might be stored
		if ( ! empty( $payload['details'] ) ) {
			return sanitize_text_field( $payload['details'] );
		}

		if ( ! empty( $payload['bounce_details'] ) ) {
			return sanitize_text_field( $payload['bounce_details'] );
		}

		if ( ! empty( $payload['output'] ) ) {
			return sanitize_text_field( $payload['output'] );
		}

		if ( ! empty( $payload['reason'] ) ) {
			return sanitize_text_field( $payload['reason'] );
		}

		if ( ! empty( $payload['error'] ) ) {
			return sanitize_text_field( $payload['error'] );
		}

		// Construct reason from bounce code if available
		if ( ! empty( $payload['bounce_code'] ) || ! empty( $payload['code'] ) ) {
			$code = $payload['bounce_code'] ?? $payload['code'];
			return "Postal bounce - Code: {$code}";
		}

		// Default reasons based on event type
		switch ( $event_type ) {
			case 'MessageBounced':
				return 'Message bounced - delivery failed';
			case 'MessageDeliveryFailed':
				return 'Message delivery failed permanently';
			case 'MessageDelayed':
				return 'Message delivery delayed - temporary failure';
			default:
				return 'Email bounced via Postal';
		}
	}

	/**
	 * Parse timestamp from various formats
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $timestamp Timestamp in various formats.
	 *
	 * @return int
	 */
	private function parse_timestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return time();
		}

		// If it's already a Unix timestamp
		if ( is_numeric( $timestamp ) && $timestamp > 1000000000 ) {
			return (int) $timestamp;
		}

		// Try to parse as date string
		$parsed = strtotime( $timestamp );
		if ( $parsed !== false ) {
			return $parsed;
		}

		// Try ISO 8601 format (common in APIs)
		$parsed = strtotime( str_replace( 'T', ' ', $timestamp ) );
		if ( $parsed !== false ) {
			return $parsed;
		}

		// Default to current time
		return time();
	}
}

// Initialize handler

