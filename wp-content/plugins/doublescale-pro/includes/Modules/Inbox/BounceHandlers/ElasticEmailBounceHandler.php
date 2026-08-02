<?php
/**
 * Elastic Email Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * ElasticEmailBounceHandler class
 */
class ElasticEmailBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'Elastic Email';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Set up Elastic Email bounce notifications';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://elasticemail.com/developers/webhooks';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'Configure webhook in Elastic Email Dashboard → Settings → Notification → Webhooks.';

	/**
	 * Handle Elastic Email webhook
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

		$events = $this->normalize_events_data();
		$processed_count = 0;

		foreach ( $events as $event ) {
			if ( $this->process_single_event( $event ) ) {
				$processed_count++;
			}
		}

		return $processed_count > 0;
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
	 * Normalize events data to consistent array format
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function normalize_events_data() {
		// Elastic Email can send single event or array of events
		return isset( $this->data['events'] ) ? $this->data['events'] : array( $this->data );
	}

	/**
	 * Process a single bounce event
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $event Event data.
	 *
	 * @return bool
	 */
	private function process_single_event( $event ) {
		// Guard clauses for event validation
		if ( ! $this->is_valid_event_structure( $event ) ) {
			return false;
		}

		$event_type = $this->extract_event_type( $event );
		if ( ! $event_type ) {
			return false;
		}

		$email = $this->extract_email_from_event( $event );
		if ( ! $email ) {
			return false;
		}

		if ( ! $this->is_supported_bounce_event( $event_type ) ) {
			return false;
		}

		// Build and process bounce metadata
		$metadata = $this->build_bounce_metadata( $event, $event_type );
		return $this->mark_contact_bounced( $email, $metadata );
	}

	/**
	 * Validate event structure
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $event Event data.
	 *
	 * @return bool
	 */
	private function is_valid_event_structure( $event ) {
		if ( ! is_array( $event ) ) {
			$this->log( 'Invalid event structure: ' . wp_json_encode( $event ) );
			return false;
		}

		return true;
	}

	/**
	 * Extract event type from event data
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Event data.
	 *
	 * @return string|false
	 */
	private function extract_event_type( $event ) {
		$event_type_fields = array( 'status', 'event', 'activity' );
		
		foreach ( $event_type_fields as $field ) {
			$event_type = $event[ $field ] ?? null;
			if ( ! empty( $event_type ) ) {
				return $event_type;
			}
		}

		$this->log( 'No event type found in data: ' . wp_json_encode( $event ) );
		return false;
	}

	/**
	 * Extract and validate email from event
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Event data.
	 *
	 * @return string|false
	 */
	private function extract_email_from_event( $event ) {
		$email_fields = array( 'to', 'email', 'recipient' );
		
		foreach ( $email_fields as $field ) {
			$email = $event[ $field ] ?? null;
			if ( is_email( $email ) ) {
				return $email;
			}
		}

		$this->log( 'Invalid email in event: ' . wp_json_encode( $event ) );
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
			'doublescale_elastic_email_bounce_statuses',
			array( 'bounced', 'bounce', 'hard_bounce', 'soft_bounce', 'blocked', 'rejected', 'error' )
		);

		if ( ! in_array( strtolower( $event_type ), array_map( 'strtolower', $bounce_statuses ), true ) ) {
			$this->log( "Event type '{$event_type}' not in bounce statuses" );
			return false;
		}

		return true;
	}

	/**
	 * Build bounce metadata from event
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event Event data.
	 * @param string $event_type Event type.
	 *
	 * @return array
	 */
	private function build_bounce_metadata( $event, $event_type ) {
		$metadata = array(
			'provider'    => 'elastic_email',
			'timestamp'   => $this->extract_timestamp( $event ),
			'bounce_type' => $this->determine_bounce_type( $event_type, $event ),
			'reason'      => $this->get_bounce_reason( $event, $event_type ),
		);

		$this->add_diagnostic_info( $metadata, $event );
		$this->add_tracking_info( $metadata, $event );

		return $metadata;
	}

	/**
	 * Extract timestamp from event data
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Event data.
	 *
	 * @return int
	 */
	private function extract_timestamp( $event ) {
		$timestamp = $event['date'] ?? $event['timestamp'] ?? null;
		return $this->parse_timestamp( $timestamp );
	}

	/**
	 * Add diagnostic information to metadata
	 *
	 * @since 1.0.0
	 *
	 * @param array $metadata Metadata array (passed by reference).
	 * @param array $event Event data.
	 *
	 * @return void
	 */
	private function add_diagnostic_info( &$metadata, $event ) {
		$diagnostic_fields = array( 'error', 'errorcode', 'bounce_code' );
		
		foreach ( $diagnostic_fields as $field ) {
			if ( ! empty( $event[ $field ] ) ) {
				$metadata['diagnostic_code'] = $event[ $field ];
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
	 * @param array $event Event data.
	 *
	 * @return void
	 */
	private function add_tracking_info( &$metadata, $event ) {
		// Message ID fields
		$message_id_fields = array( 'messageid', 'message_id' );
		foreach ( $message_id_fields as $field ) {
			if ( ! empty( $event[ $field ] ) ) {
				$metadata['message_id'] = $event[ $field ];
				break;
			}
		}

		// Transaction ID
		if ( ! empty( $event['transactionid'] ) ) {
			$metadata['transaction_id'] = $event['transactionid'];
		}

		// Subject
		if ( ! empty( $event['subject'] ) ) {
			$metadata['subject'] = $event['subject'];
		}
	}

	/**
	 * Determine bounce type based on event type and additional data
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 * @param array  $event Event data.
	 *
	 * @return string
	 */
	private function determine_bounce_type( $event_type, $event ) {
		$event_type = strtolower( $event_type );

		// Explicit hard bounce indicators
		$hard_bounce_events = array( 'hard_bounce', 'rejected', 'blocked' );
		if ( in_array( $event_type, $hard_bounce_events, true ) ) {
			return 'hard';
		}

		// Explicit soft bounce indicators
		if ( $event_type === 'soft_bounce' ) {
			return 'soft';
		}

		// Check bounce category if available
		$category = strtolower( $event['category'] ?? '' );
		if ( ! empty( $category ) ) {
			if ( in_array( $category, array( 'hard', 'hardbounce', 'permanent' ), true ) ) {
				return 'hard';
			}
			if ( in_array( $category, array( 'soft', 'softbounce', 'temporary' ), true ) ) {
				return 'soft';
			}
		}

		// Check error code patterns
		$error_code = $event['errorcode'] ?? $event['error'] ?? '';
		if ( ! empty( $error_code ) ) {
			$error_code = (string) $error_code;

			// Hard bounce SMTP codes (5xx)
			if ( preg_match( '/^5\d{2}/', $error_code ) ) {
				return 'hard';
			}

			// Soft bounce SMTP codes (4xx)
			if ( preg_match( '/^4\d{2}/', $error_code ) ) {
				return 'soft';
			}

			// Specific hard bounce patterns
			$hard_patterns = array( '550', '551', '552', '553', '554', '556' );
			foreach ( $hard_patterns as $pattern ) {
				if ( strpos( $error_code, $pattern ) !== false ) {
					return 'hard';
				}
			}

			// Specific soft bounce patterns
			$soft_patterns = array( '421', '422', '431', '432', '450', '451', '452' );
			foreach ( $soft_patterns as $pattern ) {
				if ( strpos( $error_code, $pattern ) !== false ) {
					return 'soft';
				}
			}
		}

		// Check bounce reason text for indicators
		$reason = strtolower( $event['error'] ?? $event['reason'] ?? '' );
		if ( ! empty( $reason ) ) {
			$hard_indicators = array( 'permanent', 'invalid', 'unknown', 'not exist', 'disabled', 'rejected' );
			foreach ( $hard_indicators as $indicator ) {
				if ( strpos( $reason, $indicator ) !== false ) {
					return 'hard';
				}
			}

			$soft_indicators = array( 'temporary', 'full', 'quota', 'deferred', 'try again' );
			foreach ( $soft_indicators as $indicator ) {
				if ( strpos( $reason, $indicator ) !== false ) {
					return 'soft';
				}
			}
		}

		// Default to hard bounce for generic bounce/error events
		return 'hard';
	}

	/**
	 * Get bounce reason from event data
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event Event data.
	 * @param string $event_type Event type.
	 *
	 * @return string
	 */
	private function get_bounce_reason( $event, $event_type ) {
		// Check various fields where reason might be stored
		if ( ! empty( $event['error'] ) ) {
			return sanitize_text_field( $event['error'] );
		}

		if ( ! empty( $event['reason'] ) ) {
			return sanitize_text_field( $event['reason'] );
		}

		if ( ! empty( $event['bounce_reason'] ) ) {
			return sanitize_text_field( $event['bounce_reason'] );
		}

		if ( ! empty( $event['message'] ) ) {
			return sanitize_text_field( $event['message'] );
		}

		// Construct reason from error code if available
		if ( ! empty( $event['errorcode'] ) ) {
			$code     = $event['errorcode'];
			$category = ! empty( $event['category'] ) ? ' (' . $event['category'] . ')' : '';
			return "Elastic Email bounce - Code: {$code}{$category}";
		}

		// Default reasons based on event type
		$event_type = strtolower( $event_type );
		switch ( $event_type ) {
			case 'hard_bounce':
				return 'Hard bounce - permanent delivery failure';
			case 'soft_bounce':
				return 'Soft bounce - temporary delivery failure';
			case 'blocked':
				return 'Email blocked by recipient server';
			case 'rejected':
				return 'Email rejected by Elastic Email';
			case 'error':
				return 'Email delivery error';
			case 'bounced':
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

