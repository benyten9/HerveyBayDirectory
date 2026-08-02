<?php
/**
 * Typeform API client.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Typeform;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Typeform API class.
 */
class Api extends IntegrationApi {

	/**
	 * Personal access token.
	 *
	 * @var string
	 */
	public $access_token;

	/**
	 * Constructor.
	 *
	 * @param string $access_token Personal access token.
	 */
	public function __construct( $access_token ) {
		$this->endpoint      = 'https://api.typeform.com';
		$this->access_token  = $access_token;
	}

	/**
	 * List forms (used for validation and form picker).
	 *
	 * @return array
	 */
	public function get_forms() {
		return $this->get( 'forms', array( 'page_size' => 200 ) );
	}

	/**
	 * Get a single form definition including fields.
	 *
	 * @param string $form_id Form ID.
	 * @return array
	 */
	public function get_form( $form_id ) {
		return $this->get( 'forms/' . rawurlencode( $form_id ) );
	}

	/**
	 * Create or update a webhook on a form.
	 *
	 * @param string $form_id Form ID.
	 * @param string $tag     Webhook tag.
	 * @param string $url     Callback URL.
	 * @param string $secret  Shared secret for signature verification.
	 * @return array
	 */
	public function create_webhook( $form_id, $tag, $url, $secret ) {
		return $this->put(
			'forms/' . rawurlencode( $form_id ) . '/webhooks/' . rawurlencode( $tag ),
			array(
				'url'     => $url,
				'enabled' => true,
				'secret'  => $secret,
			)
		);
	}

	/**
	 * Delete a webhook from a form.
	 *
	 * @param string $form_id Form ID.
	 * @param string $tag     Webhook tag.
	 * @return array
	 */
	public function delete_webhook( $form_id, $tag ) {
		return $this->delete(
			'forms/' . rawurlencode( $form_id ) . '/webhooks/' . rawurlencode( $tag )
		);
	}

	/**
	 * Send request to the Typeform API.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   API path.
	 * @param string|null $body   Request body.
	 * @return array|WP_Error
	 */
	public function request_remote( $method, $path, $body = null ) {
		return wp_remote_request(
			"{$this->endpoint}/$path",
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ),
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				'timeout' => 30,
			)
		);
	}
}
