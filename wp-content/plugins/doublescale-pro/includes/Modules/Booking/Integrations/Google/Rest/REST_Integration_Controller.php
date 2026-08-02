<?php
/**
 * Google integration settings + OAuth auth URL route.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Google\Rest;

use DoubleScale\Modules\Booking\Integration\Rest\REST_Integration_Controller as Abstract_REST_Integration_Controller;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @property \DoubleScale\Pro\Modules\Booking\Integrations\Google\Integration $integration
 */
class REST_Integration_Controller extends Abstract_REST_Integration_Controller {

	/**
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/auth",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'auth_uri' ),
					'permission_callback' => array( $this, 'auth_uri_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Settings schema for documentation / validation hints.
	 */
	public function get_settings_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'app'   => array(
					'type'       => 'object',
					'context'    => array( 'view' ),
					'properties' => array(
						'cache_time' => array(
							'label'    => __( 'Cache Time (minutes)', 'doublescale' ),
							'type'     => 'number',
							'required' => true,
							'context'  => array( 'view' ),
						),
					),
				),
				'hosts' => array(
					'type'                 => 'object',
					'context'              => array( 'view' ),
					'additionalProperties' => true,
				),
			),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function auth_uri( $request ) {
		$host_id = $request->get_param( 'host_id' );
		if ( empty( $host_id ) ) {
			return new WP_Error( 'no_host_id', esc_html__( 'No host ID provided.', 'doublescale' ) );
		}

		$calendar = CalendarModel::find( $host_id );
		if ( empty( $calendar ) || 'host' !== $calendar->type ) {
			return new WP_Error( 'no_host', esc_html__( 'No host calendar found.', 'doublescale' ) );
		}

		$app      = $this->integration->app;
		$auth_uri = $app->get_auth_uri( (int) $host_id );

		if ( is_wp_error( $auth_uri ) ) {
			return $auth_uri;
		}

		return new WP_REST_Response( array( 'auth_uri' => $auth_uri ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function auth_uri_permissions_check( $request ) {
		return $this->current_user_can_manage_integrations();
	}
}
