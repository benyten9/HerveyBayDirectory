<?php
/**
 * Postmark HTTP client.
 *
 * Replaces the wildbit/postmark-php SDK (which the scoped autoloader
 * force-loaded on every request) with direct WP HTTP API calls. Exposes the
 * small surface the provider uses — `sendEmailBatch()` and a server lookup for
 * key validation — returning objects whose `->Message` / `->MessageID`
 * properties match what the SDK's response models provided.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\PostMark;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Postmark HTTP client.
 *
 * @since 1.0.0
 */
class Http_Client {

	/**
	 * Postmark REST API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.postmarkapp.com';

	/**
	 * Server API token.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Postmark server token.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Send a batch of messages via `POST /email/batch`.
	 *
	 * Mirrors the SDK's `sendEmailBatch()`. Returns a list of response objects
	 * exposing `->Message`, `->MessageID`, `->ErrorCode`, and `->To` so callers
	 * that iterate the SDK's DynamicResponseModel keep working.
	 *
	 * @since 1.0.0
	 *
	 * @param array $messages List of Postmark message payloads (plain arrays).
	 * @return Postmark_Response[]
	 *
	 * @throws \Exception On transport error or non-2xx batch response.
	 */
	public function sendEmailBatch( array $messages ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-compatible surface.
		$prepared = array_map(
			static function ( $message ) {
				return self::normalize_attachments( $message );
			},
			$messages
		);

		$response = wp_remote_post(
			self::API_BASE . '/email/batch',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'                  => 'application/json',
					'Content-Type'            => 'application/json',
					'X-Postmark-Server-Token' => $this->api_key,
				),
				'body'    => wp_json_encode( $prepared ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Postmark returns 200 with a per-message array on batch success. A
		// non-2xx here is a request-level failure (e.g. auth), not per-message.
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['Message'] )
				? $data['Message']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Postmark API returned HTTP %d.', 'doublescale' ), $code );
			throw new \Exception( esc_html( $message ) );
		}

		$results = array();
		foreach ( (array) $data as $entry ) {
			$results[] = new Postmark_Response( is_array( $entry ) ? $entry : array() );
		}
		return $results;
	}

	/**
	 * Fetch the server (used to validate a server token) via `GET /server`.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error Decoded server payload on success, WP_Error otherwise.
	 */
	public function get_server() {
		$response = wp_remote_get(
			self::API_BASE . '/server',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'                  => 'application/json',
					'X-Postmark-Server-Token' => $this->api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'doublescale_smtp_postmark_api_key_invalid', __( 'API key is invalid.', 'doublescale' ), array( 'status' => $code ) );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
	}

	/**
	 * Convert any Postmark_Attachment objects in a message to their array form.
	 *
	 * @param array $message Message payload.
	 * @return array
	 */
	protected static function normalize_attachments( $message ) {
		if ( empty( $message['Attachments'] ) || ! is_array( $message['Attachments'] ) ) {
			return $message;
		}
		$message['Attachments'] = array_map(
			static function ( $attachment ) {
				return $attachment instanceof PostmarkAttachment ? $attachment->to_array() : $attachment;
			},
			$message['Attachments']
		);
		return $message;
	}
}
