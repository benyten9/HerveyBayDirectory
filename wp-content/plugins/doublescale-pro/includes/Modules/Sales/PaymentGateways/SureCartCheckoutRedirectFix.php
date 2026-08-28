<?php
/**
 * Ensures SureCart test-mode invoice checkouts load with live_mode=false in the URL.
 *
 * SureCart checkout pages default to live mode unless the query string says otherwise.
 * Invoice checkouts created in test mode (mock processor on local installs) will show
 * no payment method and hang on "Processing" without this redirect.
 *
 * @package DoubleScale\Pro\Modules\Sales\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Sales\PaymentGateways;

defined( 'ABSPATH' ) || exit;

/**
 * SureCartCheckoutRedirectFix class.
 */
final class SureCartCheckoutRedirectFix {

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_redirect_test_checkout' ), 5 );
	}

	/**
	 * @return void
	 */
	public static function maybe_redirect_test_checkout(): void {
		if ( is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$checkout_id = isset( $_GET['checkout_id'] ) ? sanitize_text_field( wp_unslash( $_GET['checkout_id'] ) ) : '';
		if ( '' === $checkout_id || ! class_exists( '\SureCart\Models\Checkout' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'false' === sanitize_text_field( wp_unslash( $_GET['live_mode'] ?? '' ) ) ) {
			return;
		}

		try {
			$checkout = \SureCart\Models\Checkout::find( $checkout_id );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! $checkout || is_wp_error( $checkout ) || ! empty( $checkout->live_mode ) ) {
			return;
		}

		$metadata = $checkout->metadata ?? null;
		$is_ds    = false;
		if ( is_object( $metadata ) && ! empty( $metadata->doublescale_invoice_id ) ) {
			$is_ds = true;
		} elseif ( is_array( $metadata ) && ! empty( $metadata['doublescale_invoice_id'] ) ) {
			$is_ds = true;
		}

		if ( ! $is_ds ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( add_query_arg( 'live_mode', 'false' ) );
		exit;
	}
}
