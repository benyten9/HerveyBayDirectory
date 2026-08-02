<?php
/**
 * Paginated JSON export for SMTP email logs (ported from smtp Utils::export_items).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Rest;

use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class EmailLogExport {

	public static function get_max_execution_time() {
		$max_execution_time = 30;
		if ( function_exists( 'ini_get' ) ) {
			$t = ini_get( 'max_execution_time' );
			if ( $t ) {
				$max_execution_time = (int) $t;
			}
		}
		return (int) apply_filters( 'doublescale_smtp_max_execution_time', $max_execution_time * 0.75 );
	}

	public static function is_memory_limit_reached() {
		$memory_limit = ini_get( 'memory_limit' );
		if ( ! $memory_limit ) {
			$memory_limit = '128M';
		}
		$memory_limit = self::convert_to_bytes( $memory_limit );
		$memory_usage = memory_get_usage( true );
		return $memory_usage >= ( $memory_limit * 0.75 );
	}

	private static function convert_to_bytes( $value ) {
		$value     = trim( (string) $value );
		$last      = strtolower( $value[ strlen( $value ) - 1 ] );
		$new_value = (int) $value;
		switch ( $last ) {
			case 'g':
				$new_value *= GB_IN_BYTES;
				break;
			case 'm':
				$new_value *= MB_IN_BYTES;
				break;
			case 'k':
				$new_value *= KB_IN_BYTES;
				break;
		}
		return $new_value;
	}

	private static function init_filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return WP_Filesystem();
	}

	public static function get_temp_file_path( $file_prefix, $file_id ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return new WP_Error( 'doublescale_smtp_no_upload', 'Uploads unavailable' );
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'doublescale_smtp_upload_error', (string) $upload['error'] );
		}
		$temp_dir = trailingslashit( $upload['basedir'] ) . 'doublescale-smtp-temp';
		if ( function_exists( 'wp_mkdir_p' ) && ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'doublescale_smtp_cannot_create_dir', 'Cannot create temp dir' );
		}
		$index = trailingslashit( $temp_dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence.\n" );
		}
		$file_name = sanitize_file_name( "doublescale-smtp-export-{$file_prefix}-{$file_id}.json" );
		return trailingslashit( $temp_dir ) . $file_name;
	}

	public static function export_items( array $params, $callback ) {
		global $wp_filesystem;

		$file_id   = ! empty( $params['file_id'] ) ? $params['file_id'] : time();
		$file_path = self::get_temp_file_path( $params['file_prefix'] ?? 'email', $file_id );

		if ( is_wp_error( $file_path ) ) {
			return $file_path;
		}

		if ( ! empty( $params['download'] ) ) {
			self::export_json( $file_path );
		}

		if ( ! self::init_filesystem() ) {
			return new WP_Error(
				'doublescale_smtp_filesystem_error',
				esc_html__( 'Cannot initialize filesystem', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$existing_content = '';
		if ( $wp_filesystem->exists( $file_path ) ) {
			$existing_content = $wp_filesystem->get_contents( $file_path );
		}

		if ( (int) ( $params['offset'] ?? 0 ) === 0 ) {
			$existing_content = "[\n";
		}

		$start_time         = microtime( true );
		$max_execution_time = self::get_max_execution_time();

		while ( ( microtime( true ) - $start_time ) < $max_execution_time && ! self::is_memory_limit_reached() ) {
			$logs = call_user_func( $callback, $params['filter'] ?? false, $params['offset'] ?? 0, $params['limit'] ?? 100 );

			if ( empty( $logs ) ) {
				$existing_content = rtrim( $existing_content, ",\n" ) . "\n]\n";
				$wp_filesystem->put_contents( $file_path, $existing_content, FS_CHMOD_FILE );
				return new WP_REST_Response(
					array(
						'status'  => 'done',
						'file_id' => $file_id,
					),
					200
				);
			}

			foreach ( $logs as $log ) {
				$existing_content .= wp_json_encode( $log ) . ",\n";
				++$params['offset'];
			}
		}

		$wp_filesystem->put_contents( $file_path, $existing_content, FS_CHMOD_FILE );

		return new WP_REST_Response(
			array(
				'status'  => 'continue',
				'offset'  => $params['offset'] ?? 0,
				'file_id' => $file_id,
			),
			200
		);
	}

	public static function export_json( $file_path ) {
		global $wp_filesystem;
		if ( ! self::init_filesystem() ) {
			return;
		}
		$filename = 'logs_export.json';
		$filesize = $wp_filesystem->size( $file_path );
		$content  = $wp_filesystem->get_contents( $file_path );

		nocache_headers();
		header( 'X-Robots-Tag: noindex', true );
		header( 'Content-Type: application/json' );
		header( 'Content-Description: File Transfer' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '";' );
		header( 'Content-Length: ' . $filesize );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_delete_file( $file_path );
		exit;
	}
}
