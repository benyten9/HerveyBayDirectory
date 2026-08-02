<?php
/**
 * SMTP2GO Curl Multi Mailer
 *
 * Handles email sending via SMTP2GO's Api using cURL Multi for parallel requests.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails\Curlmulti
 */

namespace DoubleScale\Modules\Emails\Curlmulti;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Providers\SMTP2GO\Account_API;
use WP_Error;

/**
 * Smtp2goCurlMultiMailer class
 *
 * @since 1.0.0
 */
class Smtp2goCurlMultiMailer extends AbstractCurlMultiMailer {

	/**
	 * Mailer slug identifier
	 *
	 * @var string
	 */
	protected $slug = 'smtp2go';

	/**
	 * Maximum concurrent requests
	 * SMTP2GO recommends not exceeding 10 concurrent requests
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
	 * Delay between batches in microseconds
	 * 100ms to respect rate limits
	 *
	 * @var int
	 */
	protected $batch_delay = 1000000; // 1s


	/**
	 * Check if SMTP2GO is properly configured and available
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->get_credentials() !== null;
	}

	/**
	 * Get Api credentials from smtp settings
	 *
	 * Uses the trait's get_account_credentials method.
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return array|null Array with 'api_key', or null if not configured
	 */
	protected function get_credentials( $from_email = null ) {
		return $this->get_account_credentials( 'api_key', $from_email );
	}

	/**
	 * Build request data for a single email
	 *
	 * @param array $email_data Email data for a single recipient
	 *
	 * @return array Request data array for SMTP2GO Api
	 */
	protected function build_request_data( $email_data ) {
		$data = array(
			'sender'    => sprintf( '%s <%s>', $email_data['from_name'], $email_data['from_email'] ),
			'to'        => array( $email_data['to'] ),
			'subject'   => $email_data['subject'],
			'html_body' => $email_data['html'],
		);

		// Add plain text version if provided
		if ( ! empty( $email_data['text'] ) ) {
			$data['text_body'] = $email_data['text'];
		}

		// Add custom headers
		$custom_headers = array();

		// Reply-To header
		if ( ! empty( $email_data['reply_to'] ) ) {
			$custom_headers['Reply-To'] = $email_data['reply_to'];
		}

		if ( ! empty( $custom_headers ) ) {
			$data['custom_headers'] = array();
			foreach ( $custom_headers as $header_name => $header_value ) {
				$data['custom_headers'][] = array(
					'header' => $header_name,
					'value'  => $header_value,
				);
			}
		}

		return $data;
	}

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
			'api-key: ' . $credentials['api_key'],
		);
	}

	/**
	 * Override send_chunk to use smtp Account_API for sending and logging
	 *
	 * @param array $recipients   Array of recipient email addresses
	 * @param array $batch_data   Full batch data
	 * @param array $credentials  Api credentials
	 *
	 * @return array Chunk results
	 */
	protected function send_chunk( $recipients, $batch_data, $credentials ) {
		// Build emails data array for the Account_API
		$emails_data = array();

		foreach ( $recipients as $email ) {
			// Get recipient variables
			$variables = $batch_data['recipient_variables'][ $email ] ?? array();

			// Personalize content for this recipient
			$subject = $this->process_merge_tags( $batch_data['subject'], $variables );
			$html    = $this->process_merge_tags( $batch_data['html'], $variables );
			$text    = ! empty( $batch_data['text'] )
				? $this->process_merge_tags( $batch_data['text'], $variables )
				: '';

			// Build email data for this recipient
			$email_data = array(
				'to'         => $email,
				'subject'    => $subject,
				'html'       => $html,
				'text'       => $text,
				'from_email' => $batch_data['from_email'] ?? get_option( 'admin_email' ),
				'from_name'  => $batch_data['from_name'] ?? get_bloginfo( 'name' ),
				'reply_to'   => $batch_data['reply_to'] ?? '',
				'tags'       => $batch_data['tags'] ?? array(),
			);

			// Build Api request
			$request_data = $this->build_request_data( $email_data );

			$emails_data[ $email ] = array(
				'request_data' => $request_data,
			);
		}

		// Use smtp Account_API for sending with logging capability
		$account_api = new Account_API( $credentials['api_key'] );

		// Send chunk using Account_API with optional logging callback
		$log_callback = function ( $email, $request_data, $response, $http_code, $error, $type ) {
			/**
			 * Fires when SMTP2GO email data is being sent or received.
			 *
			 * @param string      $email        Recipient email address.
			 * @param array       $request_data The request data being sent.
			 * @param string|null $response     The raw response (null for 'request' type).
			 * @param int|null    $http_code    The HTTP response code (null for 'request' type).
			 * @param string|null $error        The cURL error if any (null for 'request' type).
			 * @param string      $type         The log type: 'request' or 'response'.
			 */
			do_action( 'doublescale_smtp2go_curl_multi_log', $email, $request_data, $response, $http_code, $error, $type );
		};

		return $account_api->send_chunk( $emails_data, $log_callback );
	}

	/**
	 * Parse Api response from SMTP2GO
	 *
	 * Delegates to smtp Account_API for consistent response parsing.
	 *
	 * @param string $response  Raw response body
	 * @param int    $http_code HTTP response code
	 *
	 * @return array Parsed result
	 */
	protected function parse_response( $response, $http_code ) {
		$credentials = $this->get_credentials();
		$api_key     = $credentials ? $credentials['api_key'] : '';

		$account_api = new Account_API( $api_key );
		return $account_api->parse_response( $response, $http_code );
	}
}
