<?php

namespace SEOPressPro\CommandLine;

use WP_CLI_Command;
use WP_CLI;

class AI extends WP_CLI_Command {
	/**
	 * This command generate title with AI.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<value>]
	 * : List of Post ID separated by comma.
	 *
	 * [--meta=<value>]
	 * : Which meta to generate. Optional. Default: empty value for title + meta description
	 *
	 * [--language=<value>]
	 * : Which language to use to generate the metadata. Optional. Default: en_US
	 *
	 * [--autosave=<value>]
	 * : Boolean. Optional. Default: true
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress ai metadata --id=1 --meta=title --language=en_US
	 */
	public function metadata( $args, $assoc_args ) {
		if ( is_null( seopress_pro_get_service( 'Completions' ) ) ) {
			WP_CLI::line( 'SEOPress is not up to date. Please update SEOPress to the latest version.' );
			return;
		}

		$post_ids = isset( $assoc_args['id'] ) ? explode( ',', $assoc_args['id'] ) : null;

		if ( is_null( $post_ids ) || empty( $post_ids ) ) {
			WP_CLI::line( 'Post IDs are missing from the arguments.' );
			return;
		}

		$meta     = isset( $assoc_args['meta'] ) ? $assoc_args['meta'] : '';
		$language = isset( $assoc_args['language'] ) ? $assoc_args['language'] : 'en_US';
		$autosave = isset( $assoc_args['autosave'] ) ? $assoc_args['autosave'] : true;

		foreach ( $post_ids as $post_id ) {
			$data = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $post_id, $meta, $language, $autosave );

			$data_str = wp_json_encode( $data, JSON_PRETTY_PRINT );

			WP_CLI::line( 'Result for Post ID ' . $post_id . ':' );
			WP_CLI::line( $data_str );
		}
	}

	/**
	 * This command generate alt text with AI.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<value>]
	 * : List of Attachment ID separated by comma.
	 *
	 * [--autosave=<value>]
	 * : Boolean. Optional. Default: true
	 *
	 * [--language=<value>]
	 * : Which language to use to generate the alternative text. Optional. Default: en_US
	 *
	 * [--update_empty_alt_text=<value>]
	 * : Boolean. Optional. Default: true
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress ai alt_text --id=1 --language=en_US
	 */
	public function alt_text( $args, $assoc_args ) {
		if ( is_null( seopress_pro_get_service( 'Completions' ) ) ) {
			WP_CLI::line( 'SEOPress is not up to date. Please update SEOPress to the latest version.' );
			return;
		}

		$post_ids = isset( $assoc_args['id'] ) ? explode( ',', $assoc_args['id'] ) : null;

		if ( is_null( $post_ids ) || empty( $post_ids ) ) {
			WP_CLI::line( 'Post IDs are missing from the arguments.' );
			return;
		}

		$language              = isset( $assoc_args['language'] ) ? $assoc_args['language'] : 'en_US';
		$autosave              = isset( $assoc_args['autosave'] ) ? $assoc_args['autosave'] : true;
		$update_empty_alt_text = isset( $assoc_args['update_empty_alt_text'] ) ? $assoc_args['update_empty_alt_text'] : true;

		foreach ( $post_ids as $post_id ) {
			$data = seopress_pro_get_service( 'Completions' )->generateImgAltText( $post_id, $autosave, $language, $update_empty_alt_text );

			$data_str = wp_json_encode( $data, JSON_PRETTY_PRINT );

			WP_CLI::line( 'Result for Attachment ID ' . $post_id . ':' );
			WP_CLI::line( $data_str );
		}
	}
}
