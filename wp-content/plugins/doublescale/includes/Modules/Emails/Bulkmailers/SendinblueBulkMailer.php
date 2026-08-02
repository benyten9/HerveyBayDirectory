<?php
/**
 * Sendinblue (Brevo) Bulk Mailer
 *
 * Handles bulk email sending via Brevo's transactional email Api.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails\Bulkmailers
 */

namespace DoubleScale\Modules\Emails\Bulkmailers;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * SendinblueBulkMailer class
 *
 * @since 1.0.0
 */
class SendinblueBulkMailer extends AbstractBulkMailer {

	/**
	 * Mailer slug identifier
	 *
	 * @var string
	 */
	protected $slug = 'sendinblue';

	/**
	 * Maximum recipients per batch
	 * Brevo recommends smaller batches for transactional emails
	 *
	 * @var int
	 */
	protected $max_batch_size = 500;

	/**
	 * Whether this mailer supports tracking
	 *
	 * @var bool
	 */
	protected $supports_tracking = true;


	/**
	 * Convert Plugin merge tags to Brevo personalization format
	 *
	 * Brevo uses {{params.key}} syntax for personalization.
	 * Converts {{contact:first_name}} to {{params.first_name}}
	 *
	 * @param string $content Content with Plugin merge tags
	 *
	 * @return string Content with Brevo personalization variables
	 */
	public function convert_merge_tags( $content ) {
		// Convert {{group:field_name}} to {{params.field_name}} for all groups
		$content = preg_replace(
			'/\{\{([a-zA-Z0-9_]+):([a-zA-Z0-9_]+)\}\}/',
			'{{params.$2}}',
			$content
		);

		return $content;
	}

	/**
	 * Check if Sendinblue/Brevo is properly configured and available
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->get_account_api() !== null;
	}

	/**
	 * Send a batch of emails via Brevo
	 *
	 * @param array $batch_data Batch email data
	 *
	 * @return array|WP_Error Result array or WP_Error on failure
	 */
	public function send_batch( $batch_data ) {
		// Validate batch data
		$validation = $this->validate_batch_data( $batch_data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Get Sendinblue/Brevo Account Api (use from_email for smart routing)
		$from_email  = $batch_data['from_email'] ?? null;
		$account_api = $this->get_account_api( $from_email );

		if ( ! $account_api ) {
			return new WP_Error(
				'sendinblue_not_configured',
				__( 'Sendinblue/Brevo is not properly configured.', 'doublescale' )
			);
		}

		// Check if send_batch method exists
		if ( ! method_exists( $account_api, 'send_batch' ) ) {
			return new WP_Error(
				'sendinblue_batch_not_supported',
				__( 'Sendinblue/Brevo batch sending is not available. Please update smtp.', 'doublescale' )
			);
		}

		// Build headers
		$headers = $this->build_headers( $batch_data );

		// Prepare batch args for Brevo
		$sendinblue_args = array(
			'from_email'          => $batch_data['from_email'] ?? get_option( 'admin_email' ),
			'from_name'           => $batch_data['from_name'] ?? get_bloginfo( 'name' ),
			'to'                  => $batch_data['recipients'],
			'subject'             => $batch_data['subject'],
			'html'                => $batch_data['html'],
			'recipient_variables' => $batch_data['recipient_variables'] ?? array(),
			'headers'             => $headers,
		);

		// Add plain text version if provided
		if ( ! empty( $batch_data['text'] ) ) {
			$sendinblue_args['text'] = $batch_data['text'];
		}

		// Add reply-to
		if ( ! empty( $batch_data['reply_to'] ) ) {
			$sendinblue_args['reply_to'] = $batch_data['reply_to'];
		}

		// Add tags for campaign tracking
		if ( ! empty( $batch_data['tags'] ) ) {
			$sendinblue_args['tags'] = $batch_data['tags'];
		} elseif ( ! empty( $batch_data['campaign_id'] ) ) {
			$sendinblue_args['tags'] = array( 'campaign-' . $batch_data['campaign_id'] );
		}

		// Log attempt
		$this->log_send_attempt( $batch_data );

		// Send via Brevo
		$result = $account_api->send_batch( $sendinblue_args );

		if ( is_wp_error( $result ) ) {
			$this->log_send_failure( $batch_data, $result );
			return $result;
		}

		// Log success
		$message_id = $result['id'] ?? '';
		$this->log_send_success( $batch_data, $message_id );

		return array(
			'success'    => true,
			'message_id' => $message_id,
			'message'    => $result['message'] ?? __( 'Batch sent successfully', 'doublescale' ),
			'sent_count' => $result['sent_count'] ?? count( $batch_data['recipients'] ),
			'failed'     => $result['failed'] ?? array(),
		);
	}

	/**
	 * Build headers for Brevo request
	 *
	 * @param array $batch_data Batch email data
	 *
	 * @return array Headers array
	 */
	private function build_headers( $batch_data ) {
		$headers = array();

		// List-Unsubscribe headers (one-click unsubscribe for Gmail/Yahoo compliance)
		if ( ! empty( $batch_data['recipient_variables'] ) ) {
			$first_email = reset( $batch_data['recipients'] );
			if ( ! empty( $batch_data['recipient_variables'][ $first_email ]['unsubscribe_url'] ) ) {
				// Use Brevo params variable for personalized unsubscribe
				$headers['List-Unsubscribe']      = '{{params.unsubscribe_url}}';
				$headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
			}
		}

		return $headers;
	}
}
