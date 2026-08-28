<?php
/**
 * REST API: WooCommerce CRM settings.
 *
 * @package DoubleScale\Pro\Modules\Sales\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Integrations\WooCommerce\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestWooCommerceSettingsController class.
 */
class RestWooCommerceSettingsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'settings/woocommerce';

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
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		unset( $request );

		return Permissions::has_crm_manager_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) {
		unset( $request );

		$service  = Settings::instance();
		$settings = $service->resolved_settings();

		return new WP_REST_Response(
			array(
				'settings'              => $settings,
				'is_woocommerce_active' => $service->is_woocommerce_active(),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		$payload  = $request->get_param( 'settings' );
		$settings = is_array( $payload ) ? $payload : array();
		$service  = Settings::instance();

		$validation = $service->validate( $settings );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$saved = $service->update_settings( $settings );

		return new WP_REST_Response(
			array(
				'settings'              => $saved,
				'is_woocommerce_active' => $service->is_woocommerce_active(),
			),
			200
		);
	}
}
