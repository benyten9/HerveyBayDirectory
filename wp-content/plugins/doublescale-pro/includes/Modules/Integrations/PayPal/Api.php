<?php
/**
 * PayPal Orders v2 REST API client (wp_remote_* — no SDK).
 *
 * @package DoubleScale\Pro\Modules\Integrations\PayPal
 */

namespace DoubleScale\Pro\Modules\Integrations\PayPal;

defined( 'ABSPATH' ) || exit;

/**
 * Api class.
 */
class Api {

	/**
	 * @var string
	 */
	private $client_id;

	/**
	 * @var string
	 */
	private $secret;

	/**
	 * @var string `sandbox` or `live`.
	 */
	private $mode;

	/**
	 * @var string|null
	 */
	private $access_token;

	/**
	 * @param string $client_id Client id.
	 * @param string $secret    Secret.
	 * @param string $mode      `sandbox` or `live`.
	 */
	public function __construct( string $client_id, string $secret, string $mode = 'sandbox' ) {
		$this->client_id = $client_id;
		$this->secret    = $secret;
		$this->mode      = in_array( $mode, array( 'sandbox', 'live' ), true ) ? $mode : 'sandbox';
	}

	/**
	 * @return string
	 */
	public function client_id(): string {
		return $this->client_id;
	}

	/**
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Decimal places PayPal expects for a currency code.
	 *
	 * @param string $currency ISO currency.
	 * @return int
	 */
	public static function currency_decimals( string $currency ): int {
		return \DoubleScale\Core\Constants\Currencies::zero_decimal( $currency ) ? 0 : 2;
	}

	/**
	 * Format a major-unit amount for PayPal API payloads.
	 *
	 * @param float  $amount   Major units.
	 * @param string $currency ISO currency.
	 * @return string
	 */
	public static function format_amount( float $amount, string $currency ): string {
		$decimals = self::currency_decimals( $currency );
		return number_format( max( 0, $amount ), $decimals, '.', '' );
	}

	/**
	 * @return array{success:bool,token?:string,message?:string}
	 */
	public function get_access_token(): array {
		if ( null !== $this->access_token ) {
			return array(
				'success' => true,
				'token'   => $this->access_token,
			);
		}

		$response = wp_remote_post(
			$this->base_url() . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			$message = is_array( $body ) ? ( $body['error_description'] ?? $body['message'] ?? '' ) : '';
			return array(
				'success' => false,
				'message' => $message ?: __( 'PayPal authentication failed.', 'doublescale' ),
			);
		}

		$this->access_token = (string) $body['access_token'];

		return array(
			'success' => true,
			'token'   => $this->access_token,
		);
	}

	/**
	 * @param float  $amount   Major units.
	 * @param string $currency ISO currency.
	 * @param array  $metadata Invoice metadata.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function create_order( float $amount, string $currency, array $metadata ): array {
		$currency   = strtoupper( $currency );
		$invoice_id = (string) ( $metadata['invoice_id'] ?? '' );
		$custom_id  = '' !== $invoice_id ? 'invoice_' . $invoice_id : '';

		$payload = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'amount'      => array(
						'currency_code' => $currency,
						'value'         => self::format_amount( $amount, $currency ),
					),
					'custom_id'   => $custom_id,
					'description' => isset( $metadata['invoice_number'] )
						? sprintf(
							/* translators: %s: invoice number */
							__( 'Invoice %s', 'doublescale' ),
							(string) $metadata['invoice_number']
						)
						: __( 'Invoice payment', 'doublescale' ),
				),
			),
		);

		$return_url = trim( (string) ( $metadata['return_url'] ?? '' ) );
		$cancel_url = trim( (string) ( $metadata['cancel_url'] ?? '' ) );
		if ( '' !== $return_url ) {
			$payload['application_context'] = array(
				'brand_name'          => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'landing_page'        => 'NO_PREFERENCE',
				'user_action'         => 'PAY_NOW',
				'shipping_preference' => 'NO_SHIPPING',
				'return_url'          => $return_url,
				'cancel_url'          => '' !== $cancel_url ? $cancel_url : $return_url,
			);
		}

		return $this->request( 'POST', '/v2/checkout/orders', $payload );
	}

	/**
	 * @param string $order_id PayPal order id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function capture_order( string $order_id ): array {
		return $this->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture' );
	}

	/**
	 * @param string $order_id PayPal order id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_order( string $order_id ): array {
		return $this->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $order_id ) );
	}

	/**
	 * @param array  $headers    PayPal transmission headers.
	 * @param string $body       Raw webhook body.
	 * @param string $webhook_id Saved webhook id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function verify_webhook_signature( array $headers, string $body, string $webhook_id ): array {
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid webhook payload.', 'doublescale' ),
			);
		}

		$payload = array(
			'auth_algo'         => $headers['auth_algo'] ?? '',
			'cert_url'          => $headers['cert_url'] ?? '',
			'transmission_id'   => $headers['transmission_id'] ?? '',
			'transmission_sig'  => $headers['transmission_sig'] ?? '',
			'transmission_time' => $headers['transmission_time'] ?? '',
			'webhook_id'        => $webhook_id,
			'webhook_event'     => $event,
		);

		return $this->request( 'POST', '/v1/notifications/verify-webhook-signature', $payload );
	}

	/**
	 * @param string   $url    Webhook URL.
	 * @param string[] $events Event type names.
	 * @return array{success:bool,id?:string,message?:string}
	 */
	public function create_webhook( string $url, array $events ): array {
		$event_types = array();
		foreach ( $events as $event ) {
			$event_types[] = array( 'name' => $event );
		}

		$result = $this->request(
			'POST',
			'/v1/notifications/webhooks',
			array(
				'url'          => $url,
				'event_types'  => $event_types,
			)
		);

		if ( ! $result['success'] ) {
			return $result;
		}

		return array(
			'success' => true,
			'id'      => (string) ( $result['data']['id'] ?? '' ),
		);
	}

	/**
	 * @param string     $method HTTP method.
	 * @param string     $path   API path.
	 * @param array|null $body   JSON object body; omit for no request body.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$token_result = $this->get_access_token();
		if ( ! $token_result['success'] ) {
			return $token_result;
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token_result['token'],
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return array(
					'success' => false,
					'message' => __( 'Could not encode PayPal request body.', 'doublescale' ),
				);
			}
			$args['body'] = $encoded;
		}

		$response = 'GET' === $method
			? wp_remote_get( $this->base_url() . $path, $args )
			: wp_remote_request( $this->base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$decoded  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded  = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 ) {
			$message = '';
			if ( isset( $decoded['details'][0]['description'] ) ) {
				$message = (string) $decoded['details'][0]['description'];
			} elseif ( isset( $decoded['message'] ) ) {
				$message = (string) $decoded['message'];
			}

			return array(
				'success' => false,
				'message' => $message ?: sprintf(
					/* translators: %d: HTTP status code */
					__( 'PayPal API request failed (HTTP %d).', 'doublescale' ),
					$code
				),
				'data'    => $decoded,
			);
		}

		return array(
			'success' => true,
			'data'    => $decoded,
		);
	}

	/**
	 * @return string
	 */
	private function base_url(): string {
		return 'sandbox' === $this->mode
			? 'https://api-m.sandbox.paypal.com'
			: 'https://api-m.paypal.com';
	}
}
