<?php
/**
 * Authorize.Net webhook receiver.
 *
 * @package DoubleScale\Pro\Modules\Integrations\AuthorizeNet
 */

namespace DoubleScale\Pro\Modules\Integrations\AuthorizeNet;

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
			'/integrations/authorize-net/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Authorize.Net signs the raw body with HMAC-SHA512 using the Signature Key
	 * and sends it as `sha512=<HEX>` in X-ANET-Signature.
	 *
	 * The signature key is a hex string in the dashboard but is used as raw
	 * *bytes*, so it must be hex-decoded before hashing — hashing the literal
	 * string is the classic mistake here and silently rejects every delivery.
	 *
	 * @param string $header Value of the X-ANET-Signature header.
	 * @param string $body   Raw request body.
	 * @param string $key    Signature key (hex) from the dashboard.
	 * @return bool
	 */
	public static function is_valid_signature( string $header, string $body, string $key ): bool {
		if ( '' === $header || '' === $key ) {
			return false;
		}

		$provided = trim( $header );
		if ( 0 === stripos( $provided, 'sha512=' ) ) {
			$provided = substr( $provided, 7 );
		}
		$provided = strtolower( trim( $provided ) );
		if ( '' === $provided ) {
			return false;
		}

		$binary_key = self::decode_key( $key );
		$expected   = strtolower( hash_hmac( 'sha512', $body, $binary_key ) );

		return hash_equals( $expected, $provided );
	}

	/**
	 * The dashboard shows the signature key as hex; use its bytes.
	 *
	 * @param string $key Signature key.
	 * @return string
	 */
	private static function decode_key( string $key ): string {
		$key = trim( $key );

		if ( 1 === preg_match( '/^[0-9a-fA-F]+$/', $key ) && 0 === strlen( $key ) % 2 ) {
			$binary = hex2bin( $key );
			if ( false !== $binary ) {
				return $binary;
			}
		}

		return $key;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$this->prime_gateway_listeners();

		$mode_settings = Integration::instance()->get_mode_settings();
		if ( ! $mode_settings ) {
			return new \WP_REST_Response( array( 'message' => 'Authorize.Net is not configured.' ), 400 );
		}

		$signature_key = (string) ( $mode_settings['signature_key'] ?? '' );
		if ( '' === $signature_key ) {
			return new \WP_REST_Response( array( 'message' => 'Missing Authorize.Net signature key.' ), 400 );
		}

		$payload = $request->get_body();
		$header  = (string) (
			$request->get_header( 'x_anet_signature' )
			?: $request->get_header( 'X-ANET-Signature' )
		);

		if ( ! self::is_valid_signature( $header, $payload, $signature_key ) ) {
			doublescale_get_logger()->warning(
				'Authorize.Net webhook signature failed',
				array( 'code' => 'authorize_net_webhook_sig_failed' )
			);
			return new \WP_REST_Response( array( 'message' => 'Invalid signature.' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response( array( 'message' => 'Invalid payload.' ), 400 );
		}

		$notification_id = (string) ( $event['notificationId'] ?? '' );
		$dedupe_key      = '' !== $notification_id
			? 'ds_authnet_evt_' . md5( $notification_id )
			: '';

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
			do_action( 'doublescale_authorize_net_invoice_event', (object) $event );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Authorize.Net webhook handler threw',
				array(
					'code'    => 'authorize_net_webhook_handler_threw',
					'event'   => (string) ( $event['eventType'] ?? '' ),
					'message' => $e->getMessage(),
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
	 * @return string
	 */
	public static function notification_url(): string {
		if ( \defined( 'DOUBLESCALE_PUBLIC_REST_URL' ) && \DOUBLESCALE_PUBLIC_REST_URL ) {
			return \trailingslashit( \DOUBLESCALE_PUBLIC_REST_URL ) . 'doublescale/v1/integrations/authorize-net/webhook';
		}

		return \rest_url( 'doublescale/v1/integrations/authorize-net/webhook' );
	}

	/**
	 * @return void
	 */
	private function prime_gateway_listeners(): void {
		$class = 'DoubleScale\\Pro\\Modules\\Sales\\PaymentGateways\\AuthorizeNetInvoiceWebhookHandler';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
			return;
		}
		try {
			$class::instance();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Authorize.Net webhook gateway primer failed',
				array(
					'code'  => 'authorize_net_webhook_gateway_primer_failed',
					'class' => $class,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
