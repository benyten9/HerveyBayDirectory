<?php
/**
 * SMTP2GO Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * Smtp2goBounceHandler class
 */
class Smtp2goBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'SMTP2GO';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Configure SMTP2GO webhook for bounce events';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://support.smtp2go.com/hc/en-gb/articles/223076368-Webhook-Reference';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'Add webhook URL in SMTP2GO Dashboard → Settings → Webhooks.';

	/**
	 * Handle SMTP2GO webhook
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

		$email = $this->extract_email_address();
		if ( ! $email ) {
			return false;
		}

		if ( ! $this->is_supported_bounce_event( $event_type ) ) {
			return false;
		}

		$metadata = $this->build_bounce_metadata( $event_type );
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
		$event_type = $this->data['event_type'] ?? $this->data['event'] ?? null;

		if ( empty( $event_type ) ) {
			$this->log( 'No event type found in data: ' . wp_json_encode( $this->data ) );
			return false;
		}

		return $event_type;
	}

	/**
	 * Extract and validate email address from webhook data
	 *
	 * @since 1.0.0
	 *
	 * @return string|false
	 */
	private function extract_email_address() {
		$email_fields = array( 'email_address', 'email', 'recipient' );
		
		foreach ( $email_fields as $field ) {
			$email = $this->data[ $field ] ?? null;
			if ( is_email( $email ) ) {
				return $email;
			}
		}

		$this->log( 'Invalid email in event: ' . wp_json_encode( $this->data ) );
		return false;
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
			'doublescale_smtp2go_bounce_statuses',
			array( 'bounce', 'hard_bounce', 'soft_bounce', 'blocked', 'reject' )
		);

		if ( ! in_array( $event_type, $bounce_statuses, true ) ) {
			$this->log( "Event type '{$event_type}' not in bounce statuses" );
			return false;
		}

		return true;
	}

	/**
	 * Build bounce metadata from webhook data
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 *
	 * @return array
	 */
	private function build_bounce_metadata( $event_type ) {
		$bounce_code = $this->extract_bounce_code();
		
		$metadata = array(
			'provider'    => 'smtp2go',
			'timestamp'   => $this->extract_timestamp(),
			'bounce_type' => $this->determine_bounce_type( $event_type, $bounce_code ),
			'reason'      => $this->get_bounce_reason( $this->data, $event_type ),
		);

		$this->add_diagnostic_info( $metadata );
		$this->add_tracking_info( $metadata );

		return $metadata;
	}

	/**
	 * Extract bounce code from webhook data
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	private function extract_bounce_code() {
		return $this->data['bounce_code'] ?? $this->data['code'] ?? null;
	}

	/**
	 * Extract timestamp from webhook data
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	private function extract_timestamp() {
		$timestamp = $this->data['timestamp'] ?? $this->data['date'] ?? null;
		return $this->parse_timestamp( $timestamp );
	}

	/**
	 * Add diagnostic information to metadata
	 *
	 * @since 1.0.0
	 *
	 * @param array $metadata Metadata array (passed by reference).
	 *
	 * @return void
	 */
	private function add_diagnostic_info( &$metadata ) {
		$diagnostic_fields = array( 'bounce_code', 'bounce_message' );
		
		foreach ( $diagnostic_fields as $field ) {
			if ( ! empty( $this->data[ $field ] ) ) {
				$metadata['diagnostic_code'] = $this->data[ $field ];
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
	 *
	 * @return void
	 */
	private function add_tracking_info( &$metadata ) {
		$tracking_fields = array(
			'message_id' => 'message_id',
			'smtp_id'    => 'smtp_id',
		);

		foreach ( $tracking_fields as $source_field => $meta_field ) {
			if ( ! empty( $this->data[ $source_field ] ) ) {
				$metadata[ $meta_field ] = $this->data[ $source_field ];
			}
		}
	}

	/**
	 * Determine bounce type based on event type and bounce code
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 * @param mixed  $bounce_code Bounce code.
	 *
	 * @return string
	 */
	private function determine_bounce_type( $event_type, $bounce_code ) {
		// Hard bounce event types
		$hard_bounce_events = array( 'hard_bounce', 'reject' );

		if ( in_array( $event_type, $hard_bounce_events, true ) ) {
			return 'hard';
		}

		// Soft bounce event types
		if ( $event_type === 'soft_bounce' ) {
			return 'soft';
		}

		// For generic 'bounce' events, check bounce code
		if ( $event_type === 'bounce' && ! empty( $bounce_code ) ) {
			$bounce_code = (string) $bounce_code;

			// Hard bounce codes (5xx SMTP codes)
			if ( strpos( $bounce_code, '5' ) === 0 ) {
				return 'hard';
			}

			// Soft bounce codes (4xx SMTP codes)
			if ( strpos( $bounce_code, '4' ) === 0 ) {
				return 'soft';
			}

			// Specific hard bounce codes
			$hard_codes = array( '550', '551', '552', '553', '554' );
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

		// Default to hard bounce for blocked emails
		if ( $event_type === 'blocked' ) {
			return 'hard';
		}

		// Default to hard bounce if we can't determine
		return 'hard';
	}

	/**
	 * Get bounce reason from event data
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data Event data.
	 * @param string $event_type Event type.
	 *
	 * @return string
	 */
	private function get_bounce_reason( $data, $event_type ) {
		// Check various fields where reason might be stored
		if ( ! empty( $data['bounce_message'] ) ) {
			return sanitize_text_field( $data['bounce_message'] );
		}

		if ( ! empty( $data['reason'] ) ) {
			return sanitize_text_field( $data['reason'] );
		}

		if ( ! empty( $data['error_message'] ) ) {
			return sanitize_text_field( $data['error_message'] );
		}

		if ( ! empty( $data['message'] ) ) {
			return sanitize_text_field( $data['message'] );
		}

		// Construct reason from bounce code if available
		if ( ! empty( $data['bounce_code'] ) ) {
			$code = $data['bounce_code'];
			return "SMTP2GO bounce (Code: {$code})";
		}

		// Default reasons based on event type
		switch ( $event_type ) {
			case 'hard_bounce':
				return 'Hard bounce - permanent delivery failure';
			case 'soft_bounce':
				return 'Soft bounce - temporary delivery failure';
			case 'blocked':
				return 'Email blocked by recipient server';
			case 'reject':
				return 'Email rejected by SMTP2GO';
			case 'bounce':
			default:
				return 'Email bounced';
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

		// Default to current time
		return time();
	}
}

// Initialize handler

