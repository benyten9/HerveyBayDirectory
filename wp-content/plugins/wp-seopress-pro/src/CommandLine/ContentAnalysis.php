<?php

namespace SEOPressPro\CommandLine;

defined( 'ABSPATH' ) || exit;

/** Recalculate content scores through the existing Site Audit queue. */
class ContentAnalysis extends \WP_CLI_Command {
	/**
	 * Start a background Site Audit to refresh stored content analysis scores.
	 *
	 * Uses the configured audit batch size and exclusions. Requires working
	 * WP-Cron (or a system scheduler running due WordPress cron events).
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Updates the Site Audit post-type setting.
	 *   If omitted, the existing Site Audit scope is preserved.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress content-analysis run --post_type=product
	 *     wp seopress content-analysis status
	 */
	public function run( $args, $assoc_args ) {
		if ( '1' !== seopress_get_service( 'ToggleOption' )->getToggleBot() ) {
			\WP_CLI::error( __( 'Enable Site Audit before starting a content analysis run.', 'wp-seopress-pro' ) );
		}
		if ( 1 === (int) get_option( 'seopress_pro_site_audit_running', 0 ) ) {
			\WP_CLI::error( __( 'A Site Audit is already running. Check its status or cancel it before starting another run.', 'wp-seopress-pro' ) );
		}
		if ( isset( $assoc_args['post_type'] ) ) {
			$types = array_unique( array_filter( array_map( 'trim', explode( ',', $assoc_args['post_type'] ) ) ) );
			$available = array_keys( seopress_get_service( 'WordPressData' )->getPostTypes() );
			if ( empty( $types ) || array_diff( $types, $available ) ) {
				\WP_CLI::error( __( 'Choose registered public post types supported by Site Audit.', 'wp-seopress-pro' ) );
			}
			$options = get_option( 'seopress_bot_option_name', array() );
			$options['seopress_bot_scan_settings_audit_cpt'] = array_fill_keys( $types, '1' );
			update_option( 'seopress_bot_option_name', $options );
		}
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'action', 'start' );
		$result = ( new \SEOPressPro\Actions\Api\SiteAudit() )->processScan( $request );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( __( 'Site Audit queued. Scores will be refreshed in background batches; use wp seopress content-analysis status to follow progress.', 'wp-seopress-pro' ) );
	}

	/**
	 * Print the current Site Audit progress and last worker error as JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress content-analysis status
	 */
	public function status( $args, $assoc_args ) {
		$data = ( new \SEOPressPro\Actions\Api\SiteAudit() )->processScanStatus()->get_data();
		$data['lastError'] = get_option( 'seopress_pro_site_audit_last_error', null );
		\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Cancel the shared Site Audit queue without deleting stored scores.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress content-analysis cancel
	 */
	public function cancel( $args, $assoc_args ) {
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'action', 'cancel' );
		( new \SEOPressPro\Actions\Api\SiteAudit() )->processScan( $request );
		\WP_CLI::success( __( 'Site Audit canceled. Existing content analysis scores are retained.', 'wp-seopress-pro' ) );
	}
}
