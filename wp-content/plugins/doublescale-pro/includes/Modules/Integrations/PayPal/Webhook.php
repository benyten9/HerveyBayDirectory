<?php
/**
 * PayPal webhook receiver.
 *
 * @package DoubleScale\Pro\Modules\Integrations\PayPal
 */

namespace DoubleScale\Pro\Modules\Integrations\PayPal;

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
			'/integrations/paypal/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$this->prime_gateway_listeners();

		$mode_settings = Integration::instance()->get_mode_settings();
		if ( ! $mode_settings ) {
			return new \WP_REST_Response( array( 'message' => 'PayPal is not configured.' ), 400 );
		}

		$payload = $request->get_body();
		$headers = $this->extract_transmission_headers( $request );

		if ( '' === $headers['transmission_sig'] || '' === ( $mode_settings['webhook_id'] ?? '' ) ) {
			return new \WP_REST_Response( array( 'message' => 'Missing PayPal webhook headers or webhook ID.' ), 400 );
		}

		$api    = new Api(
			(string) $mode_settings['client_id'],
			(string) $mode_settings['secret'],
			(string) $mode_settings['mode']
		);
		$verify = $api->verify_webhook_signature( $headers, $payload, (string) $mode_settings['webhook_id'] );

		if ( ! $verify['success'] || ( $verify['data']['verification_status'] ?? '' ) !== 'SUCCESS' ) {
			doublescale_get_logger()->warning(
				'PayPal webhook signature failed',
				array(
					'code'    => 'paypal_webhook_sig_failed',
					'message' => $verify['message'] ?? '',
				)
			);
			return new \WP_REST_Response( array( 'message' => 'Invalid signature.' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response( array( 'message' => 'Invalid payload.' ), 400 );
		}

		$event_id   = isset( $event['id'] ) ? (string) $event['id'] : '';
		$dedupe_key = '' !== $event_id ? 'ds_paypal_evt_' . md5( $event_id ) : '';
		if ( '' !== $dedupe_key && get_transient( $dedupe_key ) ) {
			return new \WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$invoice_id = $this->resolve_invoice_id( $event, $api );

		try {
			do_action( 'doublescale_paypal_invoice_event', (object) $event, $invoice_id );
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'PayPal webhook handler threw',
				array(
					'code'     => 'paypal_webhook_handler_threw',
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
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, string>
	 */
	private function extract_transmission_headers( \WP_REST_Request $request ): array {
		return array(
			'auth_algo'         => (string) ( $request->get_header( 'paypal_auth_algo' ) ?: $request->get_header( 'PAYPAL-AUTH-ALGO' ) ),
			'cert_url'          => (string) ( $request->get_header( 'paypal_cert_url' ) ?: $request->get_header( 'PAYPAL-CERT-URL' ) ),
			'transmission_id'   => (string) ( $request->get_header( 'paypal_transmission_id' ) ?: $request->get_header( 'PAYPAL-TRANSMISSION-ID' ) ),
			'transmission_sig'  => (string) ( $request->get_header( 'paypal_transmission_sig' ) ?: $request->get_header( 'PAYPAL-TRANSMISSION-SIG' ) ),
			'transmission_time' => (string) ( $request->get_header( 'paypal_transmission_time' ) ?: $request->get_header( 'PAYPAL-TRANSMISSION-TIME' ) ),
		);
	}

	/**
	 * @param array $event PayPal webhook event.
	 * @param Api   $api   API client.
	 * @return int
	 */
	private function resolve_invoice_id( array $event, Api $api ): int {
		$resource = isset( $event['resource'] ) && is_array( $event['resource'] ) ? $event['resource'] : array();

		$invoice_id = $this->invoice_id_from_custom_id( (string) ( $resource['custom_id'] ?? '' ) );
		if ( $invoice_id > 0 ) {
			return $invoice_id;
		}

		$order_id = (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' );
		if ( '' === $order_id ) {
			return 0;
		}

		$order = $api->get_order( $order_id );
		if ( ! $order['success'] ) {
			return 0;
		}

		$custom_id = (string) ( $order['data']['purchase_units'][0]['custom_id'] ?? '' );
		return $this->invoice_id_from_custom_id( $custom_id );
	}

	/**
	 * @param string $custom_id PayPal custom_id value.
	 * @return int
	 */
	private function invoice_id_from_custom_id( string $custom_id ): int {
		$custom_id = trim( $custom_id );
		if ( '' === $custom_id ) {
			return 0;
		}
		if ( preg_match( '/^invoice_(\d+)$/', $custom_id, $matches ) ) {
			return (int) $matches[1];
		}
		if ( ctype_digit( $custom_id ) ) {
			return (int) $custom_id;
		}
		return 0;
	}

	/**
	 * @return void
	 */
	private function prime_gateway_listeners(): void {
		$class = 'DoubleScale\\Pro\\Modules\\Sales\\PaymentGateways\\PayPalInvoiceWebhookHandler';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
			return;
		}
		try {
			$class::instance();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'PayPal webhook gateway primer failed',
				array(
					'code'  => 'paypal_webhook_gateway_primer_failed',
					'class' => $class,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
