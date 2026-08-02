<?php
/**
 * Class Slack Api
 *
 * This class is responsible for handling the Slack Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Slack;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Slack Api class
 */
class Api extends IntegrationApi {

	/**
	 * App
	 *
	 * @var App
	 */
	private $app;

	/**
	 * Access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Refresh token
	 *
	 * @var string
	 */
	private $refresh_token;

	/**
	 * Constructor
	 *
	 * @param string $access_token Access token.
	 * @param string $refresh_token Refresh token.
	 * @param App    $app App.
	 * @since 1.0.0
	 */
	public function __construct( $access_token, $refresh_token = null, $app = null ) {
		$this->endpoint      = 'https://slack.com/api';
		$this->app           = $app;
		$this->access_token  = $access_token;
		$this->refresh_token = $refresh_token;
	}

	/**
	 * Get conversations
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public function get_conversations( $args = array() ) {
		return $this->get( 'conversations.list', $args );
	}

	/**
	 * Get user info
	 *
	 * @since 1.0.0
	 *
	 * @param string $id User ID.
	 * @return array
	 */
	public function get_user( $id ) {
		return $this->get( 'users.info', array( 'user' => $id ) );
	}

	/**
	 * Post message to a conversation
	 *
	 * @since 1.0.0
	 *
	 * @param string $conversation_id Conversation ID.
	 * @param string $text Message text.
	 * @return array
	 */
	public function post_message( $conversation_id, $text ) {
		return $this->post(
			'chat.postMessage',
			array(
				'channel' => $conversation_id,
				'text'    => $text,
			)
		);
	}

	/**
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path URL.
	 * @param string|null $body Body.
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
					'Cache-Control' => 'no-cache',
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				'timeout' => 30,
			)
		);
	}

	/**
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path Path.
	 * @param string|null $body Body.
	 * @param boolean     $maybe_refresh_token Refresh token if expired or no.
	 * @return array
	 */
	public function request( $method, $path, $body = null, $maybe_refresh_token = true ) {
		$response = $this->request_remote( $method, $path, $body );
		if ( is_wp_error( $response ) ) {
			return $this->prepare_response(
				false,
				null,
				array(
					'wp_error' => array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					),
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( $response['body'], true );

		if ( $response_code === 401 ) {
			if ( $maybe_refresh_token ) {
				$refreshed = $this->refresh_tokens();
				if ( $refreshed ) {
					// try the request again but don't try to refresh tokens again!
					return $this->request( $method, $path, $body, false );
				}
			}

			return $this->prepare_response(
				false,
				$response_code,
				$response_body
			);
		} elseif ( $response_code >= 300 ) {
			return $this->prepare_response(
				false,
				$response_code,
				$response_body
			);
		}

		unset( $response_body['_links'] );
		return $this->prepare_response(
			true,
			$response_code,
			$response_body
		);
	}

	/**
	 * Refresh tokens
	 *
	 * @return boolean
	 */
	private function refresh_tokens() {
		$tokens = $this->app->refresh_tokens( null, $this->refresh_token );
		if ( ! is_array( $tokens ) ) {
			return false;
		}

		$this->access_token  = $tokens['access_token'];
		$this->refresh_token = $tokens['refresh_token'] ?? $this->refresh_token;
		return true;
	}
}
