<?php
/**
 * Postmark Bounce Handler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\BounceHandlers;

use DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler;

/**
 * PostmarkBounceHandler class
 */
class PostmarkBounceHandler extends BounceHandler {

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name = 'Postmark';

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description = 'Configure Postmark bounce webhook';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = 'https://postmarkapp.com/developer/webhooks/bounce-webhook';

	/**
	 * Setup instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = 'Navigate to Postmark Dashboard → Servers → [Your Server] → Webhooks → Bounce, and paste this URL.';

	/**
	 * Hard bounce TypeCodes
	 *
	 * @var array
	 */
	private $hard_bounce_codes = array( 1, 16, 512, 100000, 100001, 100002, 100003, 100006, 100009 );

	/**
	 * Handle Postmark bounce webhook
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function handle() {
		// Validate data structure.
		if ( empty( $this->data ) ) {
			$this->log( 'Empty webhook data received', 'warning' );
			return false;
		}

		// Postmark sends single bounce event per webhook.
		if ( ! isset( $this->data['RecordType'] ) || $this->data['RecordType'] !== 'Bounce' ) {
			$this->log( 'Invalid RecordType: ' . ( $this->data['RecordType'] ?? 'missing' ), 'warning' );
			return false;
		}

		// Extract email address.
		$email = $this->data['Email'] ?? '';
		if ( empty( $email ) ) {
			$this->log( 'Missing email address in webhook data', 'warning' );
			return false;
		}

		// Determine bounce type using TypeCode.
		$type_code   = isset( $this->data['TypeCode'] ) ? (int) $this->data['TypeCode'] : 0;
		$bounce_type = $this->classify_bounce( $type_code );

		// Extract bounce details.
		$description  = $this->data['Description'] ?? 'Bounce notification';
		$details      = $this->data['Details'] ?? '';
		$bounced_at   = $this->data['BouncedAt'] ?? '';
		$type_name    = $this->data['Type'] ?? '';
		$name         = $this->data['Name'] ?? '';
		$message_id   = $this->data['MessageID'] ?? '';
		$can_activate = $this->data['CanActivate'] ?? false;

		// Build bounce reason.
		$reason = $name ? $name : $description;
		if ( $details ) {
			$reason .= ': ' . $details;
		}

		// Parse timestamp.
		$timestamp = $bounced_at ? strtotime( $bounced_at ) : time();

		// Build metadata array.
		$metadata = array(
			'provider'        => 'postmark',
			'bounce_type'     => $bounce_type,
			'reason'          => $reason,
			'diagnostic_code' => $details,
			'timestamp'       => $timestamp,
			'type_code'       => $type_code,
			'type_name'       => $type_name,
			'message_id'      => $message_id,
			'can_activate'    => $can_activate,
		);

		// Process the bounce.
		$result = $this->mark_contact_bounced( $email, $metadata );

		if ( $result ) {
			$this->log(
				sprintf(
					'Successfully processed %s bounce for %s (TypeCode: %d)',
					$bounce_type,
					$email,
					$type_code
				),
				'info',
				array(
					'email'      => $email,
					'type_code'  => $type_code,
					'message_id' => $message_id,
				)
			);
		}

		return $result;
	}

	/**
	 * Classify bounce based on TypeCode
	 *
	 * @since 1.0.0
	 *
	 * @param int $type_code Postmark TypeCode.
	 *
	 * @return string 'hard' or 'soft'
	 */
	private function classify_bounce( $type_code ) {
		// Check if TypeCode indicates a hard bounce.
		if ( in_array( $type_code, $this->hard_bounce_codes, true ) ) {
			return 'hard';
		}

		// Default to soft bounce for all other codes.
		return 'soft';
	}
}
