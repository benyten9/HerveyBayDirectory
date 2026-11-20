<?php
/**
 * REST: which header holds the visitor's address (Settings → System →
 * Visitor address).
 *
 *   GET  /doublescale/v1/settings/client-ip  { header, remote, headers, used }
 *   POST /doublescale/v1/settings/client-ip  { header } → same shape
 *
 * Site administrators only. The screen shows what the current request
 * carries, so the owner can see which header holds their own address before
 * trusting it. The general settings save never writes this key
 * ({@see Settings::REQUEST_PROTECTED_KEYS}).
 *
 * @since 1.7.1
 * @package DoubleScale
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestClientIpController class.
 */
class RestClientIpController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'settings/client-ip';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'header' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array_merge( array( '' ), array_keys( ClientIp::HEADERS ) ),
						),
					),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return new WP_REST_Response( self::state(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$header = (string) $request->get_param( 'header' );
		if ( '' !== $header && ! isset( ClientIp::HEADERS[ $header ] ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Unknown header.', 'doublescale' ), array( 'status' => 400 ) );
		}

		Settings::update( ClientIp::SETTING, $header );

		return new WP_REST_Response( self::state(), 200 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		return array_merge(
			array( 'header' => ClientIp::trusted_header() ),
			ClientIp::detected(),
			array( 'used' => ClientIp::get() )
		);
	}
}
