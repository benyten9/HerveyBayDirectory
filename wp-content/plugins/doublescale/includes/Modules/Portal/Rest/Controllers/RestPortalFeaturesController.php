<?php
/**
 * REST: Settings → Client Portal → What clients see in the portal.
 *
 *   GET  /doublescale/v1/portal/features  the areas, their defaults and module state
 *   POST /doublescale/v1/portal/features  save defaults: { features: { key: bool } }
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalFeatures;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalFeaturesController.
 */
class RestPortalFeaturesController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal/features';

	/**
	 * Register routes.
	 *
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Portal settings are site settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return new WP_REST_Response( PortalFeatures::settings_payload(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		$features = $request->get_param( 'features' );
		if ( ! is_array( $features ) ) {
			return new WP_Error(
				'invalid_features',
				__( 'Send the areas to save as an object of area => true/false.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$saved = PortalFeatures::update_defaults( $features );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response( PortalFeatures::settings_payload(), 200 );
	}
}
