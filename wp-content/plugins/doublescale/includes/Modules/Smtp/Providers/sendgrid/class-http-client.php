<?php
/**
 * SendGrid HTTP client.
 *
 * Replaces the sendgrid/sendgrid SDK (which the scoped autoloader force-loaded
 * on every request) with direct WP HTTP API calls. Works with the local `Mail`
 * builder drop-in and serialises to the `POST /v3/mail/send` JSON body.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SendGrid;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * SendGrid HTTP client.
 *
 * @since 1.0.0
 */
class Http_Client {

	/**
	 * SendGrid REST API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.sendgrid.com/v3';

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key SendGrid API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Send a Mail via `POST /v3/mail/send`.
	 *
	 * Mirrors the SDK client's `send()` — returns a response object exposing
	 * `statusCode()` and `body()`, so callers need not change.
	 *
	 * @since 1.0.0
	 *
	 * @param Mail $mail The mail to send.
	 * @return Http_Response
	 */
	public function send( Mail $mail ) {
		$response = wp_remote_post(
			self::API_BASE . '/mail/send',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $mail->to_array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new Http_Response( 0, $response->get_error_message() );
		}

		return new Http_Response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * Validate an API key via `GET /v3/user/email`.
	 *
	 * @since 1.0.0
	 *
	 * @return true|WP_Error True if the key is valid, WP_Error otherwise.
	 */
	public function validate_key() {
		$response = wp_remote_get(
			self::API_BASE . '/user/email',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'doublescale_smtp_sendgrid_api_key_invalid', __( 'API key is invalid.', 'doublescale' ), array( 'status' => $code ) );
		}

		return true;
	}
}
