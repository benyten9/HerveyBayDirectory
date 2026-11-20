<?php
/**
 * Account_API class.
 *
 * @since 1.4.10
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Emailit;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Account_API class.
 *
 * @see https://emailit.com/docs/api-reference/emails/
 *
 * @since 1.4.10
 */
class Account_API {

	const API_BASE = 'https://api.emailit.com/v2/';

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Send one email.
	 *
	 * @param array $args Request body for POST /v2/emails.
	 *
	 * @return WP_Error|array Decoded response body on success.
	 */
	public function send( $args ) {
		return $this->request( 'POST', 'emails', $args );
	}

	/**
	 * Check the API key against GET /v2/domains.
	 *
	 * A sending-only key is valid but may not list domains: Emailit answers
	 * 403 for it, so only 401 means the key itself is wrong.
	 *
	 * @return true|WP_Error
	 */
	public function verify() {
		$result = $this->request( 'GET', 'domains' );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && 403 === ( $data['http_status'] ?? 0 ) ) {
				return true;
			}
			return $result;
		}

		return true;
	}

	/**
	 * Call the Emailit API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to the v2 base.
	 * @param array|null $body   JSON body.
	 *
	 * @return WP_Error|array
	 */
	protected function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
			'timeout' => 60,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'doublescale_smtp_emailit_api_error',
				self::error_message( $data, $code ),
				array(
					'http_status' => $code,
					'body'        => $data,
				)
			);
		}

		return $data;
	}

	/**
	 * Readable message from an Emailit error body:
	 * {statusCode, error, message} or {error: {message}}.
	 *
	 * @param array $data Decoded error body.
	 * @param int   $code HTTP status.
	 *
	 * @return string
	 */
	protected static function error_message( $data, $code ) {
		if ( isset( $data['message'] ) && is_string( $data['message'] ) && '' !== $data['message'] ) {
			return $data['message'];
		}

		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		if ( isset( $data['error'] ) && is_string( $data['error'] ) && '' !== $data['error'] ) {
			return $data['error'];
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Emailit API request failed with status %d.', 'doublescale' ), $code );
	}
}
