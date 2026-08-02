<?php
/**
 * Jotform API client.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Jotform;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Jotform API class.
 */
class Api extends IntegrationApi {

	/**
	 * API key.
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->endpoint = 'https://api.jotform.com';
		$this->api_key  = $api_key;
	}

	/**
	 * List forms (used for validation and the form picker).
	 *
	 * @return array
	 */
	public function get_forms() {
		return $this->get( 'user/forms', array( 'limit' => 1000 ) );
	}

	/**
	 * Get a single form's questions (fields).
	 *
	 * @param string $form_id Form ID.
	 * @return array
	 */
	public function get_form_questions( $form_id ) {
		return $this->get( 'form/' . rawurlencode( $form_id ) . '/questions' );
	}

	/**
	 * List webhooks registered on a form.
	 *
	 * @param string $form_id Form ID.
	 * @return array
	 */
	public function list_webhooks( $form_id ) {
		return $this->get( 'form/' . rawurlencode( $form_id ) . '/webhooks' );
	}

	/**
	 * Add a webhook to a form.
	 *
	 * Jotform expects a form-encoded body, not JSON, so we build the request
	 * body here and send it via {@see request_remote()} directly.
	 *
	 * @param string $form_id Form ID.
	 * @param string $url     Callback URL.
	 * @return array
	 */
	public function create_webhook( $form_id, $url ) {
		return $this->request(
			'POST',
			'form/' . rawurlencode( $form_id ) . '/webhooks',
			http_build_query( array( 'webhookURL' => $url ) )
		);
	}

	/**
	 * Delete a webhook from a form by its numeric index.
	 *
	 * @param string     $form_id Form ID.
	 * @param string|int $index   Webhook index (key in the webhooks map).
	 * @return array
	 */
	public function delete_webhook( $form_id, $index ) {
		return $this->request(
			'DELETE',
			'form/' . rawurlencode( $form_id ) . '/webhooks/' . rawurlencode( (string) $index )
		);
	}

	/**
	 * Send request to the Jotform API.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   API path.
	 * @param string|null $body   Request body (form-encoded).
	 * @return array|WP_Error
	 */
	public function request_remote( $method, $path, $body = null ) {
		return wp_remote_request(
			"{$this->endpoint}/$path",
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
					'APIKEY'       => $this->api_key,
				),
				'timeout' => 30,
			)
		);
	}
}
