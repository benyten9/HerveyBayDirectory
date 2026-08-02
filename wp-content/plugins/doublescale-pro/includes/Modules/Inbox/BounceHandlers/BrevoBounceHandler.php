<?php
/**
 * Brevo (Sendinblue) Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * BrevoBounceHandler class
 */
class BrevoBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'Brevo';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Set up Brevo (formerly Sendinblue) bounce handling';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://developers.brevo.com/docs/webhooks';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'In Brevo Dashboard → Transactional → Settings → Webhooks, add this URL for bounce events.';

	/**
	 * Handle Brevo webhook
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
		// Brevo can send single event or array of events
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

		$email = $this->extract_email_from_event( $event );
		if ( ! $email ) {
			return false;
		}

		$event_type = $event['event'];
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
		if ( ! is_array( $event ) || ! isset( $event['event'] ) ) {
			$this->log( 'Invalid event structure: ' . wp_json_encode( $event ) );
			return false;
		}

		return true;
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
		$email = $event['email'] ?? null;

		if ( ! is_email( $email ) ) {
			$this->log( 'Invalid email in event: ' . wp_json_encode( $event ) );
			return false;
		}

		return $email;
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
			'doublescale_brevo_bounce_statuses',
			array( 'hard_bounce', 'soft_bounce', 'blocked', 'invalid_email' )
		);

		if ( ! in_array( $event_type, $bounce_statuses, true ) ) {
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
			'provider'    => 'brevo',
			'timestamp'   => $event['ts'] ?? time(),
			'bounce_type' => $this->determine_bounce_type( $event_type ),
			'reason'      => $this->get_bounce_reason( $event, $this->get_default_reason( $event_type ) ),
		);

		// Add optional diagnostic information
		$this->add_diagnostic_info( $metadata, $event );
		$this->add_tracking_info( $metadata, $event );

		return $metadata;
	}

	/**
	 * Determine bounce type from event type
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 *
	 * @return string
	 */
	private function determine_bounce_type( $event_type ) {
		$hard_bounce_types = array( 'hard_bounce', 'invalid_email' );
		$soft_bounce_types = array( 'soft_bounce', 'blocked' );

		if ( in_array( $event_type, $hard_bounce_types, true ) ) {
			return 'hard';
		}

		if ( in_array( $event_type, $soft_bounce_types, true ) ) {
			return 'soft';
		}

		// Default to hard bounce for unknown types
		return 'hard';
	}

	/**
	 * Get default reason based on event type
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type.
	 *
	 * @return string
	 */
	private function get_default_reason( $event_type ) {
		$default_reasons = array(
			'hard_bounce'   => 'Hard bounce',
			'soft_bounce'   => 'Soft bounce',
			'blocked'       => 'Email blocked',
			'invalid_email' => 'Invalid email',
		);

		return $default_reasons[ $event_type ] ?? 'Brevo ' . ucfirst( str_replace( '_', ' ', $event_type ) );
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
		if ( isset( $event['reason'] ) ) {
			$metadata['diagnostic_code'] = $event['reason'];
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
		if ( isset( $event['subject'] ) ) {
			$metadata['subject'] = $event['subject'];
		}

		if ( isset( $event['message-id'] ) ) {
			$metadata['message_id'] = $event['message-id'];
		}
	}

	/**
	 * Get bounce reason from event data
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event Event data.
	 * @param string $default Default reason.
	 *
	 * @return string
	 */
	private function get_bounce_reason( $event, $default = 'Email bounced' ) {
		// Check various fields where reason might be stored
		if ( ! empty( $event['reason'] ) ) {
			return sanitize_text_field( $event['reason'] );
		}

		if ( ! empty( $event['error'] ) ) {
			return sanitize_text_field( $event['error'] );
		}

		if ( ! empty( $event['bounce_reason'] ) ) {
			return sanitize_text_field( $event['bounce_reason'] );
		}

		// For specific event types, provide more descriptive reasons
		switch ( $event['event'] ?? '' ) {
			case 'hard_bounce':
				return 'Hard bounce - permanent delivery failure';
			case 'soft_bounce':
				return 'Soft bounce - temporary delivery failure';
			case 'blocked':
				return 'Email blocked by recipient server';
			case 'invalid_email':
				return 'Invalid email address format';
			default:
				return $default;
		}
	}
}

// Initialize handler

