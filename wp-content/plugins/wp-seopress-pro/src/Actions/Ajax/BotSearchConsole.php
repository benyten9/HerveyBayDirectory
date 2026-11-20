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

		// The browser sends the rows back in batches to be saved. Keep what
		// Google answered, so the save only accepts metrics that came from it.
		if ( isset( $rows['status'], $rows['data'] ) && 'success' === $rows['status'] && is_array( $rows['data'] ) ) {
			$fetched = array();
			foreach ( $rows['data'] as $row ) {
				$metrics = self::rowMetrics( $row );
				if ( null !== $metrics ) {
					$fetched[ esc_url_raw( $metrics['keys'][0] ) ] = $metrics;
				}
			}

			set_transient( self::fetchedRowsKey(), $fetched, HOUR_IN_SECONDS );
		}

		wp_send_json_success( $rows );
	}

	/**
	 * Transient holding the rows Google answered for the current user.
	 *
	 * @return string
	 */
	protected static function fetchedRowsKey() {
		return 'seopress_gsc_fetched_rows_' . get_current_user_id();
	}

	/**
	 * URL and metrics of a Search Console row, whether it is the API object or
	 * the array the browser sent back.
	 *
	 * @param mixed $row Row.
	 *
	 * @return array|null keys, clicks, ctr, impressions, position; null without a URL.
	 */
	protected static function rowMetrics( $row ) {
		$row  = is_object( $row ) ? (array) json_decode( wp_json_encode( $row ), true ) : $row;
		$keys = is_array( $row ) && isset( $row['keys'] ) ? (array) $row['keys'] : array();

		if ( ! isset( $keys[0] ) || ! is_scalar( $keys[0] ) || '' === (string) $keys[0] ) {
			return null;
		}

		return array(
			'keys'        => array( (string) $keys[0] ),
			'clicks'      => isset( $row['clicks'] ) ? (float) $row['clicks'] : 0,
			'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0,
			'impressions' => isset( $row['impressions'] ) ? (float) $row['impressions'] : 0,
			'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0,
		);
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

		// Only used to look rows up in what Google returned, through
		// esc_url_raw(): sanitize_text_field() strips the percent-encoded
		// characters of a non-ASCII URL, and those URLs collapsed into one.
		$rows = wp_unslash( $_POST['rows'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Only rows Google answered to this user's last fetch are saved, with
		// the metrics Google gave. The browser is trusted with the batching, not
		// with the numbers: posting forged rows wrote any metric on any post.
		$fetched = get_transient( self::fetchedRowsKey() );
		if ( ! is_array( $fetched ) ) {
			wp_send_json_error( 'expired' );
		}

		$service = seopress_pro_get_service( 'SearchConsole' );

		$count_save_matches = 0;
		foreach ( (array) $rows as $row ) {
			$submitted = self::rowMetrics( $row );
			$key       = null === $submitted ? '' : esc_url_raw( $submitted['keys'][0] );
			if ( '' === $key || ! isset( $fetched[ $key ] ) ) {
				continue;
			}

			$result = $service->saveDataFromRowResult( $fetched[ $key ] );
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
