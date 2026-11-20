<?php
namespace SEOPressPro\Actions\Api\Diagnostics;
defined( 'ABSPATH' ) || exit;

use SEOPress\Core\Hooks\ExecuteHooks;

/** Check the saved virtual file against the public response. */
class LlmsTxt implements ExecuteHooks {
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}
	public function permission_check() {
		return current_user_can( seopress_capability( 'manage_options', 'pro' ) );
	}
	public function register() {
		register_rest_route( 'seopress/v1', '/diagnostics/llms-txt', array(
			'methods' => 'POST',
			'callback' => array( $this, 'process' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );
	}
	protected function has_physical_file() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return is_file( get_home_path() . 'llms.txt' );
	}
	public function process() {
		if ( ! $this->permission_check() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You cannot manage PRO settings.', 'wp-seopress-pro' ), array( 'status' => 403 ) );
		}
		$result = array( 'status' => 'warning', 'url' => '', 'http_code' => 0 );
		if ( '1' !== seopress_get_toggle_option( 'llms' ) ) {
			$result['message'] = __( 'Enable llms.txt before checking its delivery.', 'wp-seopress-pro' );
			return rest_ensure_response( $result );
		}
		require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/options-llms-txt.php';
		$result['url'] = seopress_llms_get_txt_url();
		if ( $this->has_physical_file() ) {
			$result['message'] = __( 'A physical llms.txt file exists at your site root and can override these settings. Back it up and remove or rename it if you want SEOPress to serve the virtual file, then check again.', 'wp-seopress-pro' );
			return rest_ensure_response( $result );
		}
		$rate_key = 'seopress_llms_check_' . get_current_user_id();
		if ( get_transient( $rate_key ) ) {
			return new \WP_Error( 'rate_limited', __( 'Please wait a few seconds before checking again.', 'wp-seopress-pro' ), array( 'status' => 429 ) );
		}
		set_transient( $rate_key, 1, 5 );
		$expected = seopress_get_llms_txt_content();
		$limit = 512 * 1024;
		if ( strlen( $expected ) > $limit ) {
			$result['message'] = __( 'This file is too large for the automatic check. Open the public URL and compare it with your saved settings.', 'wp-seopress-pro' );
			return rest_ensure_response( $result );
		}
		$response = wp_safe_remote_get( $result['url'], array( 'timeout' => 10, 'redirection' => 3, 'limit_response_size' => $limit + 1 ) );
		if ( is_wp_error( $response ) ) {
			$result['message'] = __( 'The server could not fetch llms.txt. Open its URL and ask your host to check loopback requests, firewall rules and TLS.', 'wp-seopress-pro' );
		} else {
			$result['http_code'] = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $result['http_code'] ) {
				/* translators: %d: HTTP status returned by the public file. */
				$result['message'] = sprintf( __( 'llms.txt returned HTTP %d. Check access restrictions and server rules for .txt files.', 'wp-seopress-pro' ), $result['http_code'] );
			} elseif ( wp_remote_retrieve_body( $response ) !== $expected ) {
				$result['message'] = __( 'The public file differs from the saved SEOPress output. Check for a physical llms.txt file, another plugin, or stale server/CDN caches.', 'wp-seopress-pro' );
			} else {
				$result['status'] = 'success';
				$result['message'] = __( 'The public llms.txt matches the output generated from your saved settings.', 'wp-seopress-pro' );
			}
		}
		return rest_ensure_response( $result );
	}
}
