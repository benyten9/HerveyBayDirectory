<?php

namespace SEOPressPro\Actions;

defined( 'ABSPATH' ) || exit;

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Handle email unsubscribe requests on the frontend.
 *
 * @since 9.8.0
 */
class Unsubscribe implements ExecuteHooks {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'handle_unsubscribe_request' ) );
	}

	/**
	 * Process the unsubscribe request from the query string.
	 *
	 * @return void
	 */
	public function handle_unsubscribe_request() {
		if ( ! isset( $_GET['seopress_unsubscribe'] ) ) {
			return;
		}

		$type  = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( empty( $type ) || empty( $email ) || empty( $token ) ) {
			$this->render_page( false, __( 'Invalid unsubscribe link.', 'wp-seopress-pro' ) );
			return;
		}

		$unsubscribe_service = new \SEOPressPro\Services\Email\UnsubscribeService();

		if ( ! $unsubscribe_service->verify_token( $email, $type, $token ) ) {
			$this->render_page( false, __( 'Invalid or expired unsubscribe link.', 'wp-seopress-pro' ) );
			return;
		}

		$result = $unsubscribe_service->process_unsubscribe( $email, $type );
		$label  = $unsubscribe_service->get_alert_label( $type );

		if ( $result ) {
			$this->render_page(
				true,
				sprintf(
					/* translators: %1$s email address, %2$s alert type label */
					__( '%1$s has been unsubscribed from %2$s notifications.', 'wp-seopress-pro' ),
					'<strong>' . esc_html( $email ) . '</strong>',
					'<strong>' . esc_html( $label ) . '</strong>'
				)
			);
		} else {
			$this->render_page( false, __( 'Unable to process your unsubscribe request. The email address may have already been removed.', 'wp-seopress-pro' ) );
		}
	}

	/**
	 * Render an unsubscribe result page using the default WordPress wp_die() style.
	 *
	 * @param bool   $success Whether the unsubscribe was successful.
	 * @param string $message The message to display (can contain safe HTML).
	 *
	 * @return void
	 */
	private function render_page( $success, $message ) {
		$title = $success
			? __( 'Unsubscribed successfully', 'wp-seopress-pro' )
			: __( 'Unsubscribe error', 'wp-seopress-pro' );

		wp_die(
			wp_kses_post( $message ),
			esc_html( $title ),
			array( 'response' => $success ? 200 : 403 )
		);
	}
}
