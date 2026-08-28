<?php
/**
 * Square webhook receiver.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Square
 */

namespace DoubleScale\Pro\Modules\Integrations\Square;

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
			'/integrations/square/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Square signs `notification_url + raw_body` with HMAC-SHA256, base64 encoded.
	 *
	 * @param string $signature Value of the x-square-hmacsha256-signature header.
	 * @param string $body      Raw request body.
	 * @param string $url       Notification URL exactly as registered.
	 * @param string $key       Subscription signature key.
	 * @return bool
	 */
	public static function is_valid_signature( string $signature, string $body, string $url, string $key ): bool {
		if ( '' === $signature || '' === $key || '' === $url ) {
			return false;
		}

		$expected = base64_encode( hash_hmac( 'sha256', $url . $body, $key, true ) );

		return hash_equals( $expected, $signature );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$this->prime_gateway_listeners();

		$mode_settings = Integration::instance()->get_mode_settings();
		if ( ! $mode_settings ) {
			return new \WP_REST_Response( array( 'message' => 'Square is not configured.' ), 400 );
		}

		$signature_key = (string) ( $mode_settings['signature_key'] ?? '' );
		if ( '' === $signature_key ) {
			return new \WP_REST_Response( array( 'message' => 'Missing Square signature key.' ), 400 );
		}

		$payload   = $request->get_body();
		$signature = (string) (
			$request->get_header( 'x_square_hmacsha256_signature' )
			?: $request->get_header( 'X-Square-HmacSha256-Signature' )
		);

		if ( ! self::is_valid_signature( $signature, $payload, self::notification_url(), $signature_key ) ) {
			doublescale_get_logger()->warning(
				'Square webhook signature failed',
				array( 'code' => 'square_webhook_sig_failed' )
			);
			return new \WP_REST_Response( array( 'message' => 'Invalid signature.' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response( array( 'message' => 'Invalid payload.' ), 400 );
		}

		$event_id   = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
		$dedupe_key = '' !== $event_id ? 'ds_square_evt_' . md5( $event_id ) : '';
		if ( '' !== $dedupe_key && get_transient( $dedupe_key ) ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		try {
			do_action( 'doublescale_square_invoice_event', (object) $event );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Square webhook handler threw',
				array(
					'code'     => 'square_webhook_handler_threw',
					'event_id' => $event_id,
					'message'  => $e->getMessage(),
				)
			);
			return new \WP_REST_Response( array( 'message' => 'Handler error.' ), 500 );
		}

		if ( '' !== $dedupe_key ) {
			set_transient( $dedupe_key, 1, DAY_IN_SECONDS );
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * The URL Square signs against — must match what was registered exactly.
	 *
	 * @return string
	 */
	public static function notification_url(): string {
		if ( \defined( 'DOUBLESCALE_PUBLIC_REST_URL' ) && \DOUBLESCALE_PUBLIC_REST_URL ) {
			return \trailingslashit( \DOUBLESCALE_PUBLIC_REST_URL ) . 'doublescale/v1/integrations/square/webhook';
		}

		return \rest_url( 'doublescale/v1/integrations/square/webhook' );
	}

	/**
	 * @return void
	 */
	private function prime_gateway_listeners(): void {
		$class = 'DoubleScale\\Pro\\Modules\\Sales\\PaymentGateways\\SquareInvoiceWebhookHandler';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
			return;
		}
		try {
			$class::instance();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Square webhook gateway primer failed',
				array(
					'code'  => 'square_webhook_gateway_primer_failed',
					'class' => $class,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
