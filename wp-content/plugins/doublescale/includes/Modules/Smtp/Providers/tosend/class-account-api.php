<?php
/**
 * Account_API class.
 *
 * @since 1.4.10
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\ToSend;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Account_API class.
 *
 * @see https://tosend.com/docs/api/introduction/
 *
 * @since 1.4.10
 */
class Account_API {

	const API_BASE = 'https://api.tosend.com/v2/';

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
	 * Check the API key against GET /v2/info.
	 *
	 * @return true|WP_Error
	 */
	public function verify() {
		$result = $this->request( 'GET', 'info' );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Check that this key may send as $email: toSend rejects every message
	 * whose From domain isn't verified on the account.
	 *
	 * @see https://tosend.com/docs/api/account-info/
	 *
	 * @param string $email From address.
	 *
	 * @return true|WP_Error
	 */
	public function verify_sender( $email ) {
		$info = $this->request( 'GET', 'info' );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$domain   = strtolower( (string) substr( (string) strrchr( $email, '@' ), 1 ) );
		$verified = array();
		foreach ( (array) ( $info['domains'] ?? array() ) as $row ) {
			if ( is_array( $row ) && 'verified' === ( $row['verification_status'] ?? '' ) && ! empty( $row['domain_name'] ) ) {
				$verified[] = strtolower( (string) $row['domain_name'] );
			}
		}

		if ( '' !== $domain && in_array( $domain, $verified, true ) ) {
			return true;
		}

		if ( empty( $verified ) ) {
			return new WP_Error(
				'doublescale_smtp_tosend_sender_unverified',
				sprintf(
					/* translators: %s: email domain. */
					__( 'This toSend API key has no verified domains yet. Verify %s in your toSend dashboard, then save again.', 'doublescale' ),
					$domain
				)
			);
		}

		return new WP_Error(
			'doublescale_smtp_tosend_sender_unverified',
			sprintf(
				/* translators: 1: email domain, 2: comma-separated verified domains. */
				__( 'toSend can\'t send from %1$s: that domain isn\'t verified on this API key. Verified domains: %2$s.', 'doublescale' ),
				$domain,
				implode( ', ', $verified )
			)
		);
	}

	/**
	 * Call the toSend API.
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
				'doublescale_smtp_tosend_api_error',
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
	 * Readable message from a toSend error body: {status_code, message, errors{field: text}}.
	 *
	 * @param array $data Decoded error body.
	 * @param int   $code HTTP status.
	 *
	 * @return string
	 */
	protected static function error_message( $data, $code ) {
		$message = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';

		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$details = array();
			foreach ( $data['errors'] as $field => $error ) {
				$details[] = $field . ': ' . ( is_array( $error ) ? implode( ', ', $error ) : $error );
			}
			$message = trim( $message . ' (' . implode( '; ', $details ) . ')' );
		}

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'toSend API request failed with status %d.', 'doublescale' ), $code );
		}

		return $message;
	}
}
