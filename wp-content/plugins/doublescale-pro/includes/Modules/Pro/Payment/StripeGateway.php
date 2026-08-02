<?php
/**
 * Unified Stripe payment gateway for booking and invoice contexts.
 *
 * @package DoubleScale\Pro\Modules\Pro\Payment
 */

namespace DoubleScale\Pro\Modules\Pro\Payment;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\Gateway;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Pro\Modules\Booking\PaymentGateways\BookingPayableSubject;
use DoubleScale\Pro\Modules\Integrations\Stripe\Customers;
use DoubleScale\Pro\Modules\Integrations\Stripe\Integration as StripeIntegration;
use DoubleScale\Pro\Modules\Integrations\Stripe\PaymentIntentFlow;
use DoubleScale\Pro\Modules\Integrations\Stripe\PaymentService;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;

/**
 * StripeGateway class.
 */
class StripeGateway extends Gateway {

	public $name = 'Stripe';

	public $slug = 'stripe';

	public $description = 'Card payments via Stripe — credentials in Integrations → Stripe.';

	/**
	 * @return void
	 */
	protected function register(): void {
		$manager = GatewayManager::instance();
		$manager->register( GatewayManager::CONTEXT_INVOICE, $this );
		$manager->register( GatewayManager::CONTEXT_BOOKING, $this );
	}

	public function is_available(): bool {
		return true;
	}

	public function is_configured(): bool {
		return StripeIntegration::instance()->is_configured();
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'gateway_not_configured', __( 'Stripe is not configured.', 'doublescale' ), array( 'status' => 503 ) );
		}

		try {
			$service  = new PaymentService();
			$amount   = $subject->amount_due();
			$currency = $subject->currency();

			list( $intent, $created ) = PaymentIntentFlow::resolve_or_create(
				$service,
				$subject->external_payment_ref(),
				$amount,
				$currency,
				function () use ( $service, $subject, $amount, $currency ) {
					$customers   = new Customers();
					$customer_id = $customers->get_or_create( $subject->customer_name(), $subject->customer_email() );

					$payment_intent = $service->create_payment_intent(
						$amount,
						$currency,
						$customer_id,
						$subject->metadata()
					);

					$subject->set_external_payment_ref( (string) $payment_intent->id );

					if ( $subject instanceof BookingPayableSubject ) {
						$subject->store_payment_meta( $customer_id, $amount, $currency );
						$subject->ensure_pending_order( (string) $payment_intent->id, $amount, $currency );
					}

					return $payment_intent;
				}
			);

			unset( $created );

			$status = (string) ( $intent->status ?? '' );
			if ( in_array( $status, array( 'succeeded', 'processing' ), true ) ) {
				if ( 'succeeded' === $status ) {
					$this->record_paid( $subject, $intent );
				}
				return $this->shape_already_paid_response( $subject, $service, $amount, $currency, $status );
			}

			$response = array(
				'gateway'         => $this->slug,
				'client_secret'   => $intent->client_secret,
				'publishable_key' => $service->publishable_key(),
				'amount'          => $amount,
				'currency'        => $currency,
			);

			if ( $subject instanceof BookingPayableSubject ) {
				$response['booking_id'] = $subject->get_booking()->hash_id;
			}

			return $response;
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Stripe payment init failed',
				array(
					'code'    => 'stripe_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'stripe_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function confirm( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'gateway_not_configured', __( 'Stripe is not configured.', 'doublescale' ), array( 'status' => 503 ) );
		}

		$pi_id = $subject->external_payment_ref();
		if ( null === $pi_id || '' === $pi_id ) {
			return new WP_Error( 'invalid_data', __( 'No Stripe payment is in progress.', 'doublescale' ), array( 'status' => 400 ) );
		}

		try {
			$service = new PaymentService();
			$pi      = $service->retrieve_payment_intent( $pi_id );
			if ( ! $pi ) {
				return new WP_Error( 'stripe_error', __( 'Could not retrieve payment intent.', 'doublescale' ), array( 'status' => 500 ) );
			}

			if ( 'succeeded' === (string) $pi->status ) {
				$this->record_paid( $subject, $pi );
			}

			$response = array(
				'gateway'   => $this->slug,
				'pi_status' => (string) $pi->status,
			);

			if ( $subject instanceof InvoicePayableSubject ) {
				$invoice = $subject->get_invoice();
				$invoice->refresh();
				$response['invoice'] = $invoice;
			}

			if ( $subject instanceof BookingPayableSubject ) {
				$booking = $subject->get_booking();
				$response['payment_status'] = $booking->getPaymentStatus();
				$response['booking_status'] = (string) $booking->status;
			}

			return $response;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  Stripe payment intent or charge.
	 * @return void
	 */
	public function record_paid( PayableSubject $subject, object $charge ): void {
		$pi_id = (string) ( $charge->id ?? '' );
		if ( '' === $pi_id ) {
			return;
		}

		$subject->record_payment( $charge );
	}

	/**
	 * @param PayableSubject  $subject Payable subject.
	 * @param PaymentService  $service Stripe service.
	 * @param float           $amount  Major units.
	 * @param string          $currency ISO currency.
	 * @param string          $status  PI status.
	 * @return array<string, mixed>
	 */
	private function shape_already_paid_response( PayableSubject $subject, PaymentService $service, float $amount, string $currency, string $status ): array {
		$response = array(
			'gateway'         => $this->slug,
			'already_paid'    => true,
			'pi_status'       => $status,
			'publishable_key' => $service->publishable_key(),
			'amount'          => $amount,
			'currency'        => $currency,
		);

		if ( $subject instanceof InvoicePayableSubject ) {
			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );
		}

		if ( $subject instanceof BookingPayableSubject ) {
			$response['booking_id'] = $subject->get_booking()->hash_id;
		}

		return $response;
	}
}
