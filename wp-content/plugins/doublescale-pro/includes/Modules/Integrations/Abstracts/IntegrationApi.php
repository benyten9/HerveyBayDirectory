<?php
/**
 * Class Integration Api
 *
 * This class is responsible for handling the Integration Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */
namespace DoubleScale\Pro\Modules\Integrations\Abstracts;

use WP_Error;

/**
 * Integration Api class
 */
abstract class IntegrationApi {

	/**
	 * Integration.
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Endpoint URL
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $endpoint;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @param Integration $integration
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;
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
	public function post( $path, $body = array() ) {
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
		return $this->request( 'PUT', $path, $body ? json_encode( $body ) : null );
	}

	/**
	 * Send PATCH request to the api.
	 *
	 * @param string     $path Path.
	 * @param array|null $body Body.
	 * @return array
	 */
	public function patch( $path, $body ) {
		return $this->request( 'PATCH', $path, $body ? json_encode( $body ) : null );
	}

	/**
	 * Send DELETE request to the api.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 * @return array
	 */
	public function delete( $path, $body = array() ) {
		return $this->request( 'DELETE', $path, $body ? json_encode( $body ) : null );
	}

	/**
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path Path.
	 * @param string|null $body Body.
	 * @return array
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

		if ( $response_code === 401 ) {
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
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path URL.
	 * @param string|null $body Body.
	 * @return array|WP_Error
	 */
	abstract public function request_remote( $method, $path, $body = null );

	/**
	 * Prepare response
	 *
	 * @param boolean        $success Success or not.
	 * @param integer|string $code Response code.
	 * @param array          $data Response data.
	 * @return array
	 */
	public function prepare_response( $success, $code, $data ) {
		return compact( 'success', 'code', 'data' );
	}
}
