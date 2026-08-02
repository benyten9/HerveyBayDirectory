<?php
/**
 * AWS SES Bulk Mailer
 *
 * Handles bulk email sending via Amazon Simple Email Service (SES).
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails\Bulkmailers
 */

namespace DoubleScale\Modules\Emails\Bulkmailers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Traits\SmtpModuleActive;
use WP_Error;

/**
 * AwsBulkMailer class
 *
 * @since 1.0.0
 */
class AwsBulkMailer extends AbstractBulkMailer {

	use SmtpModuleActive;

	/**
	 * Mailer slug identifier
	 *
	 * @var string
	 */
	protected $slug = 'aws';

	/**
	 * Maximum recipients per batch (AWS SES recommended limit)
	 *
	 * Note: AWS SES doesn't have a native batch Api. This sends individual emails.
	 * The actual limit depends on your SES sending rate and quotas.
	 *
	 * @var int
	 */
	protected $max_batch_size = 50;

	/**
	 * Whether this mailer supports tracking
	 *
	 * @var bool
	 */
	protected $supports_tracking = true;


	/**
	 * Convert CRM merge tags to AWS SES format
	 *
	 * AWS SES uses {{key}} format for template variables.
	 * Converts {{contact:first_name}} to {{first_name}}
	 * Converts {{tracking:hash_key}} to {{hash_key}}
	 *
	 * @param string $content Content with CRM merge tags
	 *
	 * @return string Content with AWS SES variables
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
	 * Check if AWS SES is properly configured and available
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! self::is_smtp_pro_mailers_active() ) {
			return false;
		}

		if ( ! self::is_smtp_pro_mailers_version_supported() ) {
			return false;
		}

		return $this->get_account_api() !== null;
	}

	/**
	 * Send a batch of emails via AWS SES
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

		// Get AWS Account Api (use from_email for smart routing)
		$from_email  = $batch_data['from_email'] ?? null;
		$account_api = $this->get_account_api( $from_email );

		if ( ! $account_api ) {
			return new WP_Error(
				'aws_not_configured',
				__( 'AWS SES is not properly configured.', 'doublescale' )
			);
		}

		// Check if send_batch method exists
		if ( ! method_exists( $account_api, 'send_batch' ) ) {
			return new WP_Error(
				'aws_batch_not_supported',
				__( 'AWS SES batch sending is not available in this build. Update doublescale-pro or ensure the SMTP module includes AWS batch support.', 'doublescale' )
			);
		}

		// Build headers
		$headers = $this->build_headers( $batch_data );

		// Prepare batch args for AWS SES
		$aws_args = array(
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
			$aws_args['text'] = $batch_data['text'];
		}

		// Add reply-to
		if ( ! empty( $batch_data['reply_to'] ) ) {
			$aws_args['reply_to'] = $batch_data['reply_to'];
		}

		// Add tags for campaign tracking
		if ( ! empty( $batch_data['tags'] ) ) {
			$aws_args['tags'] = $batch_data['tags'];
		} elseif ( ! empty( $batch_data['campaign_id'] ) ) {
			$aws_args['tags'] = array( 'campaign-' . $batch_data['campaign_id'] );
		}

		// Log attempt
		$this->log_send_attempt( $batch_data );

		// Send via AWS SES
		$result = $account_api->send_batch( $aws_args );

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
	 * Build headers for AWS SES request
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
				// Use AWS template variable for personalized unsubscribe
				$headers['List-Unsubscribe']      = '{{unsubscribe_url}}';
				$headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
			}
		}

		return $headers;
	}
}
