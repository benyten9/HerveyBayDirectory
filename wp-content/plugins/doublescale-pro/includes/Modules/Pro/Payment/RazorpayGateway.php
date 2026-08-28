<?php
/**
 * Razorpay hosted-checkout payment gateway for invoice context.
 *
 * Creates a Razorpay Payment Link for the invoice balance and hands the
 * customer off to Razorpay's hosted page, where they pick UPI, card,
 * netbanking or a wallet.
 *
 * Payment Links are used rather than the checkout.js modal so the flow rides
 * the shared redirect path and needs no bespoke frontend code.
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
use DoubleScale\Pro\Modules\Integrations\Razorpay\Api;
use DoubleScale\Pro\Modules\Integrations\Razorpay\Integration as RazorpayIntegration;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * RazorpayGateway class.
 */
class RazorpayGateway extends Gateway {

	public $name = 'Razorpay';

	public $slug = 'razorpay';

	public $description = 'Razorpay hosted checkout (UPI, cards, netbanking, wallets) — credentials in Integrations → Razorpay.';

	/**
	 * Query arg appended to the invoice return URL after checkout.
	 */
	public const RETURN_QUERY_ARG = 'ds_razorpay_return';

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
		return RazorpayIntegration::instance()->is_configured();
	}

	/**
	 * Razorpay reports money in minor units (paise).
	 *
	 * @return bool
	 */
	public function uses_major_units(): bool {
		return false;
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
			__( 'Razorpay payment for invoice %s', 'doublescale' ),
			$invoice_number
		);
	}

	/**
	 * A payment link is settled once it reports `paid`.
	 *
	 * @param string $status Payment link status.
	 * @return bool
	 */
	public static function is_paid_status( string $status ): bool {
		return 'paid' === strtolower( $status );
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'Razorpay is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $subject instanceof InvoicePayableSubject ) {
			return new WP_Error(
				'invalid_subject',
				__( 'Razorpay checkout is only available for invoices.', 'doublescale' ),
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
			$api = RazorpayIntegration::instance()->connect();
			if ( ! $api instanceof Api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Razorpay is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			$resolved = $this->resolve_or_create_link( $api, $subject, $amount, $currency );
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
				'Razorpay payment init failed',
				array(
					'code'    => 'razorpay_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'razorpay_error', $e->getMessage(), array( 'status' => 500 ) );
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
				__( 'Razorpay is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		$link_id = $subject->external_payment_ref();
		if ( null === $link_id || '' === $link_id ) {
			return new WP_Error(
				'invalid_data',
				__( 'No Razorpay checkout is in progress.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		try {
			$api = RazorpayIntegration::instance()->connect();
			if ( ! $api instanceof Api ) {
				return new WP_Error(
					'gateway_not_configured',
					__( 'Razorpay is not configured.', 'doublescale' ),
					array( 'status' => 503 )
				);
			}

			$result = $api->get_payment_link( $link_id );
			if ( ! $result['success'] ) {
				return new WP_Error(
					'razorpay_error',
					$result['message'] ?? __( 'Could not retrieve the Razorpay payment link.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}

			$link   = $result['data'];
			$status = strtolower( (string) ( $link['status'] ?? '' ) );

			if ( self::is_paid_status( $status ) ) {
				$charge = $this->charge_from_link( $api, $link );
				if ( $charge ) {
					$this->record_paid( $subject, $charge );
				}
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
			return new WP_Error( 'razorpay_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  Razorpay payment object.
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
	 * Reuse an open link for this invoice when the amount still matches,
	 * otherwise create a fresh one.
	 *
	 * @param Api                   $api      API client.
	 * @param InvoicePayableSubject $subject  Subject.
	 * @param float                 $amount   Major units.
	 * @param string                $currency ISO currency (uppercase).
	 * @return array{url?:string,already_paid?:bool}|WP_Error
	 */
	private function resolve_or_create_link( Api $api, InvoicePayableSubject $subject, float $amount, string $currency ) {
		$ref = $subject->external_payment_ref();
		if ( null !== $ref && '' !== $ref ) {
			$existing = $api->get_payment_link( $ref );
			if ( $existing['success'] ) {
				$link   = $existing['data'];
				$status = strtolower( (string) ( $link['status'] ?? '' ) );

				if ( self::is_paid_status( $status ) ) {
					$mismatch = PaymentCurrency::guard( $currency, $link['currency'] ?? '', 'Razorpay' );
					if ( is_wp_error( $mismatch ) ) {
						return $mismatch;
					}
					$charge = $this->charge_from_link( $api, $link );
					if ( $charge ) {
						$this->record_paid( $subject, $charge );
					}
					return array( 'already_paid' => true );
				}

				$mismatch = PaymentCurrency::guard( $currency, $link['currency'] ?? '', 'Razorpay' );
				if ( is_wp_error( $mismatch ) ) {
					return $mismatch;
				}

				$expected = Api::to_minor_units( $amount, $currency );
				$actual   = (int) ( $link['amount'] ?? 0 );
				$url      = (string) ( $link['short_url'] ?? '' );

				// Only a `created` link is still payable.
				if ( 'created' === $status && $actual === $expected && '' !== $url ) {
					return array( 'url' => $url );
				}
			}
		}

		$created = $api->create_payment_link(
			$amount,
			$currency,
			$this->payment_metadata( $subject ),
			$this->invoice_return_url( $subject )
		);

		if ( ! $created['success'] ) {
			return new WP_Error(
				'razorpay_error',
				$created['message'] ?? __( 'Could not create the Razorpay payment link.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$link    = $created['data'];
		$link_id = (string) ( $link['id'] ?? '' );
		$url     = (string) ( $link['short_url'] ?? '' );

		if ( '' === $link_id || '' === $url ) {
			return new WP_Error(
				'razorpay_error',
				__( 'Razorpay did not return a checkout link.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		// Non-Stripe refs must not populate stripe_payment_intent_id.
		$subject->get_invoice()->set_in_progress_payment_ref( $link_id, false );

		return array( 'url' => $url );
	}

	/**
	 * Resolve the underlying payment for a paid link.
	 *
	 * The link itself is not a charge — its `payments` array points at the real
	 * payment ids, which is what must be recorded as the transaction.
	 *
	 * @param Api   $api  API client.
	 * @param array $link Payment link payload.
	 * @return object|null
	 */
	private function charge_from_link( Api $api, array $link ) {
		foreach ( (array) ( $link['payments'] ?? array() ) as $payment ) {
			if ( ! is_array( $payment ) ) {
				continue;
			}

			$status = strtolower( (string) ( $payment['status'] ?? '' ) );
			if ( '' !== $status && ! in_array( $status, array( 'captured', 'authorized' ), true ) ) {
				continue;
			}

			$payment_id = (string) ( $payment['payment_id'] ?? $payment['id'] ?? '' );
			if ( '' === $payment_id ) {
				continue;
			}

			// Fetch so the recorded amount/currency come from the payment
			// itself rather than the link's requested total.
			$fetched = $api->get_payment( $payment_id );
			if ( $fetched['success'] ) {
				return (object) $fetched['data'];
			}

			return (object) array(
				'id'       => $payment_id,
				'amount'   => (int) ( $payment['amount'] ?? $link['amount_paid'] ?? 0 ),
				'currency' => (string) ( $link['currency'] ?? '' ),
			);
		}

		return null;
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

		$name = $subject->customer_name();
		if ( $name ) {
			$metadata['customer_name'] = $name;
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
	 * @param object $payment Razorpay payment or normalized object.
	 * @return object|null
	 */
	private function normalize_payment( object $payment ) {
		if ( isset( $payment->payment_mode ) && PaymentModeSlugs::razorpay() === (string) $payment->payment_mode ) {
			$txn = (string) ( $payment->transaction_id ?? '' );
			return '' !== $txn ? $payment : null;
		}

		$payment_id = (string) ( $payment->id ?? '' );
		if ( '' === $payment_id ) {
			return null;
		}

		return (object) array(
			'payment_mode'   => PaymentModeSlugs::razorpay(),
			'transaction_id' => $payment_id,
			'id'             => $payment_id,
			// Minor units — uses_major_units() is false for this gateway.
			'amount'         => (int) ( $payment->amount ?? 0 ),
			'currency'       => strtolower( (string) ( $payment->currency ?? '' ) ),
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
