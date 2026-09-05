<?php // phpcs:ignore

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * License REST API endpoints.
 *
 * Backs the React License page: read the license / updater state and run the
 * activate / deactivate / reset actions plus the two update-debug remediations
 * (clear the EDD updater cache, live connectivity probe).
 */
class License implements ExecuteHooks {

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Permission check.
	 *
	 * Uses the live current-user state (see ProSettings for why the user id is
	 * not cached at registration time). Mirrors the capability enforced by the
	 * legacy admin_init handlers.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return boolean
	 */
	public function permissionCheck( \WP_REST_Request $request ) {
		return current_user_can( seopress_capability( 'manage_options', 'license' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		$permission = array( $this, 'permissionCheck' );

		register_rest_route(
			'seopress/v1',
			'/license',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getState' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/license/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => $permission,
				'args'                => array(
					'license' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/license/deactivate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'deactivate' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/license/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/license/clear-cache',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clearCache' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/license/probe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'probe' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Load the shared license helpers.
	 *
	 * REST requests bypass the PRO admin bootstrap (is_admin() is false), so the
	 * callbacks file that defines the shared activation helpers is not otherwise
	 * loaded here. Mirrors the on-demand require used by ProSettings.
	 *
	 * @return void
	 */
	private function loadHelpers() {
		if ( ! function_exists( 'seopress_pro_run_license_activation' ) ) {
			require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/admin/callbacks/License.php';
		}
	}

	/**
	 * Build the standard response payload (state + debug snapshot).
	 *
	 * @return array
	 */
	private function statePayload() {
		return array(
			'state' => seopress_pro_get_license_state(),
			'debug' => seopress_get_license_debug_data(),
		);
	}

	/**
	 * GET /license — return the current state and debug snapshot.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function getState( \WP_REST_Request $request ) {
		$this->loadHelpers();

		return new \WP_REST_Response( $this->statePayload(), 200 );
	}

	/**
	 * POST /license/activate — save the key (when editable) and activate.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function activate( \WP_REST_Request $request ) {
		$this->loadHelpers();

		// When the key is defined in wp-config.php it is authoritative; ignore
		// any submitted value and activate the constant.
		if ( defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY ) ) {
			$license = SEOPRESS_LICENSE_KEY;
		} else {
			$license = trim( (string) $request->get_param( 'license' ) );

			if ( '' === $license ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Please enter a license key.', 'wp-seopress-pro' ),
					),
					200
				);
			}

			// Persist the key first so the state reflects it even if activation fails.
			update_option( 'seopress_pro_license_key', $license );
		}

		$result = seopress_pro_run_license_activation( $license );

		if ( ! $result['success'] && '' !== $result['message'] ) {
			update_option( 'seopress_pro_license_key_error', $result['message'] );
		}

		return new \WP_REST_Response(
			array_merge(
				array(
					'success' => $result['success'],
					'message' => $result['success'] ? __( 'Your license has been successfully activated.', 'wp-seopress-pro' ) : $result['message'],
				),
				$this->statePayload()
			),
			200
		);
	}

	/**
	 * POST /license/deactivate — deactivate the license.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function deactivate( \WP_REST_Request $request ) {
		$this->loadHelpers();

		$result = seopress_pro_run_license_deactivation();

		return new \WP_REST_Response(
			array_merge(
				array(
					'success' => $result['success'],
					'message' => $result['success'] ? __( 'Your license has been successfully deactivated.', 'wp-seopress-pro' ) : $result['message'],
				),
				$this->statePayload()
			),
			200
		);
	}

	/**
	 * POST /license/reset — clear all local license state.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function reset( \WP_REST_Request $request ) {
		$this->loadHelpers();

		seopress_pro_run_license_reset();

		return new \WP_REST_Response(
			array_merge(
				array(
					'success' => true,
					'message' => __( 'Your license has been reset.', 'wp-seopress-pro' ),
				),
				$this->statePayload()
			),
			200
		);
	}

	/**
	 * POST /license/clear-cache — flush the EDD updater caches.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function clearCache( \WP_REST_Request $request ) {
		$this->loadHelpers();

		seopress_clear_edd_updater_cache();

		return new \WP_REST_Response(
			array_merge(
				array(
					'success' => true,
					'message' => __( 'Update cache cleared. WordPress will check for updates again on the next request.', 'wp-seopress-pro' ),
				),
				$this->statePayload()
			),
			200
		);
	}

	/**
	 * POST /license/probe — live connectivity test against the store.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function probe( \WP_REST_Request $request ) {
		$this->loadHelpers();

		$license = defined( 'SEOPRESS_LICENSE_KEY' ) && ! empty( SEOPRESS_LICENSE_KEY ) && is_string( SEOPRESS_LICENSE_KEY ) ? SEOPRESS_LICENSE_KEY : trim( (string) get_option( 'seopress_pro_license_key' ) );

		$response = wp_remote_post(
			STORE_URL_SEOPRESS,
			array(
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
				'timeout'    => 15,
				'body'       => array(
					'edd_action' => 'get_version',
					'license'    => $license,
					'item_id'    => ITEM_ID_SEOPRESS,
					'version'    => defined( 'SEOPRESS_PRO_VERSION' ) ? SEOPRESS_PRO_VERSION : '',
					'slug'       => 'wp-seopress-pro',
					'url'        => get_option( 'home' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$probe = array(
				'ok'      => false,
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'body'    => '',
			);
		} else {
			$probe = array(
				'ok'      => 200 === wp_remote_retrieve_response_code( $response ),
				'code'    => (string) wp_remote_retrieve_response_code( $response ),
				'message' => '',
				'body'    => substr( (string) wp_remote_retrieve_body( $response ), 0, 1000 ),
			);
		}

		return new \WP_REST_Response(
			array_merge( array( 'probe' => $probe ), $this->statePayload() ),
			200
		);
	}
}
