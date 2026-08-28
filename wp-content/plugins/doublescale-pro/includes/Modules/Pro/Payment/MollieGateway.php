<?php
/**
 * Mollie hosted-checkout payment gateway for invoice context.
 *
 * Creates a Mollie payment for the invoice balance and hands the customer off
 * to Mollie's hosted checkout, where they pick iDEAL, Bancontact, SEPA, card,
 * or any other method enabled on the account.
 *
 * @package DoubleScale\Pro\Modules\Pro\Payment
 */

namespace DoubleScale\Pro\Modules\Pro\Payment;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\Gateway;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Pro\Modules\Integrations\Mollie\Api;
use DoubleScale\Pro\Modules\Integrations\Mollie\Integration as MollieIntegration;
use DoubleScale\Pro\Modules\Integrations\Mollie\Webhook;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * MollieGateway class.
 */
class MollieGateway extends Gateway {

	public $name = 'Mollie';

	public $slug = 'mollie';

	public $description = 'Mollie hosted checkout (iDEAL, Bancontact, SEPA, cards) — credentials in Integrations → Mollie.';

	/**
	 * Query arg appended to the invoice return URL after checkout.
	 */
	public const RETURN_QUERY_ARG = 'ds_mollie_return';

	/**
	 * Mollie payment statuses that mean "settled".
	 */
	private const PAID_STATUSES = array( 'paid', 'authorized' );

	/**
	 * @return void
	 */
	protected function register(): void {
		GatewayManager::instance()->register( GatewayManager::CONTEXT_INVOICE, $this );
	}

	public function is_available(): bool {
		return true;
	}

	public function is_configured(): bool {
		return MollieIntegration::instance()->is_configured();
	}

	/**
	 * Mollie reports amounts as decimal strings in major units.
	 *
	 * @return bool
	 */
	public function uses_major_units(): bool {
		return true;
	}

	/**
	 * @return string
	 */
	public function return_query_arg(): string {
		return self::RETURN_QUERY_ARG;
	}

	/**
	 * @param string $invoice_number Invoice number.
	 * @return string
	 */
	public function payment_note( string $invoice_number ): string {
		return sprintf(
			/* translators: %s: invoice number */
			__( 'Mollie payment for invoice %s', 'doublescale' ),
			$invoice_number
		);
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'Mollie is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $subject instanceof InvoicePayableSubject ) {
			return new WP_Error(
				'invalid_subject',
				__( 'Mollie checkout is only available for invoices.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$amount   = $subject->amount_due();
		$currency = strtoupper( $subject->currency() );

		if ( $amount <= 0 ) {
			return new WP_Error(
				'nothing_due',
				__( 'This invoice has no balance due.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		try {
			$api = MollieIntegration::instance()->connect();
			if ( ! $api instanceof Api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Mollie is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			$resolved = $this->resolve_or_create_payment( $api, $subject, $amount, $currency );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			if ( ! empty( $resolved['already_paid'] ) ) {
				return $this->shape_already_paid_response( $subject, $amount, $currency );
			}

			return array(
				'gateway'      => $this->slug,
				'redirect_url' => (string) $resolved['url'],
				'amount'       => $amount,
				'currency'     => strtolower( $currency ),
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Mollie payment init failed',
				array(
					'code'    => 'mollie_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'mollie_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function confirm( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'Mollie is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		$payment_id = $subject->external_payment_ref();
		if ( null === $payment_id || '' === $payment_id ) {
			return new WP_Error(
				'invalid_data',
				__( 'No Mollie checkout is in progress.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		try {
			$api = MollieIntegration::instance()->connect();
			if ( ! $api instanceof Api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Mollie is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			$result = $api->get_payment( $payment_id );
			if ( ! $result['success'] ) {
				return new WP_Error(
					'mollie_error',
					$result['message'] ?? __( 'Could not retrieve the Mollie payment.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}

			$payment = $result['data'];
			$status  = strtolower( (string) ( $payment['status'] ?? '' ) );

			if ( self::is_paid_status( $status ) ) {
				$this->record_paid( $subject, (object) $payment );
			}

			$response = array(
				'gateway' => $this->slug,
				'status'  => $status,
			);

			if ( $subject instanceof InvoicePayableSubject ) {
				$invoice = $subject->get_invoice();
				$invoice->refresh();
				$response['invoice'] = InvoiceShaper::shape( $invoice, true );
			}

			return $response;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'mollie_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  Mollie payment object.
	 * @return void
	 */
	public function record_paid( PayableSubject $subject, object $charge ): void {
		$normalized = $this->normalize_payment( $charge );
		if ( ! $normalized ) {
			return;
		}
		$subject->record_payment( $normalized );
	}

	/**
	 * @param string $status Mollie payment status.
	 * @return bool
	 */
	public static function is_paid_status( string $status ): bool {
		return in_array( strtolower( $status ), self::PAID_STATUSES, true );
	}

	/**
	 * Reuse an open payment for this invoice when the amount still matches,
	 * otherwise create a fresh one.
	 *
	 * @param Api                   $api      API client.
	 * @param InvoicePayableSubject $subject  Subject.
	 * @param float                 $amount   Major units.
	 * @param string                $currency ISO currency (uppercase).
	 * @return array{url?:string,already_paid?:bool}|WP_Error
	 */
	private function resolve_or_create_payment( Api $api, InvoicePayableSubject $subject, float $amount, string $currency ) {
		$ref = $subject->external_payment_ref();
		if ( null !== $ref && '' !== $ref ) {
			$existing = $api->get_payment( $ref );
			if ( $existing['success'] ) {
				$payment = $existing['data'];
				$status  = strtolower( (string) ( $payment['status'] ?? '' ) );

				if ( self::is_paid_status( $status ) ) {
					$mismatch = PaymentCurrency::guard( $currency, $payment['amount']['currency'] ?? '', 'Mollie' );
					if ( is_wp_error( $mismatch ) ) {
						return $mismatch;
					}
					$this->record_paid( $subject, (object) $payment );
					return array( 'already_paid' => true );
				}

				$mismatch = PaymentCurrency::guard( $currency, $payment['amount']['currency'] ?? '', 'Mollie' );
				if ( is_wp_error( $mismatch ) ) {
					return $mismatch;
				}

				$expected = Api::format_amount( $amount, $currency );
				$actual   = (string) ( $payment['amount']['value'] ?? '' );
				$checkout = (string) ( $payment['_links']['checkout']['href'] ?? '' );

				// Only an `open` payment still has a usable checkout link.
				if ( 'open' === $status && $actual === $expected && '' !== $checkout ) {
					return array( 'url' => $checkout );
				}
			}
		}

		$created = $api->create_payment(
			$amount,
			$currency,
			$this->payment_metadata( $subject ),
			$this->invoice_return_url( $subject ),
			$this->webhook_url()
		);

		if ( ! $created['success'] ) {
			return new WP_Error(
				'mollie_error',
				$created['message'] ?? __( 'Could not create the Mollie payment.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$payment    = $created['data'];
		$payment_id = (string) ( $payment['id'] ?? '' );
		$checkout   = (string) ( $payment['_links']['checkout']['href'] ?? '' );

		if ( '' === $payment_id || '' === $checkout ) {
			return new WP_Error(
				'mollie_error',
				__( 'Mollie did not return a checkout link.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		// Non-Stripe refs must not populate stripe_payment_intent_id.
		$subject->get_invoice()->set_in_progress_payment_ref( $payment_id, false );

		return array( 'url' => $checkout );
	}

	/**
	 * @param InvoicePayableSubject $subject Subject.
	 * @return array<string, mixed>
	 */
	private function payment_metadata( InvoicePayableSubject $subject ): array {
		$metadata = $subject->metadata();

		$email = $subject->customer_email();
		if ( $email ) {
			$metadata['customer_email'] = $email;
		}

		return $metadata;
	}

	/**
	 * @param InvoicePayableSubject $subject Subject.
	 * @return string
	 */
	private function invoice_return_url( InvoicePayableSubject $subject ): string {
		$base = InvoiceUrl::get_public_url( $subject->get_invoice() );
		if ( '' === $base ) {
			return home_url( '/' );
		}
		return add_query_arg( self::RETURN_QUERY_ARG, '1', $base );
	}

	/**
	 * Mollie rejects unreachable webhook URLs outright, so omit it on local
	 * sites and rely on the return-from-checkout confirm instead.
	 *
	 * @return string
	 */
	private function webhook_url(): string {
		$url  = Webhook::notification_url();
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = strtolower( $host );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| str_ends_with( $host, '.local' ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * @param object $payment Mollie payment or normalized object.
	 * @return object|null
	 */
	private function normalize_payment( object $payment ) {
		if ( isset( $payment->payment_mode ) && PaymentModeSlugs::mollie() === (string) $payment->payment_mode ) {
			$txn = (string) ( $payment->transaction_id ?? '' );
			return '' !== $txn ? $payment : null;
		}

		$payment_id = (string) ( $payment->id ?? '' );
		if ( '' === $payment_id ) {
			return null;
		}

		$amount_data = $payment->amount ?? null;
		if ( is_object( $amount_data ) ) {
			$amount   = (float) ( $amount_data->value ?? 0 );
			$currency = strtolower( (string) ( $amount_data->currency ?? '' ) );
		} elseif ( is_array( $amount_data ) ) {
			$amount   = (float) ( $amount_data['value'] ?? 0 );
			$currency = strtolower( (string) ( $amount_data['currency'] ?? '' ) );
		} else {
			return null;
		}

		return (object) array(
			'payment_mode'   => PaymentModeSlugs::mollie(),
			'transaction_id' => $payment_id,
			'id'             => $payment_id,
			// Major units — uses_major_units() is true for this gateway.
			'amount'         => $amount,
			'currency'       => $currency,
		);
	}

	/**
	 * @param PayableSubject $subject  Subject.
	 * @param float          $amount   Major units.
	 * @param string         $currency ISO currency.
	 * @return array<string, mixed>
	 */
	private function shape_already_paid_response( PayableSubject $subject, float $amount, string $currency ): array {
		$response = array(
			'gateway'      => $this->slug,
			'already_paid' => true,
			'amount'       => $amount,
			'currency'     => strtolower( $currency ),
		);

		if ( $subject instanceof InvoicePayableSubject ) {
			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );
		}

		return $response;
	}
}
