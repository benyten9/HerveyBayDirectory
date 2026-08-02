<?php
/**
 * SparkPost Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * SparkpostBounceHandler class
 */
class SparkpostBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'SparkPost';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Set up SparkPost webhooks for bounce handling';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://developers.sparkpost.com/api/webhooks/';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'In SparkPost Dashboard → Webhooks → Create Webhook, select \"Bounce\" and \"Out of Band\" events.';

	/**
	 * Hard bounce classification codes
	 *
	 * @var array
	 */
	private $hard_bounce_classes = array( '10', '25', '30', '80', '90' );

	/**
	 * Handle SparkPost bounce webhook
	 *
	 * @since 1.0.0
	 *
	 * @return bool|array
	 */
	public function handle() {
		// Validate data structure.
		if ( empty( $this->data ) ) {
			$this->log( 'Empty webhook data received', 'warning' );
			return false;
		}

		// SparkPost wraps events in msys.message_event structure.
		if ( ! isset( $this->data['msys']['message_event'] ) ) {
			$this->log( 'Invalid SparkPost webhook structure - missing msys.message_event', 'warning' );
			return false;
		}

		$event = $this->data['msys']['message_event'];

		// Verify event type.
		if ( ! isset( $event['type'] ) || $event['type'] !== 'bounce' ) {
			$this->log(
				sprintf( 'Non-bounce event received: %s', $event['type'] ?? 'unknown' ),
				'info'
			);
			return false;
		}

		// Extract email address.
		$email = $event['rcpt_to'] ?? '';
		if ( empty( $email ) ) {
			$this->log( 'Missing rcpt_to field in bounce event', 'warning' );
			return false;
		}

		// Determine bounce type using bounce_class.
		$bounce_class = isset( $event['bounce_class'] ) ? (string) $event['bounce_class'] : '1';
		$bounce_type  = $this->classify_bounce( $bounce_class );

		// Extract bounce details.
		$reason          = $event['reason'] ?? ( $event['raw_reason'] ?? 'Email bounced' );
		$error_code      = $event['error_code'] ?? '';
		$timestamp       = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : time();
		$num_retries     = $event['num_retries'] ?? '0';
		$message_id      = $event['message_id'] ?? '';
		$campaign_id     = $event['campaign_id'] ?? '';
		$transmission_id = $event['transmission_id'] ?? '';

		// Build diagnostic code from available data.
		$diagnostic_code = $error_code;
		if ( isset( $event['raw_reason'] ) && $event['raw_reason'] !== $reason ) {
			$diagnostic_code .= ' - ' . $event['raw_reason'];
		}

		// Build metadata array.
		$metadata = array(
			'provider'        => 'sparkpost',
			'bounce_type'     => $bounce_type,
			'reason'          => $reason,
			'diagnostic_code' => trim( $diagnostic_code, ' -' ),
			'timestamp'       => $timestamp,
			'bounce_class'    => $bounce_class,
			'error_code'      => $error_code,
			'num_retries'     => $num_retries,
			'message_id'      => $message_id,
			'campaign_id'     => $campaign_id,
			'transmission_id' => $transmission_id,
		);

		// Process the bounce.
		$result = $this->mark_contact_bounced( $email, $metadata );

		if ( $result ) {
			$this->log(
				sprintf(
					'Successfully processed %s bounce for %s (bounce_class: %s, retries: %s)',
					$bounce_type,
					$email,
					$bounce_class,
					$num_retries
				),
				'info',
				array(
					'email'        => $email,
					'bounce_class' => $bounce_class,
					'num_retries'  => $num_retries,
					'message_id'   => $message_id,
				)
			);
		}

		return $result;
	}

	/**
	 * Classify bounce based on bounce_class
	 *
	 * @since 1.0.0
	 *
	 * @param string $bounce_class SparkPost bounce_class value.
	 *
	 * @return string 'hard' or 'soft'
	 */
	private function classify_bounce( $bounce_class ) {
		// Check if bounce_class indicates a permanent failure.
		// Hard bounces: 10 (Invalid Recipient), 25 (Admin Failure), 30 (Generic No RCPT),
		// 80 (Subscribe), 90 (Unsubscribe).
		if ( in_array( $bounce_class, $this->hard_bounce_classes, true ) ) {
			return 'hard';
		}

		// All other bounce classes are considered temporary/soft bounces.
		return 'soft';
	}
}

