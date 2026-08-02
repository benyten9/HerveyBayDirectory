<?php
/**
 * Abstract Curl Multi Mailer
 *
 * Base class for curl multi email mailers. Each mailer (SMTP2GO, etc.)
 * that doesn't support bulk Api but has HTTP Api should extend this class.
 *
 * This uses cURL Multi to send emails in parallel for better performance.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails\Curlmulti
 */

namespace DoubleScale\Modules\Emails\Curlmulti;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Emails\Traits\AccountApiHelper;
use WP_Error;

/**
 * AbstractCurlMultiMailer class
 *
 * @since 1.0.0
 */
abstract class AbstractCurlMultiMailer {

	use AccountApiHelper;

	/**
	 * Mailer slug identifier
	 *
	 * @var string
	 */
	protected $slug = '';

	/**
	 * Maximum concurrent requests
	 * Lower = safer but slower, Higher = faster but may hit rate limits
	 *
	 * @var int
	 */
	protected $max_concurrent = 10;

	/**
	 * Maximum recipients per batch
	 *
	 * @var int
	 */
	protected $max_batch_size = 100;

	/**
	 * Delay between batches in microseconds (for rate limiting)
	 *
	 * @var int
	 */
	protected $batch_delay = 1000000; // 1s

	/**
	 * Whether this mailer supports tracking
	 *
	 * @var bool
	 */
	protected $supports_tracking = true;

	/**
	 * Get mailer slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get maximum concurrent requests
	 *
	 * @return int
	 */
	public function get_max_concurrent() {
		return apply_filters(
			'doublescale_curl_multi_max_concurrent',
			$this->max_concurrent,
			$this->slug
		);
	}

	/**
	 * Get maximum batch size
	 *
	 * @return int
	 */
	public function get_max_batch_size() {
		return apply_filters(
			'doublescale_curl_multi_max_batch_size',
			$this->max_batch_size,
			$this->slug
		);
	}

	/**
	 * Check if this mailer supports tracking
	 *
	 * @return bool
	 */
	public function supports_tracking() {
		return $this->supports_tracking;
	}

	/**
	 * Convert Plugin merge tags to mailer-specific format
	 *
	 * Note: For curl multi, merge tags are typically processed before sending
	 * since each email is personalized individually.
	 *
	 * @param string $content Content with Plugin merge tags
	 * @param array  $variables Variables to replace
	 *
	 * @return string Content with replaced variables
	 */
	public function process_merge_tags( $content, $variables ) {
		foreach ( $variables as $key => $value ) {
			// Replace {{group:key}} format
			$content = preg_replace(
				'/\{\{[a-zA-Z0-9_]+:' . preg_quote( $key, '/' ) . '\}\}/',
				$value,
				$content
			);
			// Also replace simple %key% format
			$content = str_replace( '%' . $key . '%', $value, $content );
		}

		return $content;
	}

	/**
	 * Check if this mailer is properly configured and available
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * Get Api credentials
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return array|null Array with 'api_key' and other credentials, or null if not configured
	 */
	abstract protected function get_credentials( $from_email = null );

	/**
	 * Build request data for a single email
	 *
	 * @param array $email_data {
	 *     Email data for a single recipient.
	 *
	 *     @type string $to         Recipient email address
	 *     @type string $subject    Email subject
	 *     @type string $html       HTML body
	 *     @type string $text       Plain text body (optional)
	 *     @type string $from_email Sender email address
	 *     @type string $from_name  Sender name
	 *     @type string $reply_to   Reply-to address (optional)
	 * }
	 *
	 * @return array Request data array for the Api
	 */
	abstract protected function build_request_data( $email_data );

	/**
	 * Parse Api response for a single email
	 *
	 * @param string $response Raw response body
	 * @param int    $http_code HTTP response code
	 *
	 * @return array {
	 *     Parsed result.
	 *
	 *     @type bool   $success    Whether the email was sent successfully
	 *     @type string $message_id Message ID if available
	 *     @type string $error      Error message if failed
	 * }
	 */
	abstract protected function parse_response( $response, $http_code );

	/**
	 * Send a batch of emails using cURL Multi
	 *
	 * @param array $batch_data {
	 *     Batch email data.
	 *
	 *     @type string $subject             Email subject (with merge tags)
	 *     @type string $html                HTML body (with merge tags)
	 *     @type string $text                Plain text body (optional)
	 *     @type string $from_email          Sender email address
	 *     @type string $from_name           Sender name
	 *     @type string $reply_to            Reply-to address (optional)
	 *     @type array  $recipients          Array of recipient email addresses
	 *     @type array  $recipient_variables Associative array keyed by email with variable values
	 *     @type array  $tags                Tags for tracking (optional)
	 *     @type int    $campaign_id         Campaign ID for logging (optional)
	 * }
	 *
	 * @return array|WP_Error {
	 *     Result array or WP_Error on complete failure.
	 *
	 *     @type bool   $success      Whether all emails were sent successfully
	 *     @type int    $sent_count   Number of successfully sent emails
	 *     @type array  $failed       Array of failed recipients with errors
	 *     @type array  $message_ids  Array of message IDs (email => id)
	 * }
	 */
	public function send_batch( $batch_data ) {
		// Validate batch data
		$validation = $this->validate_batch_data( $batch_data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Get credentials (use from_email for smart routing)
		$from_email  = $batch_data['from_email'] ?? null;
		$credentials = $this->get_credentials( $from_email );
		if ( ! $credentials ) {
			return new WP_Error(
				'not_configured',
				sprintf(
					/* translators: %s: mailer name */
					__( '%s is not properly configured.', 'doublescale' ),
					ucfirst( $this->slug )
				)
			);
		}

		// Log attempt
		$this->log_send_attempt( $batch_data );

		// Split recipients into chunks for parallel processing
		$recipients       = $batch_data['recipients'];
		$max_concurrent   = $this->get_max_concurrent();
		$recipient_chunks = array_chunk( $recipients, $max_concurrent );

		$results = array(
			'success'     => true,
			'sent_count'  => 0,
			'failed'      => array(),
			'message_ids' => array(),
		);

		// Process each chunk
		foreach ( $recipient_chunks as $chunk ) {
			$chunk_results = $this->send_chunk( $chunk, $batch_data, $credentials );

			// Merge results
			$results['sent_count'] += $chunk_results['sent_count'];
			$results['failed']      = array_merge( $results['failed'], $chunk_results['failed'] );
			$results['message_ids'] = array_merge( $results['message_ids'], $chunk_results['message_ids'] );

			if ( ! empty( $chunk_results['failed'] ) ) {
				$results['success'] = false;
			}

			// Delay between chunks to respect rate limits
			if ( $this->batch_delay > 0 ) {
				usleep( $this->batch_delay );
			}
		}

		// Log final result
		if ( $results['success'] ) {
			$this->log_send_success( $batch_data, $results['sent_count'] );
		} else {
			$this->log_partial_failure( $batch_data, $results );
		}

		return $results;
	}

	/**
	 * Send a chunk of emails in parallel using cURL Multi
	 *
	 * @param array $recipients   Array of recipient email addresses
	 * @param array $batch_data   Full batch data
	 * @param array $credentials  Api credentials
	 *
	 * @return array Chunk results
	 */
	abstract protected function send_chunk( $recipients, $batch_data, $credentials );

	/**
	 * Build HTTP headers for the Api request
	 *
	 * @param array $credentials Api credentials
	 *
	 * @return array Headers array
	 */
	protected function build_headers( $credentials ) {
		return array(
			'Content-Type: application/json',
			'Accept: application/json',
		);
	}

	/**
	 * Validate batch data before sending
	 *
	 * @param array $batch_data Batch email data
	 *
	 * @return true|WP_Error True if valid, WP_Error if invalid
	 */
	protected function validate_batch_data( $batch_data ) {
		if ( empty( $batch_data['recipients'] ) || ! is_array( $batch_data['recipients'] ) ) {
			return new WP_Error(
				'invalid_recipients',
				__( 'Recipients array is required.', 'doublescale' )
			);
		}

		if ( empty( $batch_data['subject'] ) ) {
			return new WP_Error(
				'missing_subject',
				__( 'Email subject is required.', 'doublescale' )
			);
		}

		if ( empty( $batch_data['html'] ) ) {
			return new WP_Error(
				'missing_body',
				__( 'Email body is required.', 'doublescale' )
			);
		}

		$max_batch_size = $this->get_max_batch_size();
		if ( count( $batch_data['recipients'] ) > $max_batch_size ) {
			return new WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum batch size */
					__( 'Batch size exceeds maximum of %d recipients.', 'doublescale' ),
					$max_batch_size
				)
			);
		}

		return true;
	}

	/**
	 * Log batch send attempt
	 *
	 * @param array $batch_data Batch email data
	 */
	protected function log_send_attempt( $batch_data ) {
		doublescale_get_logger()->info(
			/* translators: %s: mailer name */
			sprintf( __( 'Sending emails via %s cURL Multi', 'doublescale' ), ucfirst( $this->slug ) ),
			array(
				'code'            => 'curl_multi_send_attempt',
				'mailer'          => $this->slug,
				'recipient_count' => count( $batch_data['recipients'] ),
				'campaign_id'     => $batch_data['campaign_id'] ?? null,
				'max_concurrent'  => $this->get_max_concurrent(),
			)
		);
	}

	/**
	 * Log batch send success
	 *
	 * @param array $batch_data Batch email data
	 * @param int   $sent_count Number of emails sent
	 */
	protected function log_send_success( $batch_data, $sent_count ) {
		doublescale_get_logger()->info(
			__( 'cURL Multi email batch sent successfully', 'doublescale' ),
			array(
				'code'        => 'curl_multi_send_success',
				'mailer'      => $this->slug,
				'sent_count'  => $sent_count,
				'campaign_id' => $batch_data['campaign_id'] ?? null,
			)
		);
	}

	/**
	 * Log partial failure
	 *
	 * @param array $batch_data Batch email data
	 * @param array $results    Results array
	 */
	protected function log_partial_failure( $batch_data, $results ) {
		doublescale_get_logger()->info(
			__( 'cURL Multi email batch partially failed', 'doublescale' ),
			array(
				'code'         => 'curl_multi_partial_failure',
				'mailer'       => $this->slug,
				'sent_count'   => $results['sent_count'],
				'failed_count' => count( $results['failed'] ),
				'failed'       => array_slice( $results['failed'], 0, 5 ), // Log first 5 failures
				'campaign_id'  => $batch_data['campaign_id'] ?? null,
			)
		);
	}
}
