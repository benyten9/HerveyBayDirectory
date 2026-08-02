<?php
/**
 * Class Convertkit Api
 *
 * This class is responsible for handling the Convertkit Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Convertkit;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Convertkit Api class
 */
class Api extends IntegrationApi {

	/**
	 * Api Key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $api_secret;

	/**
	 * Constructor
	 *
	 * @param string $api_url
	 * @param string $api_secret
	 *
	 * @since 1.0.0
	 */
	public function __construct( $api_secret ) {
		$this->endpoint   = 'https://api.convertkit.com/v3';
		$this->api_secret = $api_secret;
	}

	/**
	 * Get account
	 *
	 * @return array
	 */
	public function get_account() {
		return $this->get( 'account' );
	}

	/**
	 * Get tags
	 *
	 * @return array
	 */
	public function get_tags() {
		return $this->get( 'tags' );
	}

	/**
	 * Get sequences
	 *
	 * @return array
	 */
	public function get_sequences() {
		return $this->get( 'sequences' );
	}

	/**
	 * Get custom fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return $this->get( 'custom_fields' );
	}

	/**
	 * Get subscriber by email
	 *
	 * @param string $email
	 *
	 * @return array
	 */
	public function get_subscriber( $email ) {
		return $this->get( 'subscribers', array( 'email_address' => $email ) );
	}

	/**
	 * Add subscriber tag
	 *
	 * @param int   $tag_id
	 * @param array $data
	 *
	 * @return array
	 */
	public function add_subscriber_tag( $tag_id, $data ) {
		return $this->post( "tags/$tag_id/subscribe", $data );
	}

	/**
	 * Remove subscriber tag
	 *
	 * @param int   $tag_id
	 * @param array $data
	 *
	 * @return array
	 */
	public function remove_subscriber_tag( $tag_id, $data ) {
		return $this->post( "tags/$tag_id/unsubscribe", $data );
	}

	/**
	 * Update subscriber
	 *
	 * @param int   $subscriber_id
	 * @param array $data
	 *
	 * @return array
	 */
	public function update_subscriber( $subscriber_id, $data ) {
		return $this->put( "subscribers/$subscriber_id", $data );
	}

	/**
	 * Add subscriber to sequence
	 *
	 * @param int $email
	 * @param int $sequence_id
	 *
	 * @return array
	 */
	public function add_subscriber_to_sequence( $email, $sequence_id ) {
		return $this->post( "sequences/$sequence_id/subscribe", array( 'email' => $email ) );
	}

	/**
	 * Get subscribers by page
	 *
	 * @param int $page
	 *
	 * @return array
	 */
	public function get_subscribers_by_page( $page ) {
		return $this->get( 'subscribers', array( 'page' => $page ) );
	}

	/**
	 * Send GET request to the api.
	 *
	 * @param string     $path Path.
	 * @param array|null $args Query string.
	 *
	 * @return array
	 */
	public function get( $path, $args = array() ) {
		$args['api_secret'] = $this->api_secret;
		if ( ! empty( $args ) ) {
			$path .= '?' . http_build_query( $args );
		}
		return $this->request( 'GET', $path );
	}

	/**
	 * Send POST request to the api.
	 *
	 * @param string     $path Path.
	 * @param array|null $body Body.
	 * @return array
	 */
	public function post( $path, $body = null ) {
		$body['api_secret'] = $this->api_secret;
		return $this->request( 'POST', $path, $body ? json_encode( $body ) : null );
	}

	/**
	 * Send PUT request to the api.
	 *
	 * @param string     $path Path.
	 * @param array|null $body Body.
	 * @return array
	 */
	public function put( $path, $body ) {
		$body['api_secret'] = $this->api_secret;
		return $this->request( 'PUT', $path, $body ? json_encode( $body ) : null );
	}

	/**
	 * Delete request to the api.
	 *
	 * @param string $path Path.
	 * @param array  $args Query string.
	 *
	 * @return array
	 */
	public function delete( $path, $args = array() ) {
		$args['api_secret'] = $this->api_secret;
		if ( ! empty( $args ) ) {
			$path .= '?' . http_build_query( $args );
		}
		return $this->request( 'DELETE', $path );
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
				),
				'timeout' => 30,
			)
		);
	}
}
