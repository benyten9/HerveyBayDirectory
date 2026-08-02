<?php
/**
 * Meta WhatsApp Graph Api Wrapper
 *
 * Handles all communication with Meta's Graph Api for WhatsApp Business
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\MetaWhatsapp;

defined( 'ABSPATH' ) || exit;

/**
 * Api class for Meta WhatsApp Graph Api
 */
class Api {

	/**
	 * Access token for authentication
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * WhatsApp phone number ID
	 *
	 * @var string
	 */
	private $phone_number_id;

	/**
	 * WhatsApp Business Account ID
	 *
	 * @var string
	 */
	private $business_account_id;

	/**
	 * Api version
	 *
	 * @var string
	 */
	private $api_version = 'v21.0';

	/**
	 * Base URL for Graph Api
	 *
	 * @var string
	 */
	private $base_url = 'https://graph.facebook.com';

	/**
	 * Constructor
	 *
	 * @param string $access_token        Permanent access token.
	 * @param string $phone_number_id     WhatsApp phone number ID.
	 * @param string $business_account_id WhatsApp Business Account ID.
	 */
	public function __construct( $access_token, $phone_number_id, $business_account_id ) {
		$this->access_token        = $access_token;
		$this->phone_number_id     = $phone_number_id;
		$this->business_account_id = $business_account_id;
	}

	/**
	 * Send a template message
	 *
	 * @param string $to         Recipient phone number.
	 * @param string $template   Template name.
	 * @param string $language   Language code (e.g., 'en_US').
	 * @param array  $components Template components with parameters.
	 *
	 * @return array Result array with success status and data/error.
	 */
	public function send_template_message( $to, $template, $language, $components = array() ) {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->phone_number_id}/messages";

		$body = array(
			'messaging_product' => 'whatsapp',
			'to'                => ltrim( $to, '+' ), // Meta expects without +
			'type'              => 'template',
			'template'          => array(
				'name'     => $template,
				'language' => array( 'code' => $language ),
			),
		);

		// Add components if provided
		if ( ! empty( $components ) ) {
			$body['template']['components'] = $components;
		}

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Send a text message (session message within 24h window)
	 *
	 * @param string $to   Recipient phone number.
	 * @param string $text Message text.
	 *
	 * @return array Result array with success status and data/error.
	 */
	public function send_text_message( $to, $text ) {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->phone_number_id}/messages";

		$body = array(
			'messaging_product' => 'whatsapp',
			'to'                => ltrim( $to, '+' ),
			'type'              => 'text',
			'text'              => array( 'body' => $text ),
		);

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Get approved message templates
	 *
	 * @param string $status Template status filter (default: APPROVED).
	 * @param int    $limit  Number of templates to fetch.
	 *
	 * @return array Result array with success status and templates.
	 */
	public function get_message_templates( $status = 'APPROVED', $limit = 100 ) {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->business_account_id}/message_templates";
		$params   = array(
			'status' => $status,
			'limit'  => $limit,
		);

		return $this->request( 'GET', $endpoint, null, $params );
	}

	/**
	 * Get phone numbers in the WhatsApp Business Account
	 *
	 * @return array Result array with phone numbers.
	 */
	public function get_phone_numbers() {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->business_account_id}/phone_numbers";
		return $this->request( 'GET', $endpoint );
	}

	/**
	 * Register the phone number with the WhatsApp Cloud Api
	 *
	 * A number added to a WhatsApp Business Account is "Pending" until it is
	 * explicitly registered with the Cloud Api. Until then every send fails with
	 * error #133010 "Account not registered". This call moves the number from
	 * "Pending" to "Connected" and sets/confirms its two-step verification PIN.
	 *
	 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/reference/registration
	 *
	 * @param string $pin 6-digit two-step verification PIN. If the number never had
	 *                     two-step verification enabled, this value becomes the new PIN.
	 *
	 * @return array Result array with success status and data/error.
	 */
	public function register_phone_number( $pin ) {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->phone_number_id}/register";

		$body = array(
			'messaging_product' => 'whatsapp',
			'pin'               => (string) $pin,
		);

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Get the registration/connection status of the configured phone number
	 *
	 * Reads the phone number node so callers can tell whether the number still
	 * needs to be registered (status "PENDING") versus ready to message
	 * (status "CONNECTED").
	 *
	 * @return array Result array with success status and data/error.
	 */
	public function get_phone_number_status() {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->phone_number_id}";
		$params   = array(
			'fields' => 'id,display_phone_number,verified_name,status,platform_type,code_verification_status',
		);

		return $this->request( 'GET', $endpoint, null, $params );
	}

	/**
	 * Mark a message as read
	 *
	 * @param string $message_id The message ID to mark as read.
	 *
	 * @return array Result array.
	 */
	public function mark_as_read( $message_id ) {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->phone_number_id}/messages";

		$body = array(
			'messaging_product' => 'whatsapp',
			'status'            => 'read',
			'message_id'        => $message_id,
		);

		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Get business profile
	 *
	 * @return array Result array with business profile data.
	 */
	public function get_business_profile() {
		$endpoint = "{$this->base_url}/{$this->api_version}/{$this->phone_number_id}/whatsapp_business_profile";
		$params   = array(
			'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
		);

		return $this->request( 'GET', $endpoint, null, $params );
	}

	/**
	 * Make an HTTP request to the Graph Api
	 *
	 * @param string     $method   HTTP method (GET, POST).
	 * @param string     $endpoint Api endpoint URL.
	 * @param array|null $body     Request body for POST requests.
	 * @param array      $params   Query parameters for GET requests.
	 *
	 * @return array Result array with success status and data/error.
	 */
	private function request( $method, $endpoint, $body = null, $params = array() ) {
		$url = $endpoint;
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 200 && $status_code < 300 ) {
			return array(
				'success' => true,
				'data'    => $data,
			);
		}

		// Extract error message from Meta's error response format
		$error_message = 'Unknown error';
		$error_code    = null;

		if ( isset( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown error';
			$error_code    = $data['error']['code'] ?? null;

			// Include more detailed error info if available
			if ( isset( $data['error']['error_user_msg'] ) ) {
				$error_message .= ' - ' . $data['error']['error_user_msg'];
			}
		}

		return array(
			'success'    => false,
			'error'      => $error_message,
			'error_code' => $error_code,
			'data'       => $data,
		);
	}
}





