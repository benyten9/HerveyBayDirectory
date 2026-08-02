<?php
/**
 * Serves signed attachment downloads on the front end (all modules).
 *
 * @package DoubleScale\Core\Renderer
 */

namespace DoubleScale\Core\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\AttachmentService;

/**
 * AttachmentServeHandler class.
 */
final class AttachmentServeHandler {

	/**
	 * Wire the template_redirect listener.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Intercept signed download requests (`ds_file` or legacy `ds_support_file`).
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file_hash = '';
		$sign      = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['ds_file'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$file_hash = sanitize_text_field( wp_unslash( (string) $_GET['ds_file'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sign = isset( $_GET['ds_sign'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ds_sign'] ) ) : '';
		} elseif ( ! empty( $_GET['ds_support_file'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$file_hash = sanitize_text_field( wp_unslash( (string) $_GET['ds_support_file'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sign = isset( $_GET['ds_support_sign'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ds_support_sign'] ) ) : '';
		}

		if ( '' === $file_hash ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'GET' !== $method ) {
			wp_die( esc_html__( 'Invalid request method.', 'doublescale' ), esc_html__( 'Method Not Allowed', 'doublescale' ), array( 'response' => 405 ) );
		}

		( new AttachmentService() )->serve( $file_hash, $sign );
	}
}
