<?php
/**
 * Square Payment Links / Orders REST API client (wp_remote_* — no SDK).
 *
 * @package DoubleScale\Pro\Modules\Integrations\Square
 */

namespace DoubleScale\Pro\Modules\Integrations\Square;

defined( 'ABSPATH' ) || exit;

/**
 * Api class.
 */
class Api {

	/**
	 * Square API version this client is written against.
	 */
	public const API_VERSION = '2024-10-17';

	/**
	 * @var string
	 */
	private $access_token;

	/**
	 * @var string `sandbox` or `production`.
	 */
	private $mode;

	/**
	 * @var string
	 */
	private $location_id;

	/**
	 * @param string $access_token Access token.
	 * @param string $mode         `sandbox` or `production`.
	 * @param string $location_id  Square location id.
	 */
	public function __construct( string $access_token, string $mode = 'sandbox', string $location_id = '' ) {
		$this->access_token = $access_token;
		$this->mode         = in_array( $mode, array( 'sandbox', 'production' ), true ) ? $mode : 'sandbox';
		$this->location_id  = $location_id;
	}

	/**
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * @return string
	 */
	public function location_id(): string {
		return $this->location_id;
	}

	/**
	 * Currencies Square treats as zero-decimal.
	 *
	 * @param string $currency ISO currency.
	 * @return int
	 */
	public static function currency_decimals( string $currency ): int {
		return \DoubleScale\Core\Constants\Currencies::zero_decimal( $currency ) ? 0 : 2;
	}

	/**
	 * Convert a major-unit amount to the integer minor units Square expects.
	 *
	 * @param float  $amount   Major units.
	 * @param string $currency ISO currency.
	 * @return int
	 */
	public static function to_minor_units( float $amount, string $currency ): int {
		$decimals = self::currency_decimals( $currency );
		return (int) round( max( 0, $amount ) * pow( 10, $decimals ) );
	}

	/**
	 * Convert Square minor units back to major units.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency ISO currency.
	 * @return float
	 */
	public static function from_minor_units( int $amount, string $currency ): float {
		$decimals = self::currency_decimals( $currency );
		return round( $amount / pow( 10, $decimals ), $decimals );
	}

	/**
	 * Verify credentials by listing locations.
	 *
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function list_locations(): array {
		return $this->request( 'GET', '/v2/locations' );
	}

	/**
	 * Create a hosted checkout link for an invoice balance.
	 *
	 * @param float  $amount     Major units.
	 * @param string $currency   ISO currency.
	 * @param array  $metadata   Invoice metadata (invoice_id, invoice_number, return_url).
	 * @param string $idempotency Idempotency key.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function create_payment_link( float $amount, string $currency, array $metadata, string $idempotency ): array {
		$currency = strtoupper( $currency );
		$name     = isset( $metadata['invoice_number'] )
			? sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice %s', 'doublescale' ),
				(string) $metadata['invoice_number']
			)
			: __( 'Invoice payment', 'doublescale' );

		$payload = array(
			'idempotency_key' => $idempotency,
			'quick_pay'       => array(
				'name'        => $name,
				'price_money' => array(
					'amount'   => self::to_minor_units( $amount, $currency ),
					'currency' => $currency,
				),
				'location_id' => $this->location_id,
			),
		);

		$invoice_id = (string) ( $metadata['invoice_id'] ?? '' );
		if ( '' !== $invoice_id ) {
			// Round-trips back on the order so webhooks can resolve the invoice.
			$payload['payment_note'] = 'invoice_' . $invoice_id;
		}

		$return_url = trim( (string) ( $metadata['return_url'] ?? '' ) );
		if ( '' !== $return_url ) {
			$payload['checkout_options'] = array(
				'redirect_url'          => $return_url,
				'ask_for_shipping_address' => false,
			);
		}

		$email = trim( (string) ( $metadata['customer_email'] ?? '' ) );
		if ( '' !== $email ) {
			$payload['pre_populated_data'] = array( 'buyer_email' => $email );
		}

		return $this->request( 'POST', '/v2/online-checkout/payment-links', $payload );
	}

	/**
	 * @param string $link_id Payment link id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_payment_link( string $link_id ): array {
		return $this->request( 'GET', '/v2/online-checkout/payment-links/' . rawurlencode( $link_id ) );
	}

	/**
	 * @param string $order_id Square order id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_order( string $order_id ): array {
		return $this->request( 'GET', '/v2/orders/' . rawurlencode( $order_id ) );
	}

	/**
	 * @param string $payment_id Square payment id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_payment( string $payment_id ): array {
		return $this->request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * @param string   $url    Notification URL.
	 * @param string[] $events Event type names.
	 * @return array{success:bool,id?:string,signature_key?:string,message?:string}
	 */
	public function create_webhook_subscription( string $url, array $events ): array {
		$result = $this->request(
			'POST',
			'/v2/webhooks/subscriptions',
			array(
				'idempotency_key' => wp_generate_uuid4(),
				'subscription'    => array(
					'name'          => 'DoubleScale invoice payments',
					'event_types'   => array_values( $events ),
					'notification_url' => $url,
					'api_version'   => self::API_VERSION,
				),
			)
		);

		if ( ! $result['success'] ) {
			return $result;
		}

		return array(
			'success'       => true,
			'id'            => (string) ( $result['data']['subscription']['id'] ?? '' ),
			'signature_key' => (string) ( $result['data']['subscription']['signature_key'] ?? '' ),
		);
	}

	/**
	 * @param string     $method HTTP method.
	 * @param string     $path   API path.
	 * @param array|null $body   JSON object body; omit for no request body.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $this->access_token,
				'Content-Type'   => 'application/json',
				'Square-Version' => self::API_VERSION,
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return array(
					'success' => false,
					'message' => __( 'Could not encode Square request body.', 'doublescale' ),
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

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'message' => self::error_message( $decoded, $code ),
				'data'    => $decoded,
			);
		}

		return array(
			'success' => true,
			'data'    => $decoded,
		);
	}

	/**
	 * Square reports failures as an `errors` array of {category, code, detail}.
	 *
	 * @param array $decoded Decoded response body.
	 * @param int   $code    HTTP status code.
	 * @return string
	 */
	private static function error_message( array $decoded, int $code ): string {
		$errors = $decoded['errors'] ?? array();
		if ( is_array( $errors ) && ! empty( $errors[0] ) && is_array( $errors[0] ) ) {
			$detail = (string) ( $errors[0]['detail'] ?? $errors[0]['code'] ?? '' );
			if ( '' !== $detail ) {
				return $detail;
			}
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Square API request failed (HTTP %d).', 'doublescale' ),
			$code
		);
	}

	/**
	 * @return string
	 */
	private function base_url(): string {
		return 'sandbox' === $this->mode
			? 'https://connect.squareupsandbox.com'
			: 'https://connect.squareup.com';
	}
}
