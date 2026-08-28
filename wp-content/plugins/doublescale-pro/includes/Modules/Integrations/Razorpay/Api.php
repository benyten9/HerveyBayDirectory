<?php
/**
 * Razorpay Payment Links REST API client (wp_remote_* — no SDK).
 *
 * @package DoubleScale\Pro\Modules\Integrations\Razorpay
 */

namespace DoubleScale\Pro\Modules\Integrations\Razorpay;

defined( 'ABSPATH' ) || exit;

/**
 * Api class.
 */
class Api {

	/**
	 * @var string
	 */
	private $key_id;

	/**
	 * @var string
	 */
	private $key_secret;

	/**
	 * @param string $key_id     Key id (`rzp_test_…` / `rzp_live_…`).
	 * @param string $key_secret Key secret.
	 */
	public function __construct( string $key_id, string $key_secret ) {
		$this->key_id     = trim( $key_id );
		$this->key_secret = trim( $key_secret );
	}

	/**
	 * Razorpay encodes the mode in the key id prefix.
	 *
	 * @param string $key_id Key id.
	 * @return string `test`, `live`, or '' when unrecognised.
	 */
	public static function mode_from_key( string $key_id ): string {
		$key_id = trim( $key_id );
		if ( 0 === strpos( $key_id, 'rzp_test_' ) ) {
			return 'test';
		}
		if ( 0 === strpos( $key_id, 'rzp_live_' ) ) {
			return 'live';
		}
		return '';
	}

	/**
	 * @return string
	 */
	public function key_id(): string {
		return $this->key_id;
	}

	/**
	 * @return string
	 */
	public function mode(): string {
		return self::mode_from_key( $this->key_id );
	}

	/**
	 * Currencies Razorpay treats as zero-decimal.
	 *
	 * @param string $currency ISO currency.
	 * @return int
	 */
	public static function currency_decimals( string $currency ): int {
		return \DoubleScale\Core\Constants\Currencies::zero_decimal( $currency ) ? 0 : 2;
	}

	/**
	 * Convert a major-unit amount to the integer minor units (paise) Razorpay
	 * expects.
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
	 * @param int    $amount   Minor units.
	 * @param string $currency ISO currency.
	 * @return float
	 */
	public static function from_minor_units( int $amount, string $currency ): float {
		$decimals = self::currency_decimals( $currency );
		return round( $amount / pow( 10, $decimals ), $decimals );
	}

	/**
	 * Verify credentials. Razorpay has no dedicated ping endpoint, so list a
	 * single payment link — cheap and authenticated.
	 *
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function verify_credentials(): array {
		return $this->request( 'GET', '/v1/payment_links?count=1' );
	}

	/**
	 * Create a hosted payment link for an invoice balance.
	 *
	 * @param float  $amount      Major units.
	 * @param string $currency    ISO currency.
	 * @param array  $metadata    Invoice metadata.
	 * @param string $callback_url Where Razorpay returns the customer.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function create_payment_link( float $amount, string $currency, array $metadata, string $callback_url ): array {
		$currency   = strtoupper( $currency );
		$invoice_id = (string) ( $metadata['invoice_id'] ?? '' );

		$payload = array(
			'amount'                 => self::to_minor_units( $amount, $currency ),
			'currency'               => $currency,
			'accept_partial'         => false,
			'description'            => isset( $metadata['invoice_number'] )
				? sprintf(
					/* translators: %s: invoice number */
					__( 'Invoice %s', 'doublescale' ),
					(string) $metadata['invoice_number']
				)
				: __( 'Invoice payment', 'doublescale' ),
			// Round-trips so the webhook can resolve the invoice without a lookup.
			'notes'                  => array(
				'invoice_id'     => $invoice_id,
				'invoice_number' => (string) ( $metadata['invoice_number'] ?? '' ),
			),
			// Razorpay only honours callback_url when this is set.
			'callback_url'           => $callback_url,
			'callback_method'        => 'get',
			'reminder_enable'        => false,
			'notify'                 => array(
				'sms'   => false,
				'email' => false,
			),
		);

		$customer = array();
		$email    = trim( (string) ( $metadata['customer_email'] ?? '' ) );
		$name     = trim( (string) ( $metadata['customer_name'] ?? '' ) );
		if ( '' !== $email ) {
			$customer['email'] = $email;
		}
		if ( '' !== $name ) {
			$customer['name'] = $name;
		}
		if ( ! empty( $customer ) ) {
			$payload['customer'] = $customer;
		}

		return $this->request( 'POST', '/v1/payment_links', $payload );
	}

	/**
	 * @param string $link_id Payment link id (`plink_…`).
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_payment_link( string $link_id ): array {
		return $this->request( 'GET', '/v1/payment_links/' . rawurlencode( $link_id ) );
	}

	/**
	 * @param string $payment_id Payment id (`pay_…`).
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_payment( string $payment_id ): array {
		return $this->request( 'GET', '/v1/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * @param string     $method HTTP method.
	 * @param string     $path   API path.
	 * @param array|null $body   JSON object body; omit for no request body.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		if ( '' === $this->key_id || '' === $this->key_secret ) {
			return array(
				'success' => false,
				'message' => __( 'Razorpay API credentials are missing.', 'doublescale' ),
			);
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->key_id . ':' . $this->key_secret ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return array(
					'success' => false,
					'message' => __( 'Could not encode Razorpay request body.', 'doublescale' ),
				);
			}
			$args['body'] = $encoded;
		}

		$url = 'https://api.razorpay.com' . $path;

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
	 * Razorpay reports failures as `error.description`.
	 *
	 * @param array $decoded Decoded response body.
	 * @param int   $code    HTTP status code.
	 * @return string
	 */
	private static function error_message( array $decoded, int $code ): string {
		$description = $decoded['error']['description'] ?? '';
		if ( is_string( $description ) && '' !== $description ) {
			return $description;
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Razorpay API request failed (HTTP %d).', 'doublescale' ),
			$code
		);
	}
}
