<?php // phpcs:ignore

namespace SEOPressPro\Actions\Api\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * PRO Tools Settings REST API endpoints.
 */
class ToolsSettingsPro implements ExecuteHooks {
	/**
	 * @since 9.7.0
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
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
		return current_user_can( seopress_capability( 'manage_options', 'cleaning' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/tools/clean-audit-scans',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processCleanAuditScans' ),
				'permission_callback' => array( $this, 'permissionCheck' ),
			)
		);
	}

	/**
	 * Clean SEO audit scans.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processCleanAuditScans( \WP_REST_Request $request ) {
		global $wpdb;

		// Clean custom table if it exists.
		$table_name = $wpdb->prefix . 'seopress_seo_issues';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "DELETE FROM `{$table_name}`" );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'SEO audit scans have been successfully deleted.', 'wp-seopress-pro' ),
			),
			200
		);
	}
}
