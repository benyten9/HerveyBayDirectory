<?php
/**
 * Pepipost Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * PepipostBounceHandler class
 */
class PepipostBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'Netcore Email Api (Pepipost)';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Configure Netcore Email Api (formerly Pepipost) webhook for bounce tracking';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://help.netcorecloud.com/docs/email-api-webhooks';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'Add this webhook URL in Netcore Cloud Dashboard → Settings → Webhooks.';

	/**
	 * Handle Pepipost bounce webhook
	 *
	 * @since 1.0.0
	 *
	 * @return bool|array
	 */
	public function handle() {
		// Validate data structure.
		if ( empty( $this->data ) || ! is_array( $this->data ) ) {
			$this->log( 'Empty or invalid webhook data received', 'warning' );
			return false;
		}

		// Pepipost sends bounces as an array (can batch up to 500 events).
		$results       = array();
		$success_count = 0;
		$total_count   = count( $this->data );

		foreach ( $this->data as $index => $event ) {
			// Validate event type.
			if ( ! isset( $event['EVENT'] ) || $event['EVENT'] !== 'bounced' ) {
				$this->log(
					sprintf( 'Skipping non-bounce event at index %d: %s', $index, $event['EVENT'] ?? 'missing' ),
					'info'
				);
				continue;
			}

			// Extract email address.
			$email = $event['EMAIL'] ?? '';
			if ( empty( $email ) ) {
				$this->log( sprintf( 'Missing EMAIL field at index %d', $index ), 'warning' );
				continue;
			}

			// Determine bounce type.
			$bounce_type_raw = $event['BOUNCE_TYPE'] ?? '';
			$bounce_type     = $this->classify_bounce( $bounce_type_raw );

			// Extract bounce details.
			$bounce_reason    = $event['BOUNCE_REASON'] ?? 'Email bounced';
			$response         = $event['RESPONSE'] ?? '';
			$timestamp        = isset( $event['TIMESTAMP'] ) ? (int) $event['TIMESTAMP'] : time();
			$bounce_reason_id = $event['BOUNCE_REASONID'] ?? '';
			$trans_id         = $event['TRANSID'] ?? '';
			$from_address     = $event['FROMADDRESS'] ?? '';

			// Build metadata array.
			$metadata = array(
				'provider'         => 'pepipost',
				'bounce_type'      => $bounce_type,
				'reason'           => $bounce_reason,
				'diagnostic_code'  => $response,
				'timestamp'        => $timestamp,
				'bounce_reason_id' => $bounce_reason_id,
				'transaction_id'   => $trans_id,
				'from_address'     => $from_address,
			);

			// Process the bounce.
			$result = $this->mark_contact_bounced( $email, $metadata );

			$results[ $index ] = array(
				'email'   => $email,
				'success' => $result,
			);

			if ( $result ) {
				$success_count++;
			}
		}

		// Log summary.
		$this->log(
			sprintf(
				'Processed %d/%d bounce events from Pepipost',
				$success_count,
				$total_count
			),
			'info',
			array(
				'total'   => $total_count,
				'success' => $success_count,
				'failed'  => $total_count - $success_count,
			)
		);

		return $results;
	}

	/**
	 * Classify bounce based on BOUNCE_TYPE
	 *
	 * @since 1.0.0
	 *
	 * @param string $bounce_type_raw Pepipost BOUNCE_TYPE value.
	 *
	 * @return string 'hard' or 'soft'
	 */
	private function classify_bounce( $bounce_type_raw ) {
		// Pepipost uses uppercase HARDBOUNCE and SOFTBOUNCE.
		if ( $bounce_type_raw === 'HARDBOUNCE' ) {
			return 'hard';
		}

		if ( $bounce_type_raw === 'SOFTBOUNCE' ) {
			return 'soft';
		}

		// Default to soft if unknown.
		$this->log(
			sprintf( 'Unknown BOUNCE_TYPE: %s, defaulting to soft', $bounce_type_raw ),
			'warning',
			array( 'bounce_type_raw' => $bounce_type_raw )
		);

		return 'soft';
	}
}
