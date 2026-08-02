<?php
/**
 * SendGrid Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * SendgridBounceHandler class
 */
class SendgridBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'SendGrid';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Configure SendGrid Event Webhook for bounce notifications';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://docs.sendgrid.com/for-developers/tracking-events/getting-started-event-webhook';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'Go to SendGrid Dashboard → Settings → Mail Settings → Event Webhook. Enable "Bounced" and "Dropped" events, then paste the webhook URL.';

	/**
	 * Handle SendGrid webhook
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

		$processed = false;

		foreach ( $this->data as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['event'] ) ) {
				continue;
			}

			$event_type = $event['event'];
			$email      = $event['email'] ?? null;

			if ( ! is_email( $email ) ) {
				$this->log( 'Invalid email in event: ' . wp_json_encode( $event ) );
				continue;
			}

			$metadata = array(
				'reason'          => $event['reason'] ?? 'Unknown',
				'timestamp'       => $event['timestamp'] ?? time(),
				'provider'        => 'sendgrid',
				'diagnostic_code' => $event['reason'] ?? '',
			);

			switch ( $event_type ) {
				case 'bounce':
					$metadata['bounce_type'] = 'hard';
					$this->mark_contact_bounced( $email, $metadata );
					$processed = true;
					break;

				case 'dropped':
					$metadata['bounce_type'] = 'hard';
					$metadata['reason']      = 'Dropped by SendGrid: ' . $metadata['reason'];
					$this->mark_contact_bounced( $email, $metadata );
					$processed = true;
					break;

				case 'blocked':
					// Blocked can be treated as soft bounce
					$metadata['bounce_type'] = 'soft';
					$metadata['reason']      = 'Blocked by SendGrid: ' . $metadata['reason'];
					$this->mark_contact_bounced( $email, $metadata );
					$processed = true;
					break;
			}
		}

		return $processed;
	}
}
