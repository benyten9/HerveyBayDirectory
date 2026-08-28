<?php
/**
 * Mollie webhook receiver.
 *
 * Mollie's webhook is unlike the others: the request body carries only the
 * payment `id` and there is no signature header. The payment must be re-fetched
 * from the API, which is what makes the notification trustworthy — an attacker
 * can only ever cause us to re-read a real payment we already own.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Mollie
 */

namespace DoubleScale\Pro\Modules\Integrations\Mollie;

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
			'/integrations/mollie/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Mollie payment ids look like `tr_xxxxxxxx`.
	 *
	 * @param string $id Candidate payment id.
	 * @return bool
	 */
	public static function is_valid_payment_id( string $id ): bool {
		return 1 === preg_match( '/^tr_[A-Za-z0-9]+$/', $id );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$this->prime_gateway_listeners();

		if ( ! Integration::instance()->is_configured() ) {
			return new \WP_REST_Response( array( 'message' => 'Mollie is not configured.' ), 400 );
		}

		$payment_id = (string) $request->get_param( 'id' );
		if ( '' === $payment_id || ! self::is_valid_payment_id( $payment_id ) ) {
			return new \WP_REST_Response( array( 'message' => 'Missing or malformed payment id.' ), 400 );
		}

		$api = Integration::instance()->connect();
		if ( ! $api instanceof Api ) {
			return new \WP_REST_Response( array( 'message' => 'Mollie is not configured.' ), 400 );
		}

		// The body is not trusted; this fetch is the source of truth.
		$result = $api->get_payment( $payment_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->warning(
				'Mollie webhook payment fetch failed',
				array(
					'code'       => 'mollie_webhook_fetch_failed',
					'payment_id' => $payment_id,
					'message'    => $result['message'] ?? '',
				)
			);
			// 200 so Mollie stops retrying an id we cannot resolve; a 4xx here
			// would have it retry for days.
			return new \WP_REST_Response( array( 'received' => true, 'resolved' => false ), 200 );
		}

		$payment = $result['data'];
		$status  = strtolower( (string) ( $payment['status'] ?? '' ) );

		// Dedupe per payment+status: Mollie re-notifies on every transition.
		$dedupe_key = 'ds_mollie_evt_' . md5( $payment_id . '|' . $status );
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
			do_action( 'doublescale_mollie_invoice_event', (object) $payment );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Mollie webhook handler threw',
				array(
					'code'       => 'mollie_webhook_handler_threw',
					'payment_id' => $payment_id,
					'message'    => $e->getMessage(),
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
			return \trailingslashit( \DOUBLESCALE_PUBLIC_REST_URL ) . 'doublescale/v1/integrations/mollie/webhook';
		}

		return \rest_url( 'doublescale/v1/integrations/mollie/webhook' );
	}

	/**
	 * @return void
	 */
	private function prime_gateway_listeners(): void {
		$class = 'DoubleScale\\Pro\\Modules\\Sales\\PaymentGateways\\MollieInvoiceWebhookHandler';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
			return;
		}
		try {
			$class::instance();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Mollie webhook gateway primer failed',
				array(
					'code'  => 'mollie_webhook_gateway_primer_failed',
					'class' => $class,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
