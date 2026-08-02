<?php
/**
 * Brevo (Sendinblue) HTTP client.
 *
 * Thin wrapper over the WP HTTP API replacing the getbrevo/brevo-php SDK.
 * The SDK bundled ~6.5M / ~383 classes that the scoped autoloader force-loaded
 * on every request; only two Brevo endpoints are ever called, so we hit them
 * directly instead.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SendInBlue;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Brevo HTTP client.
 *
 * @since 1.0.0
 */
class Http_Client {

	/**
	 * Brevo REST API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.brevo.com/v3';

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Brevo API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Send a transactional email.
	 *
	 * Mirrors the SDK's `TransactionalEmailsApi::sendTransacEmail()`.
	 *
	 * @since 1.0.0
	 *
	 * @param array $email_body Brevo `SendSmtpEmail` payload (plain array).
	 * @return array|WP_Error { messageId: string } on success, WP_Error otherwise.
	 */
	public function send_transactional_email( array $email_body ) {
		$response = wp_remote_post(
			self::API_BASE . '/smtp/email',
			array(
				'timeout' => 30,
				'headers' => array(
					'api-key'      => $this->api_key,
					'accept'       => 'application/json',
					'content-type' => 'application/json',
				),
				'body'    => wp_json_encode( $email_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] )
				? $data['message']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Brevo API returned HTTP %d.', 'doublescale' ), $code );
			return new WP_Error( 'doublescale_smtp_sendinblue_send_failed', $message, array( 'status' => $code ) );
		}

		return array(
			'messageId' => is_array( $data ) && isset( $data['messageId'] ) ? (string) $data['messageId'] : '',
		);
	}

	/**
	 * Fetch the account (used to validate an API key).
	 *
	 * Mirrors the SDK's `AccountApi::getAccount()`.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error Decoded account payload on success, WP_Error otherwise.
	 */
	public function get_account() {
		$response = wp_remote_get(
			self::API_BASE . '/account',
			array(
				'timeout' => 30,
				'headers' => array(
					'api-key' => $this->api_key,
					'accept'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'doublescale_smtp_sendinblue_api_key_invalid', __( 'API key is invalid.', 'doublescale' ), array( 'status' => $code ) );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
	}
}
