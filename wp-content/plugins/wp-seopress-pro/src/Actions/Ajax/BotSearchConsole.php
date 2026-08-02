<?php // phpcs:ignore
/*
 * SEOPress PRO Bot Search Console.
 *
 * This file is used to handle the search console data.
 *
 * @package SEOPress PRO
 * @subpackage Actions
 */

namespace SEOPressPro\Actions\Ajax;

defined( 'ABSPATH' ) || exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Bot Search Console class.
 */
class BotSearchConsole implements ExecuteHooks {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_ajax_seopress_request_data_search_console', array( $this, 'handleSearchConsole' ) );
		add_action( 'wp_ajax_seopress_request_save_search_console', array( $this, 'handle' ) );
	}

	/**
	 * Handle search console.
	 *
	 * @return void
	 */
	public function handleSearchConsole() {
		check_ajax_referer( 'seopress_nonce_search_console' );

		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( seopress_capability( 'manage_options', 'bot' ) ) ) { // phpcs:ignore
			return;
		}

		// Get Google API Key.
		$options        = get_option( 'seopress_instant_indexing_option_name' );
		$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';

		if ( empty( $google_api_key ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$service = seopress_pro_get_service( 'SearchConsole' );
		$rows    = $service->handle();

		wp_send_json_success( $rows );
	}

	/**
	 * Handle save data.
	 *
	 * @return void
	 */
	public function handle() {

		check_ajax_referer( 'seopress_nonce_search_console' );

		if ( ! is_admin() ) {
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! current_user_can( seopress_capability( 'manage_options', 'bot' ) ) ) { // phpcs:ignore
			wp_send_json_error( 'not_authorized' );
		}

		if ( ! isset( $_POST['rows'] ) ) {
			wp_send_json_error( 'missing_parameters' );
		}

		$rows = map_deep( wp_unslash( $_POST['rows'] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$service = seopress_pro_get_service( 'SearchConsole' );

		$count_save_matches = 0;
		foreach ( $rows as $row ) {
			$result = $service->saveDataFromRowResult( $row );
			if ( $result && isset( $result['post_id'] ) ) {
				++$count_save_matches;
			}
		}

		wp_send_json_success(
			array(
				'total_matches' => $count_save_matches,
			)
		);
	}
}
