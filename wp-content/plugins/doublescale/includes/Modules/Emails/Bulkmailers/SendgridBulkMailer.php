<?php
/**
 * SendGrid Bulk Mailer
 *
 * Handles bulk email sending via SendGrid's personalization feature.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails\Bulkmailers
 */

namespace DoubleScale\Modules\Emails\Bulkmailers;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * SendgridBulkMailer class
 *
 * @since 1.0.0
 */
class SendgridBulkMailer extends AbstractBulkMailer {

	/**
	 * Mailer slug identifier
	 *
	 * @var string
	 */
	protected $slug = 'sendgrid';

	/**
	 * Maximum recipients per batch (SendGrid limit: 1000 personalizations)
	 *
	 * @var int
	 */
	protected $max_batch_size = 1000;

	/**
	 * Whether this mailer supports tracking
	 *
	 * @var bool
	 */
	protected $supports_tracking = true;


	/**
	 * Convert Plugin merge tags to SendGrid substitution format
	 *
	 * SendGrid uses {{key}} syntax for substitutions.
	 * Converts {{contact:first_name}} to {{first_name}}
	 *
	 * @param string $content Content with Plugin merge tags
	 *
	 * @return string Content with SendGrid substitution variables
	 */
	public function convert_merge_tags( $content ) {
		// Convert {{group:field_name}} to {{field_name}} for all groups
		$content = preg_replace(
			'/\{\{([a-zA-Z0-9_]+):([a-zA-Z0-9_]+)\}\}/',
			'{{$2}}',
			$content
		);

		return $content;
	}

	/**
	 * Check if SendGrid is properly configured and available
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->get_account_api() !== null;
	}

	/**
	 * Send a batch of emails via SendGrid
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

		// Get SendGrid Account Api (use from_email for smart routing)
		$from_email  = $batch_data['from_email'] ?? null;
		$account_api = $this->get_account_api( $from_email );

		if ( ! $account_api ) {
			return new WP_Error(
				'sendgrid_not_configured',
				__( 'SendGrid is not properly configured.', 'doublescale' )
			);
		}

		// Check if send_batch method exists
		if ( ! method_exists( $account_api, 'send_batch' ) ) {
			return new WP_Error(
				'sendgrid_batch_not_supported',
				__( 'SendGrid batch sending is not available. Please update smtp.', 'doublescale' )
			);
		}

		// Build headers
		$headers = $this->build_headers( $batch_data );

		// Prepare batch args for SendGrid
		$sendgrid_args = array(
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
			$sendgrid_args['text'] = $batch_data['text'];
		}

		// Add reply-to
		if ( ! empty( $batch_data['reply_to'] ) ) {
			$sendgrid_args['reply_to'] = $batch_data['reply_to'];
		}

		// Add categories/tags for campaign tracking
		if ( ! empty( $batch_data['tags'] ) ) {
			$sendgrid_args['categories'] = $batch_data['tags'];
		} elseif ( ! empty( $batch_data['campaign_id'] ) ) {
			$sendgrid_args['categories'] = array( 'campaign-' . $batch_data['campaign_id'] );
		}

		// Log attempt
		$this->log_send_attempt( $batch_data );

		// Send via SendGrid
		$result = $account_api->send_batch( $sendgrid_args );

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
	 * Build headers for SendGrid request
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
				// Use SendGrid substitution variable for personalized unsubscribe
				$headers['List-Unsubscribe']      = '{{unsubscribe_url}}';
				$headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
			}
		}

		return $headers;
	}
}
