<?php
/**
 * Base HTTP helper for booking provider clients (Google, Outlook, Zoom, …).
 *
 * Child classes implement {@see self::request_remote()} and may override {@see self::request()}
 * for token refresh or custom error handling.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Integration;

use DoubleScale\Modules\Booking\Abstracts\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Integration API class
 */
abstract class API {

	/**
	 * Booking integration instance.
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * API root URL (no trailing slash).
	 *
	 * @var string
	 */
	public $endpoint;

	/**
	 * @param Integration $integration Active integration.
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;
	}

	/**
	 * @param string       $path Path (may include leading slash).
	 * @param array<mixed> $args Query args for GET.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get( $path, $args = array() ) {
		if ( ! empty( $args ) ) {
			$path .= '?' . http_build_query( $args );
		}
		return $this->request( 'GET', $path );
	}

	/**
	 * @param string               $path Path.
	 * @param array<string, mixed> $body Request JSON body.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function post( $path, $body = array() ) {
		return $this->request( 'POST', $path, $body ? wp_json_encode( $body ) : null );
	}

	/**
	 * @param string               $path Path.
	 * @param array<string, mixed> $body Request JSON body.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function put( $path, $body ) {
		return $this->request( 'PUT', $path, $body ? wp_json_encode( $body ) : null );
	}

	/**
	 * @param string               $path Path.
	 * @param array<string, mixed> $body Request JSON body.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function patch( $path, $body ) {
		return $this->request( 'PATCH', $path, $body ? wp_json_encode( $body ) : null );
	}

	/**
	 * @param string               $path Path.
	 * @param array<string, mixed> $body Request JSON body.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function delete( $path, $body = array() ) {
		return $this->request( 'DELETE', $path, $body ? wp_json_encode( $body ) : null );
	}

	/**
	 * Default request pipeline (children often override for OAuth refresh).
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   Relative path.
	 * @param string|null $body   JSON body or null.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function request( $method, $path, $body = null ) {
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

		if ( 401 === $response_code ) {
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

		if ( is_array( $response_body ) ) {
			unset( $response_body['_links'] );
		}
		return $this->prepare_response(
			true,
			$response_code,
			$response_body
		);
	}

	/**
	 * @param string      $method HTTP method.
	 * @param string      $path   Relative path.
	 * @param string|null $body   JSON body or null.
	 * @return array|\WP_Error Raw {@see wp_remote_request()} result.
	 */
	abstract public function request_remote( $method, $path, $body = null );

	/**
	 * @param bool            $success Outcome.
	 * @param int|string|null $code    HTTP status or null.
	 * @param mixed           $data    Decoded body or error payload.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function prepare_response( $success, $code, $data ) {
		return compact( 'success', 'code', 'data' );
	}
}
