<?php
/**
 * Booking PayableSubject adapter for unified payment gateways.
 *
 * @package DoubleScale\Pro\Modules\Booking\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Booking\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Pro\Modules\Integrations\Stripe\PaymentService;
use DoubleScale\Pro\Modules\Integrations\Stripe\Utils as StripeUtils;

/**
 * BookingPayableSubject class.
 */
final class BookingPayableSubject implements PayableSubject {

	/**
	 * @var BookingModel
	 */
	private $booking;

	/**
	 * @param BookingModel $booking Booking.
	 */
	public function __construct( BookingModel $booking ) {
		$this->booking = $booking;
	}

	/**
	 * @return BookingModel
	 */
	public function get_booking(): BookingModel {
		return $this->booking;
	}

	public function context(): string {
		return 'booking';
	}

	public function entity_id(): int {
		return (int) $this->booking->id;
	}

	public function amount_due(): float {
		$bookable = $this->booking->getBookableEntity();
		if ( $bookable && method_exists( $bookable, 'getTotalPrice' ) ) {
			$live = (float) $bookable->getTotalPrice();
			if ( $live > 0 ) {
				return $live;
			}
		}
		$override = $this->booking->get_meta( 'payment_amount', 0 );
		return $override > 0 ? (float) $override : 0.0;
	}

	public function currency(): string {
		$bookable = $this->booking->getBookableEntity();
		$settings = $bookable && isset( $bookable->payments_settings ) ? (array) $bookable->payments_settings : array();
		$live     = isset( $settings['currency'] ) && '' !== $settings['currency'] ? (string) $settings['currency'] : '';
		if ( '' !== $live ) {
			return $live;
		}
		$override = $this->booking->get_meta( 'payment_currency', '' );
		if ( is_string( $override ) && '' !== $override ) {
			return $override;
		}
		return 'USD';
	}

	public function customer_name(): ?string {
		$name = $this->booking->getContactDisplayName();
		return '' !== $name ? $name : null;
	}

	public function customer_email(): ?string {
		$email = $this->booking->contact->email ?? null;
		return is_string( $email ) && '' !== $email ? $email : null;
	}

	public function external_payment_ref(): ?string {
		$pi_id = $this->booking->get_meta( 'stripe_payment_intent_id' );
		return is_string( $pi_id ) && '' !== $pi_id ? $pi_id : null;
	}

	public function set_external_payment_ref( string $id ): void {
		$this->booking->update_meta( 'stripe_payment_intent_id', $id );
	}

	public function metadata(): array {
		return array(
			'source'       => 'booking',
			'booking_id'   => (string) $this->booking->id,
			'booking_hash' => $this->booking->hash_id,
			'guest_email'  => $this->customer_email() ?? '',
		);
	}

	public function record_payment( object $charge ): void {
		if ( 'completed' === $this->booking->getPaymentStatus() ) {
			return;
		}

		if ( $this->booking->isCancelled() ) {
			doublescale_get_logger()->error(
				'Stripe payment succeeded on cancelled booking — auto-refunding',
				array(
					'code'       => 'stripe_paid_after_cancel',
					'booking_id' => (int) $this->booking->id,
					'pi_id'      => (string) ( $charge->id ?? '' ),
				)
			);
			try {
				$service = new PaymentService();
				$service->client()->refunds->create( array( 'payment_intent' => (string) $charge->id ) );
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->error(
					'Stripe auto-refund failed',
					array( 'code' => 'stripe_auto_refund_failed', 'pi_id' => (string) ( $charge->id ?? '' ), 'message' => $e->getMessage() )
				);
			}
			$this->booking->logs()->create(
				array(
					'type'    => 'error',
					'message' => __( 'Payment captured on cancelled booking — refunded', 'doublescale' ),
					'details' => sprintf(
						/* translators: %s: payment intent id */
						__( 'Booking was already cancelled when Stripe captured payment intent %s. An automatic refund was issued.', 'doublescale' ),
						(string) ( $charge->id ?? '' )
					),
				)
			);
			return;
		}

		$expected_amount   = $this->amount_due();
		$expected_currency = strtolower( $this->currency() );
		$expected_minor    = StripeUtils::to_stripe_amount( $expected_amount, $expected_currency );
		$received_minor    = (int) ( $charge->amount ?? 0 );
		$received_currency = strtolower( (string) ( $charge->currency ?? '' ) );

		if ( $received_minor !== $expected_minor || $received_currency !== $expected_currency ) {
			doublescale_get_logger()->error(
				'Stripe booking webhook amount/currency mismatch',
				array(
					'code'              => 'stripe_booking_amount_mismatch',
					'booking_id'        => (int) $this->booking->id,
					'pi_id'             => (string) ( $charge->id ?? '' ),
					'expected_minor'    => $expected_minor,
					'received_minor'    => $received_minor,
					'expected_currency' => $expected_currency,
					'received_currency' => $received_currency,
				)
			);
			$this->booking->logs()->create(
				array(
					'type'    => 'error',
					'message' => __( 'Payment amount mismatch — not marked as paid', 'doublescale' ),
					'details' => sprintf(
						/* translators: 1: received amount, 2: received currency, 3: expected amount, 4: expected currency */
						__( 'Webhook reported %1$s %2$s but expected %3$s %4$s. Booking left pending for manual review.', 'doublescale' ),
						StripeUtils::from_stripe_amount( $received_minor, $received_currency ),
						strtoupper( $received_currency ),
						$expected_amount,
						strtoupper( $expected_currency )
					),
				)
			);
			return;
		}

		$this->booking->setPaymentStatus( 'completed' );
		$this->booking->update_meta( 'stripe_payment_intent_id', (string) ( $charge->id ?? '' ) );

		if ( ! empty( $charge->latest_charge ) ) {
			try {
				$service = new PaymentService();
				$stripe_charge = $service->retrieve_charge( $charge->latest_charge );
				if ( $stripe_charge ) {
					$this->booking->update_meta( 'stripe_charge_id', $stripe_charge->id );
					$this->booking->update_meta( 'stripe_receipt_url', $stripe_charge->receipt_url );
					if ( $this->booking->order ) {
						$this->booking->order()->update( array( 'transaction_id' => $stripe_charge->id, 'status' => 'completed' ) );
					}
				}
			} catch ( \Throwable $e ) {
				if ( $this->booking->order ) {
					$this->booking->order()->update( array( 'transaction_id' => $charge->latest_charge, 'status' => 'completed' ) );
				}
			}
		} elseif ( $this->booking->order ) {
			$this->booking->order()->update( array( 'transaction_id' => (string) ( $charge->id ?? '' ), 'status' => 'completed' ) );
		}

		$this->booking->logs()->create(
			array(
				'type'    => 'info',
				'message' => __( 'Payment processed', 'doublescale' ),
				'details' => sprintf(
					/* translators: 1: amount, 2: currency, 3: txn id */
					__( 'Payment of %1$s %2$s processed via Stripe. Transaction ID: %3$s', 'doublescale' ),
					( (int) ( $charge->amount ?? 0 ) ) / 100,
					strtoupper( (string) ( $charge->currency ?? '' ) ),
					$this->booking->order->transaction_id ?? (string) ( $charge->id ?? '' )
				),
			)
		);
	}

	/**
	 * Ensure a pending order row exists before charging.
	 *
	 * @param string $pi_id    Payment intent id.
	 * @param float  $amount   Major units.
	 * @param string $currency ISO currency.
	 * @return void
	 */
	public function ensure_pending_order( string $pi_id, float $amount, string $currency ): void {
		$bookable = $this->booking->getBookableEntity();
		$items    = $bookable ? $bookable->getItems() : array();

		if ( ! $this->booking->order ) {
			$this->booking->order()->create(
				array(
					'payment_method' => 'stripe',
					'status'         => 'pending',
					'total'          => $amount,
					'currency'       => $currency,
					'items'          => $items,
					'transaction_id' => $pi_id,
				)
			);
		} else {
			$order = $this->booking->order;
			$order->fill(
				array(
					'payment_method' => 'stripe',
					'status'         => 'pending',
					'total'          => $amount,
					'currency'       => $currency,
					'items'          => $items,
					'transaction_id' => $pi_id,
				)
			)->save();
		}
	}

	/**
	 * Store customer id and payment amount meta after PI creation.
	 *
	 * @param string $customer_id Stripe customer id.
	 * @param float  $amount      Major units.
	 * @param string $currency    ISO currency.
	 * @return void
	 */
	public function store_payment_meta( string $customer_id, float $amount, string $currency ): void {
		$this->booking->update_meta( 'stripe_customer_id', $customer_id );
		$this->booking->update_meta( 'payment_amount', $amount );
		$this->booking->update_meta( 'payment_currency', $currency );
	}
}
