<?php // phpcs:ignore

namespace SEOPressPro\Actions\Api\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * PRO Settings REST API endpoint.
 */
class ProSettings implements ExecuteHooks {
	/**
	 * @since 9.7.0
	 */
	public function hooks() {
		// The Free plugin registers a fallback handler for `/options/pro-settings`
		// at the default priority 10. Run later and override so the Pro handler
		// wins whenever Pro is active.
		add_action( 'rest_api_init', array( $this, 'register' ), 100 );

		// The Google Search Console JSON key field shown in the Pro GSC tab is
		// stored in `seopress_instant_indexing_option_name` (single source of
		// truth, also used by every GSC consumer). The React form initialises
		// from `INITIAL_SETTINGS` which is `get_option( 'seopress_pro_option_name' )`,
		// so inject the indexing value here to keep both screens in sync.
		add_filter( 'option_seopress_pro_option_name', array( $this, 'injectSharedIndexingKey' ) );
	}

	/**
	 * Inject the shared GSC JSON key from the indexing option into the Pro
	 * options array, so the React form sees the current value regardless of
	 * which screen last saved it.
	 *
	 * @param mixed $value Stored Pro option value.
	 *
	 * @return array
	 */
	public function injectSharedIndexingKey( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$indexing = get_option( 'seopress_instant_indexing_option_name' );
		if ( is_array( $indexing ) && isset( $indexing['seopress_instant_indexing_google_api_key'] ) ) {
			$value['seopress_instant_indexing_google_api_key'] = $indexing['seopress_instant_indexing_google_api_key'];
		}

		return $value;
	}

	/**
	 * Permission check.
	 *
	 * `current_user_can()` uses the live current user state, which
	 * `rest_cookie_check_errors` resets to 0 when no nonce is provided on a
	 * cookie-authenticated REST request. Caching the user ID at
	 * hook-registration time would silently bypass that check.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return boolean
	 */
	public function permissionCheck( \WP_REST_Request $request ) {
		return current_user_can( seopress_capability( 'manage_options', 'pro' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		// `WP_REST_Server::register_route()` REPLACES the registered endpoints
		// when $override is true, it does not merge. Splitting GET and POST
		// into two override calls would leave only the second method
		// registered (the first call's GET would be wiped by the POST call),
		// so register both methods in a single call.
		register_rest_route(
			'seopress/v1',
			'/options/pro-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGet' ),
					'permission_callback' => array( $this, 'permissionCheck' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processPost' ),
					'permission_callback' => array( $this, 'permissionCheck' ),
				),
			),
			true
		);

		register_rest_route(
			'seopress/v1',
			'/options/pro-mu-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGetMu' ),
					'permission_callback' => array( $this, 'permissionCheck' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processPostMu' ),
					'permission_callback' => array( $this, 'permissionCheck' ),
				),
			),
			true
		);
	}

	/**
	 * Process POST request.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processPost( \WP_REST_Request $request ) {
		$new_options = $request->get_json_params();

		if ( empty( $new_options ) || ! is_array( $new_options ) ) {
			return new \WP_Error(
				'invalid_data',
				__( 'Invalid data provided.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		// The GSC tab edits the Google service-account JSON key, but the GSC
		// sync code (GoogleClient, BotSearchConsole, InspectUrl, etc.) reads
		// it from the Free plugin's `seopress_instant_indexing_option_name`.
		// Mirror it there so the two screens stay in sync.
		$shared_indexing_value = null;
		if ( array_key_exists( 'seopress_instant_indexing_google_api_key', $new_options ) ) {
			$shared_indexing_value = $new_options['seopress_instant_indexing_google_api_key'];
			unset( $new_options['seopress_instant_indexing_google_api_key'] );

			$indexing_options = get_option( 'seopress_instant_indexing_option_name' );
			if ( ! is_array( $indexing_options ) ) {
				$indexing_options = array();
			}
			$indexing_options['seopress_instant_indexing_google_api_key'] = $shared_indexing_value;
			update_option( 'seopress_instant_indexing_option_name', $indexing_options );
		}

		// The sanitize file is included in the PRO admin bootstrap, which only
		// runs under is_admin(). REST requests bypass that bootstrap, so load
		// the function on demand to avoid the map_deep + sanitize_text_field
		// fallback collapsing newlines in textarea fields like llms.txt.
		if ( ! function_exists( 'seopress_pro_sanitize_options_fields' ) ) {
			require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/admin/sanitize/Sanitize.php';
		}

		$sanitized_options = seopress_pro_sanitize_options_fields( $new_options );

		update_option( 'seopress_pro_option_name', $sanitized_options );

		do_action( 'seopress_pro_settings_updated', $sanitized_options );

		// Mask API keys before returning.
		$safe_options = $sanitized_options;
		foreach ( $safe_options as $key => $value ) {
			if ( $this->isApiKeyField( $key ) && ! empty( $value ) ) {
				$safe_options[ $key ] = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
			}
		}

		// Echo back the shared indexing value so the React form keeps it after save.
		if ( null !== $shared_indexing_value ) {
			$safe_options['seopress_instant_indexing_google_api_key'] = $shared_indexing_value;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Settings saved successfully.', 'wp-seopress-pro' ),
				'data'    => $safe_options,
			),
			200
		);
	}

	/**
	 * Process GET request.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGet( \WP_REST_Request $request ) {
		$options = get_option( 'seopress_pro_option_name' );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		// One-shot migration for installs affected by the 9.7 regression where
		// the GSC tab wrote the JSON key into `seopress_pro_option_name`
		// instead of `seopress_instant_indexing_option_name`. Move it back so
		// the GSC sync code (which reads the indexing option) finds it.
		if ( ! empty( $options['seopress_instant_indexing_google_api_key'] ) ) {
			$indexing_options = get_option( 'seopress_instant_indexing_option_name' );
			if ( ! is_array( $indexing_options ) ) {
				$indexing_options = array();
			}
			if ( empty( $indexing_options['seopress_instant_indexing_google_api_key'] ) ) {
				$indexing_options['seopress_instant_indexing_google_api_key'] = $options['seopress_instant_indexing_google_api_key'];
				update_option( 'seopress_instant_indexing_option_name', $indexing_options );
			}
			unset( $options['seopress_instant_indexing_google_api_key'] );
			update_option( 'seopress_pro_option_name', $options );
		}

		// Inject the JSON key from the indexing option so the React GSC tab
		// shows the current value.
		$indexing_options = get_option( 'seopress_instant_indexing_option_name' );
		$shared_value     = is_array( $indexing_options ) && isset( $indexing_options['seopress_instant_indexing_google_api_key'] )
			? $indexing_options['seopress_instant_indexing_google_api_key']
			: '';

		if ( empty( $options ) && '' === $shared_value ) {
			return new \WP_REST_Response( array() );
		}

		$data = array();

		// AI API key constants map.
		$ai_key_constants = array(
			'seopress_ai_openai_api_key'   => 'SEOPRESS_OPENAI_KEY',
			'seopress_ai_deepseek_api_key' => 'SEOPRESS_DEEPSEEK_KEY',
			'seopress_ai_gemini_api_key'   => 'SEOPRESS_GEMINI_KEY',
			'seopress_ai_mistral_api_key'  => 'SEOPRESS_MISTRAL_KEY',
			'seopress_ai_claude_api_key'   => 'SEOPRESS_CLAUDE_KEY',
			'seopress_ai_seopress_api_key' => 'SEOPRESS_CREDITS_KEY',
		);

		foreach ( $options as $key => $value ) {
			// Mask API keys for security.
			if ( $this->isApiKeyField( $key ) ) {
				$has_constant = isset( $ai_key_constants[ $key ] ) && defined( $ai_key_constants[ $key ] ) && ! empty( constant( $ai_key_constants[ $key ] ) );

				if ( $has_constant || ! empty( $value ) ) {
					$data[ $key ] = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
				} else {
					$data[ $key ] = $value;
				}
			} else {
				$data[ $key ] = $value;
			}
		}

		$data['seopress_instant_indexing_google_api_key'] = $shared_value;

		return new \WP_REST_Response( $data );
	}

	/**
	 * Process GET request for multisite options.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetMu( \WP_REST_Request $request ) {
		$options = get_option( 'seopress_pro_mu_option_name' );

		if ( empty( $options ) ) {
			return new \WP_REST_Response( array() );
		}

		return new \WP_REST_Response( $options );
	}

	/**
	 * Process POST request for multisite options.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processPostMu( \WP_REST_Request $request ) {
		$new_options = $request->get_json_params();

		if ( empty( $new_options ) || ! is_array( $new_options ) ) {
			return new \WP_Error(
				'invalid_data',
				__( 'Invalid data provided.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		// Multiline textarea fields (virtual robots.txt / llms.txt) must keep
		// their line breaks. `sanitize_text_field` collapses newlines into
		// spaces, which flattens the whole file onto a single line and breaks
		// it, so sanitize those with `sanitize_textarea_field` and only run the
		// scalar `sanitize_text_field` pass on the remaining fields.
		$textarea_fields = array(
			'seopress_robots_file',
			'seopress_mu_robots_file',
			'seopress_llms_file',
			'seopress_mu_llms_file',
		);

		$sanitized_mu_options = array();
		foreach ( $new_options as $key => $value ) {
			if ( in_array( $key, $textarea_fields, true ) ) {
				$sanitized_mu_options[ $key ] = sanitize_textarea_field( $value );
			} else {
				$sanitized_mu_options[ $key ] = map_deep( $value, 'sanitize_text_field' );
			}
		}

		update_option( 'seopress_pro_mu_option_name', $sanitized_mu_options );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Settings saved successfully.', 'wp-seopress-pro' ),
				'data'    => $new_options,
			),
			200
		);
	}

	/**
	 * Check if a field is an API key that should be masked.
	 *
	 * @param string $key The field key.
	 *
	 * @return boolean
	 */
	private function isApiKeyField( $key ) {
		$api_key_fields = array(
			'seopress_ai_openai_api_key',
			'seopress_ai_deepseek_api_key',
			'seopress_ai_gemini_api_key',
			'seopress_ai_mistral_api_key',
			'seopress_ai_claude_api_key',
			'seopress_ai_seopress_api_key',
			'seopress_ps_api_key',
			'seopress_google_analytics_auth_secret_id',
			'seopress_google_analytics_auth_client_id',
		);

		return in_array( $key, $api_key_fields, true );
	}
}
