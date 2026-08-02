<?php
/**
 * Mailgun Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * MailgunBounceHandler class
 */
class MailgunBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'Mailgun';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Set up Mailgun webhooks for bounce and failure events';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://documentation.mailgun.com/en/latest/user_manual.html#webhooks';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'In Mailgun Dashboard → Webhooks, enable \"Permanent Failure\" and \"Temporary Failure\" events with this URL.';

	/**
	 * Handle Mailgun webhook
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function handle() {
		if ( ! is_array( $this->data ) || empty( $this->data ) ) {
			$this->log( 'Empty or invalid data received' );
			return false;
		}

		// Mailgun uses 'event-data' structure
		$event_data = $this->data['event-data'] ?? $this->data;

		if ( ! isset( $event_data['event'] ) ) {
			$this->log( 'No event type found in data' );
			return false;
		}

		$event_type = $event_data['event'];
		$email      = $event_data['recipient'] ?? null;

		if ( ! is_email( $email ) ) {
			$this->log( 'Invalid email in event: ' . wp_json_encode( $event_data ) );
			return false;
		}

		// Filterable bounce statuses
		$bounce_statuses = apply_filters(
			'doublescale_mailgun_bounce_statuses',
			array( 'bounced', 'failed', 'complained', 'unsubscribed' )
		);

		if ( ! in_array( $event_type, $bounce_statuses, true ) ) {
			$this->log( "Event type '{$event_type}' not in bounce statuses" );
			return false;
		}

		$metadata = array(
			'provider' => 'mailgun',
			'timestamp' => $event_data['timestamp'] ?? time(),
		);

		// Determine bounce type based on severity or event type
		if ( isset( $event_data['severity'] ) ) {
			$metadata['bounce_type'] = ( $event_data['severity'] === 'permanent' ) ? 'hard' : 'soft';
			$metadata['reason']      = ucfirst( $event_data['severity'] ) . ' failure';
		} else {
			// Classify based on event type
			if ( in_array( $event_type, array( 'failed', 'bounced' ), true ) ) {
				$metadata['bounce_type'] = 'hard';
				$metadata['reason']      = ucfirst( $event_type );
			} else {
				$metadata['bounce_type'] = 'hard';
				$metadata['reason']      = 'Mailgun ' . ucfirst( $event_type );
			}
		}

		// Add delivery status info if available
		if ( isset( $event_data['delivery-status'] ) ) {
			$delivery_status             = $event_data['delivery-status'];
			$metadata['diagnostic_code'] = $delivery_status['message'] ?? '';

			if ( isset( $delivery_status['description'] ) ) {
				$metadata['reason'] = $delivery_status['description'];
			}
		}

		// Add reason from message if available
		if ( isset( $event_data['reason'] ) ) {
			$metadata['reason'] = $event_data['reason'];
		}

		return $this->mark_contact_bounced( $email, $metadata );
	}
}
