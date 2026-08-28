<?php
/**
 * Mollie Payments v2 REST API client (wp_remote_* — no SDK).
 *
 * @package DoubleScale\Pro\Modules\Integrations\Mollie
 */

namespace DoubleScale\Pro\Modules\Integrations\Mollie;

defined( 'ABSPATH' ) || exit;

/**
 * Api class.
 */
class Api {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @param string $api_key Mollie API key (`test_…` or `live_…`).
	 */
	public function __construct( string $api_key ) {
		$this->api_key = trim( $api_key );
	}

	/**
	 * Mollie encodes the mode in the key prefix, so no separate mode setting.
	 *
	 * @param string $api_key API key.
	 * @return string `test`, `live`, or '' when unrecognised.
	 */
	public static function mode_from_key( string $api_key ): string {
		$api_key = trim( $api_key );
		if ( 0 === strpos( $api_key, 'test_' ) ) {
			return 'test';
		}
		if ( 0 === strpos( $api_key, 'live_' ) ) {
			return 'live';
		}
		return '';
	}

	/**
	 * @return string
	 */
	public function mode(): string {
		return self::mode_from_key( $this->api_key );
	}

	/**
	 * Currencies Mollie treats as zero-decimal.
	 *
	 * @param string $currency ISO currency.
	 * @return int
	 */
	public static function currency_decimals( string $currency ): int {
		return \DoubleScale\Core\Constants\Currencies::zero_decimal( $currency ) ? 0 : 2;
	}

	/**
	 * Mollie takes amounts as decimal *strings* in major units.
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
	 * Verify credentials — the methods endpoint is the cheapest authenticated call.
	 *
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function list_methods(): array {
		return $this->request( 'GET', '/v2/methods' );
	}

	/**
	 * @param float  $amount      Major units.
	 * @param string $currency    ISO currency.
	 * @param array  $metadata    Invoice metadata.
	 * @param string $redirect_url Where Mollie returns the customer.
	 * @param string $webhook_url  Where Mollie notifies us (may be '' locally).
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function create_payment(
		float $amount,
		string $currency,
		array $metadata,
		string $redirect_url,
		string $webhook_url = ''
	): array {
		$currency = strtoupper( $currency );

		$payload = array(
			'amount'      => array(
				'currency' => $currency,
				'value'    => self::format_amount( $amount, $currency ),
			),
			'description' => isset( $metadata['invoice_number'] )
				? sprintf(
					/* translators: %s: invoice number */
					__( 'Invoice %s', 'doublescale' ),
					(string) $metadata['invoice_number']
				)
				: __( 'Invoice payment', 'doublescale' ),
			'redirectUrl' => $redirect_url,
			// Round-trips so the webhook can resolve the invoice without a lookup.
			'metadata'    => array(
				'invoice_id'     => (string) ( $metadata['invoice_id'] ?? '' ),
				'invoice_number' => (string) ( $metadata['invoice_number'] ?? '' ),
			),
		);

		// Mollie rejects localhost webhooks, so omit rather than send a bad one.
		if ( '' !== $webhook_url ) {
			$payload['webhookUrl'] = $webhook_url;
		}

		$email = trim( (string) ( $metadata['customer_email'] ?? '' ) );
		if ( '' !== $email ) {
			$payload['billingEmail'] = $email;
		}

		return $this->request( 'POST', '/v2/payments', $payload );
	}

	/**
	 * @param string $payment_id Mollie payment id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_payment( string $payment_id ): array {
		return $this->request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * @param string     $method HTTP method.
	 * @param string     $path   API path.
	 * @param array|null $body   JSON object body; omit for no request body.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		if ( '' === $this->api_key ) {
			return array(
				'success' => false,
				'message' => __( 'Mollie API key is missing.', 'doublescale' ),
			);
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return array(
					'success' => false,
					'message' => __( 'Could not encode Mollie request body.', 'doublescale' ),
				);
			}
			$args['body'] = $encoded;
		}

		$url = 'https://api.mollie.com' . $path;

		$response = 'GET' === $method
			? wp_remote_get( $url, $args )
			: wp_remote_request( $url, $args );

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
	 * Mollie reports failures as `detail`, sometimes with field errors nested
	 * under `_embedded.errors`.
	 *
	 * @param array $decoded Decoded response body.
	 * @param int   $code    HTTP status code.
	 * @return string
	 */
	private static function error_message( array $decoded, int $code ): string {
		$field_error = $decoded['_embedded']['errors'][0]['detail'] ?? '';
		if ( is_string( $field_error ) && '' !== $field_error ) {
			return $field_error;
		}

		$detail = $decoded['detail'] ?? '';
		if ( is_string( $detail ) && '' !== $detail ) {
			return $detail;
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Mollie API request failed (HTTP %d).', 'doublescale' ),
			$code
		);
	}
}
