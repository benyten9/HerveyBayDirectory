<?php

namespace SEOPressPro\CommandLine;

use WP_CLI_Command;
use WP_CLI;

class Settings extends WP_CLI_Command {

	/**
	 * This command import the SEOPress settings from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<value>]
	 * : From path for the file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress settings import --from=/home/user
	 */
	public function import( $args, $assoc_args ) {
		if ( is_null( seopress_get_service( 'ImportSettings' ) ) ) {
			WP_CLI::line( 'SEOPress is not up to date. Please update SEOPress to the latest version.' );
			return;
		}

		$from = isset( $assoc_args['from'] ) ? $assoc_args['from'] : false;

		if ( ! $from ) {
			$from = wp_upload_dir();
			if ( ! empty( $from['basedir'] ) ) {
				$from = $from['basedir'];
			} else {
				$from = SEOPRESS_PRO_PLUGIN_DIR_PATH;
			}
		}

		$from = trailingslashit( $from ) . 'seopress-settings.json';

		if ( ! file_exists( $from ) ) {
			WP_CLI::error( sprintf( 'The file %s does not exist.', $from ) );
		}

		WP_CLI::line( 'Importing SEOPress settings...' );

		$data = json_decode( file_get_contents( $from ), true );

		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( 'The file %s is not a valid JSON file.', $from ) );
		}

		seopress_get_service( 'ImportSettings' )->handle( $data );

		WP_CLI::success( 'SEOPress settings imported.' );
	}

	/**
	 * This command export the SEOPress settings to a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--destination=<value>]
	 * : Destination path for the file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress settings export --destination=/home/user/
	 */
	public function export( $args, $assoc_args ) {
		if ( is_null( seopress_get_service( 'ExportSettings' ) ) ) {
			WP_CLI::line( 'SEOPress is not up to date. Please update SEOPress to the latest version.' );
			return;
		}

		$settings = seopress_get_service( 'ExportSettings' )->handle();

		$destination = isset( $assoc_args['destination'] ) ? $assoc_args['destination'] : false;

		if ( ! $destination ) {
			$destination = wp_upload_dir();
			if ( ! empty( $destination['basedir'] ) ) {
				$destination = $destination['basedir'];
			} else {
				$destination = SEOPRESS_PRO_PLUGIN_DIR_PATH;
			}
		}

		$destination = trailingslashit( $destination ) . 'seopress-settings.json';
		file_put_contents( $destination, wp_json_encode( $settings ) );

		WP_CLI::line( sprintf( 'The file has been created: %s', $destination ) );
	}
}
