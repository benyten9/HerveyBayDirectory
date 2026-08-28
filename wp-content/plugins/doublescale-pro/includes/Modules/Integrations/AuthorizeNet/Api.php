<?php
/**
 * Authorize.net Accept Hosted REST API client (wp_remote_* — no SDK).
 *
 * @package DoubleScale\Pro\Modules\Integrations\AuthorizeNet
 */

namespace DoubleScale\Pro\Modules\Integrations\AuthorizeNet;

defined( 'ABSPATH' ) || exit;

/**
 * Api class.
 */
class Api {

	/**
	 * @var string
	 */
	private $login_id;

	/**
	 * @var string
	 */
	private $transaction_key;

	/**
	 * @var string `sandbox` or `production`.
	 */
	private $mode;

	/**
	 * @param string $login_id        API login id.
	 * @param string $transaction_key Transaction key.
	 * @param string $mode            `sandbox` or `production`.
	 */
	public function __construct( string $login_id, string $transaction_key, string $mode = 'sandbox' ) {
		$this->login_id        = trim( $login_id );
		$this->transaction_key = trim( $transaction_key );
		$this->mode            = in_array( $mode, array( 'sandbox', 'production' ), true ) ? $mode : 'sandbox';
	}

	/**
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Where the hosted form token is POSTed to.
	 *
	 * @return string
	 */
	public function hosted_form_url(): string {
		return 'sandbox' === $this->mode
			? 'https://test.authorize.net/payment/payment'
			: 'https://accept.authorize.net/payment/payment';
	}

	/**
	 * Authorize.net settles in the merchant account's own currency and takes
	 * amounts as decimal strings.
	 *
	 * @param float $amount Major units.
	 * @return string
	 */
	public static function format_amount( float $amount ): string {
		return number_format( max( 0, $amount ), 2, '.', '' );
	}

	/**
	 * Verify credentials. `authenticateTestRequest` is the purpose-built ping.
	 *
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function authenticate(): array {
		return $this->request(
			array(
				'authenticateTestRequest' => array(
					'merchantAuthentication' => $this->merchant_auth(),
				),
			)
		);
	}

	/**
	 * Request a hosted payment page token for an invoice balance.
	 *
	 * @param float  $amount     Major units.
	 * @param array  $metadata   Invoice metadata.
	 * @param string $return_url Where the customer is sent after paying.
	 * @param string $cancel_url Where the customer is sent on cancel.
	 * @return array{success:bool,token?:string,message?:string}
	 */
	public function get_hosted_payment_page( float $amount, array $metadata, string $return_url, string $cancel_url ): array {
		$invoice_number = (string) ( $metadata['invoice_number'] ?? '' );
		$invoice_id     = (string) ( $metadata['invoice_id'] ?? '' );

		$order = array();
		if ( '' !== $invoice_number ) {
			// Authorize.net caps invoiceNumber at 20 chars and rejects longer.
			$order['invoiceNumber'] = substr( $invoice_number, 0, 20 );
		}
		if ( '' !== $invoice_id ) {
			$order['description'] = 'invoice_' . $invoice_id;
		}

		$transaction_request = array(
			'transactionType' => 'authCaptureTransaction',
			'amount'          => self::format_amount( $amount ),
		);

		if ( ! empty( $order ) ) {
			$transaction_request['order'] = $order;
		}

		$email = trim( (string) ( $metadata['customer_email'] ?? '' ) );
		if ( '' !== $email ) {
			$transaction_request['customer'] = array( 'email' => $email );
		}

		$payload = array(
			'getHostedPaymentPageRequest' => array(
				'merchantAuthentication' => $this->merchant_auth(),
				'transactionRequest'     => $transaction_request,
				// Every setting value is a JSON *string*, even in a JSON request.
				'hostedPaymentSettings'  => array(
					'setting' => $this->hosted_settings( $return_url, $cancel_url ),
				),
			),
		);

		$result = $this->request( $payload );
		if ( ! $result['success'] ) {
			return $result;
		}

		$token = (string) ( $result['data']['token'] ?? '' );
		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'Authorize.Net did not return a hosted payment token.', 'doublescale' ),
			);
		}

		return array(
			'success' => true,
			'token'   => $token,
		);
	}

	/**
	 * Fetch a transaction — the authority on whether payment succeeded.
	 *
	 * @param string $transaction_id Transaction id.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_transaction_details( string $transaction_id ): array {
		return $this->request(
			array(
				'getTransactionDetailsRequest' => array(
					'merchantAuthentication' => $this->merchant_auth(),
					'transId'                => $transaction_id,
				),
			)
		);
	}

	/**
	 * Recent unsettled transactions (just-captured charges live here until batch settlement).
	 *
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_unsettled_transaction_list(): array {
		return $this->request(
			array(
				'getUnsettledTransactionListRequest' => array(
					'merchantAuthentication' => $this->merchant_auth(),
					'sorting'                => array(
						'orderBy'         => 'submitTimeUTC',
						'orderDescending' => true,
					),
					'paging'                 => array(
						'limit'  => '1000',
						'offset' => '1',
					),
				),
			)
		);
	}

	/**
	 * Find unsettled transactions whose invoiceNumber matches.
	 *
	 * Accept Hosted does not hand back a transaction id on return, so the
	 * invoice number is the only link back to the charge. There is no
	 * invoice-number search API — `getTransactionListForCustomerRequest`
	 * requires a customer profile id, so we page the unsettled list and filter.
	 *
	 * @param string $invoice_number Invoice number.
	 * @return array{success:bool,data?:array,message?:string}
	 */
	public function get_transaction_list_for_invoice( string $invoice_number ): array {
		$list = $this->get_unsettled_transaction_list();
		if ( ! $list['success'] ) {
			return $list;
		}

		$needle       = substr( $invoice_number, 0, 20 );
		$transactions = $list['data']['transactions'] ?? array();
		if ( isset( $transactions['transId'] ) ) {
			$transactions = array( $transactions );
		}
		if ( ! is_array( $transactions ) ) {
			$transactions = array();
		}

		$matched = array();
		foreach ( $transactions as $summary ) {
			if ( ! is_array( $summary ) ) {
				continue;
			}
			$number = (string) ( $summary['invoiceNumber'] ?? '' );
			if ( '' !== $needle && 0 === strcasecmp( $number, $needle ) ) {
				$matched[] = $summary;
			}
		}

		$list['data']['transactions'] = $matched;
		return $list;
	}

	/**
	 * @return array{name:string,transactionKey:string}
	 */
	private function merchant_auth(): array {
		return array(
			'name'           => $this->login_id,
			'transactionKey' => $this->transaction_key,
		);
	}

	/**
	 * Hosted form settings. Values are JSON-encoded strings by API contract.
	 *
	 * @param string $return_url Return URL.
	 * @param string $cancel_url Cancel URL.
	 * @return array<int, array{settingName:string,settingValue:string}>
	 */
	private function hosted_settings( string $return_url, string $cancel_url ): array {
		// Ampersands in url/cancelUrl make Accept Hosted render only "Order Summary".
		if ( false !== strpos( $return_url, '&' ) || false !== strpos( $cancel_url, '&' ) ) {
			doublescale_get_logger()->warning(
				'Authorize.Net hosted return URL contains an ampersand',
				array(
					'code' => 'authorize_net_return_url_ampersand',
				)
			);
		}

		$settings = array(
			'hostedPaymentReturnOptions' => array(
				'showReceipt' => false,
				'url'         => $return_url,
				'urlText'     => __( 'Return to invoice', 'doublescale' ),
				'cancelUrl'   => '' !== $cancel_url ? $cancel_url : $return_url,
				'cancelUrlText' => __( 'Cancel', 'doublescale' ),
			),
			'hostedPaymentButtonOptions' => array(
				'text' => __( 'Pay', 'doublescale' ),
			),
			'hostedPaymentOrderOptions'  => array(
				// The merchant name/description block is redundant here.
				'show' => false,
			),
			'hostedPaymentPaymentOptions' => array(
				'cardCodeRequired' => true,
				'showCreditCard'   => true,
				'showBankAccount'  => false,
			),
			'hostedPaymentBillingAddressOptions' => array(
				'show'     => true,
				'required' => false,
			),
			// Redirect back automatically instead of showing Authorize.net's
			// own receipt page.
			'hostedPaymentIFrameCommunicatorUrl' => array(
				'url' => '',
			),
		);

		$out = array();
		foreach ( $settings as $name => $value ) {
			if ( 'hostedPaymentIFrameCommunicatorUrl' === $name && '' === $value['url'] ) {
				continue;
			}
			$encoded = wp_json_encode( $value );
			if ( false === $encoded ) {
				continue;
			}
			$out[] = array(
				'settingName'  => $name,
				'settingValue' => $encoded,
			);
		}

		return $out;
	}

	/**
	 * @param array $payload Request body (single-key request envelope).
	 * @return array{success:bool,data?:array,message?:string}
	 */
	private function request( array $payload ): array {
		if ( '' === $this->login_id || '' === $this->transaction_key ) {
			return array(
				'success' => false,
				'message' => __( 'Authorize.Net API credentials are missing.', 'doublescale' ),
			);
		}

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded ) {
			return array(
				'success' => false,
				'message' => __( 'Could not encode the Authorize.Net request body.', 'doublescale' ),
			);
		}

		$response = wp_remote_post(
			$this->base_url(),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $encoded,
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
		$body = (string) wp_remote_retrieve_body( $response );

		$decoded = json_decode( self::strip_bom( $body ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Authorize.Net API request failed (HTTP %d).', 'doublescale' ),
					$code
				),
				'data'    => $decoded,
			);
		}

		if ( empty( $decoded ) ) {
			return array(
				'success' => false,
				'message' => __( 'Authorize.Net returned an unreadable response.', 'doublescale' ),
			);
		}

		// Transport succeeded; the API reports its own outcome in messages.
		$result_code = strtolower( (string) ( $decoded['messages']['resultCode'] ?? '' ) );
		if ( 'ok' !== $result_code ) {
			return array(
				'success' => false,
				'message' => self::error_message( $decoded ),
				'data'    => $decoded,
			);
		}

		return array(
			'success' => true,
			'data'    => $decoded,
		);
	}

	/**
	 * Authorize.Net serves JSON with a UTF-8 BOM, which json_decode() rejects.
	 *
	 * @param string $body Raw response body.
	 * @return string
	 */
	public static function strip_bom( string $body ): string {
		$bom = pack( 'CCC', 0xEF, 0xBB, 0xBF );
		if ( 0 === strncmp( $body, $bom, 3 ) ) {
			return substr( $body, 3 );
		}
		return ltrim( $body, "\xEF\xBB\xBF" );
	}

	/**
	 * Errors surface either in messages.message[] or transactionResponse.errors[].
	 *
	 * @param array $decoded Decoded response body.
	 * @return string
	 */
	private static function error_message( array $decoded ): string {
		$messages = $decoded['messages']['message'] ?? array();
		if ( is_array( $messages ) && ! empty( $messages[0] ) && is_array( $messages[0] ) ) {
			$text = (string) ( $messages[0]['text'] ?? '' );
			if ( '' !== $text ) {
				return $text;
			}
		}

		$errors = $decoded['transactionResponse']['errors'] ?? array();
		if ( is_array( $errors ) && ! empty( $errors[0] ) && is_array( $errors[0] ) ) {
			$text = (string) ( $errors[0]['errorText'] ?? '' );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return __( 'Authorize.Net rejected the request.', 'doublescale' );
	}

	/**
	 * @return string
	 */
	private function base_url(): string {
		return 'sandbox' === $this->mode
			? 'https://apitest.authorize.net/xml/v1/request.api'
			: 'https://api.authorize.net/xml/v1/request.api';
	}
}
