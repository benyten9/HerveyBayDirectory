<?php
/**
 * Stream a generated document file from a REST callback.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * DocumentDownload service.
 */
final class DocumentDownload {

	/**
	 * Returning raw bytes through WP_REST_Response JSON-encodes them, which
	 * corrupts the file. We hook `rest_pre_serve_request` to emit the bytes
	 * ourselves and short-circuit the default JSON serialization.
	 *
	 * @param string $bytes        File contents.
	 * @param string $filename     Download filename (extension optional).
	 * @param string $extension    Extension without the dot, e.g. `pdf`, `xml`.
	 * @param string $content_type Content-Type header value.
	 * @return \WP_REST_Response Response to return from the callback.
	 */
	public static function stream( string $bytes, string $filename, string $extension, string $content_type ): \WP_REST_Response {
		$download_name = self::filename( $filename, $extension );

		\add_filter(
			'rest_pre_serve_request',
			static function ( $served ) use ( $bytes, $download_name, $content_type ) {
				if ( $served ) {
					return $served;
				}

				if ( ! headers_sent() ) {
					header( 'Content-Type: ' . $content_type );
					header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
					header( 'Content-Length: ' . strlen( $bytes ) );
					header( 'X-Content-Type-Options: nosniff' );
					header( 'Cache-Control: private, no-store, max-age=0' );
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw file bytes.
				echo $bytes;

				return true;
			},
			10,
			1
		);

		// Body is ignored because the filter above already emitted the bytes,
		// but we return a 200 response so the REST server proceeds to serve.
		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', $content_type );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $download_name . '"' );

		return $response;
	}

	/**
	 * @param string $filename  Base filename.
	 * @param string $extension Extension without the dot.
	 * @return string Sanitised filename ending in the extension.
	 */
	public static function filename( string $filename, string $extension ): string {
		$download_name = \sanitize_file_name( $filename );
		if ( '' === $download_name ) {
			$download_name = 'document';
		}

		$suffix = '.' . strtolower( $extension );
		if ( strtolower( substr( $download_name, -strlen( $suffix ) ) ) !== $suffix ) {
			$download_name .= $suffix;
		}

		return $download_name;
	}
}
