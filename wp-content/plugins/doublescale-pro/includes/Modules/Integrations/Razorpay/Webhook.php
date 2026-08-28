<?php
/**
 * Razorpay webhook receiver.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Razorpay
 */

namespace DoubleScale\Pro\Modules\Integrations\Razorpay;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook class.
 */
class Webhook {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'doublescale/v1',
			'/integrations/razorpay/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Razorpay signs the raw request body with HMAC-SHA256 and sends the hex
	 * digest in X-Razorpay-Signature.
	 *
	 * The body must be the untouched raw payload — re-encoding a decoded array
	 * changes key order and whitespace, which breaks the digest.
	 *
	 * @param string $signature Value of the X-Razorpay-Signature header.
	 * @param string $body      Raw request body.
	 * @param string $secret    Webhook secret configured in the dashboard.
	 * @return bool
	 */
	public static function is_valid_signature( string $signature, string $body, string $secret ): bool {
		if ( '' === $signature || '' === $secret ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );

		return hash_equals( $expected, strtolower( trim( $signature ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$this->prime_gateway_listeners();

		$mode_settings = Integration::instance()->get_mode_settings();
		if ( ! $mode_settings ) {
			return new \WP_REST_Response( array( 'message' => 'Razorpay is not configured.' ), 400 );
		}

		$secret = (string) ( $mode_settings['webhook_secret'] ?? '' );
		if ( '' === $secret ) {
			return new \WP_REST_Response( array( 'message' => 'Missing Razorpay webhook secret.' ), 400 );
		}

		$payload   = $request->get_body();
		$signature = (string) (
			$request->get_header( 'x_razorpay_signature' )
			?: $request->get_header( 'X-Razorpay-Signature' )
		);

		if ( ! self::is_valid_signature( $signature, $payload, $secret ) ) {
			doublescale_get_logger()->warning(
				'Razorpay webhook signature failed',
				array( 'code' => 'razorpay_webhook_sig_failed' )
			);
			return new \WP_REST_Response( array( 'message' => 'Invalid signature.' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response( array( 'message' => 'Invalid payload.' ), 400 );
		}

		// Razorpay has no per-delivery event id, so dedupe on the signature —
		// identical body + secret always yields the same digest.
		$dedupe_key = 'ds_razorpay_evt_' . md5( $signature );
		if ( get_transient( $dedupe_key ) ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		try {
			do_action( 'doublescale_razorpay_invoice_event', (object) $event );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Razorpay webhook handler threw',
				array(
					'code'    => 'razorpay_webhook_handler_threw',
					'event'   => (string) ( $event['event'] ?? '' ),
					'message' => $e->getMessage(),
				)
			);
			return new \WP_REST_Response( array( 'message' => 'Handler error.' ), 500 );
		}

		set_transient( $dedupe_key, 1, DAY_IN_SECONDS );

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * @return string
	 */
	public static function notification_url(): string {
		if ( \defined( 'DOUBLESCALE_PUBLIC_REST_URL' ) && \DOUBLESCALE_PUBLIC_REST_URL ) {
			return \trailingslashit( \DOUBLESCALE_PUBLIC_REST_URL ) . 'doublescale/v1/integrations/razorpay/webhook';
		}

		return \rest_url( 'doublescale/v1/integrations/razorpay/webhook' );
	}

	/**
	 * @return void
	 */
	private function prime_gateway_listeners(): void {
		$class = 'DoubleScale\\Pro\\Modules\\Sales\\PaymentGateways\\RazorpayInvoiceWebhookHandler';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
			return;
		}
		try {
			$class::instance();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Razorpay webhook gateway primer failed',
				array(
					'code'  => 'razorpay_webhook_gateway_primer_failed',
					'class' => $class,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
