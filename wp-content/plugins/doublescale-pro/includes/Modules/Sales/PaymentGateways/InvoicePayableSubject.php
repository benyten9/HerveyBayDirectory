<?php
/**
 * Invoice PayableSubject adapter for unified payment gateways.
 *
 * @package DoubleScale\Pro\Modules\Sales\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Sales\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Services\InvoicePayments;
use DoubleScale\Pro\Modules\Integrations\Stripe\Utils as StripeUtils;

/**
 * InvoicePayableSubject class.
 */
final class InvoicePayableSubject implements PayableSubject {

	/**
	 * @var InvoiceModel
	 */
	private $invoice;

	/**
	 * @param InvoiceModel $invoice Invoice.
	 */
	public function __construct( InvoiceModel $invoice ) {
		$this->invoice = $invoice;
	}

	/**
	 * @return InvoiceModel
	 */
	public function get_invoice(): InvoiceModel {
		return $this->invoice;
	}

	public function context(): string {
		return 'invoice';
	}

	public function entity_id(): int {
		return (int) $this->invoice->id;
	}

	public function amount_due(): float {
		return max( 0, round( (float) $this->invoice->total - (float) $this->invoice->amount_paid, 2 ) );
	}

	public function currency(): string {
		return (string) $this->invoice->currency;
	}

	public function customer_name(): ?string {
		$contact = $this->invoice->relationLoaded( 'contact' ) ? $this->invoice->contact : $this->invoice->contact()->first();
		if ( ! $contact ) {
			return null;
		}
		$name = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
		return '' !== $name ? $name : null;
	}

	public function customer_email(): ?string {
		$contact = $this->invoice->relationLoaded( 'contact' ) ? $this->invoice->contact : $this->invoice->contact()->first();
		if ( ! $contact ) {
			return null;
		}
		$email = (string) ( $contact->email ?? '' );
		return '' !== $email ? $email : null;
	}

	public function external_payment_ref(): ?string {
		return $this->invoice->in_progress_payment_ref();
	}

	public function set_external_payment_ref( string $id ): void {
		$this->invoice->set_in_progress_payment_ref( $id, true );
	}

	public function metadata(): array {
		return array(
			'source'         => 'invoice',
			'invoice_id'     => (string) $this->invoice->id,
			'invoice_number' => (string) $this->invoice->invoice_number,
			'invoice_hash'   => (string) $this->invoice->hash,
		);
	}

	public function record_payment( object $charge ): void {
		$payment_mode   = isset( $charge->payment_mode ) ? (string) $charge->payment_mode : PaymentMode::STRIPE;
		$transaction_id = isset( $charge->transaction_id )
			? (string) $charge->transaction_id
			: (string) ( $charge->id ?? '' );

		if ( '' === $transaction_id ) {
			return;
		}

		$existing = PaymentModel::query()->where( 'transaction_id', $transaction_id )->first();
		if ( $existing ) {
			return;
		}

		$currency        = strtolower( (string) ( $charge->currency ?? $this->invoice->currency ) );
		$expected_amount = $this->amount_due();

		if ( PaymentMode::PAYPAL === $payment_mode ) {
			$received_amount = round( (float) ( $charge->amount ?? 0 ), 2 );
			if ( $received_amount > $expected_amount + 0.01 ) {
				doublescale_get_logger()->error(
					'PayPal invoice payment exceeds balance due',
					array(
						'code'       => 'paypal_invoice_amount_mismatch',
						'invoice_id' => (int) $this->invoice->id,
						'capture_id' => $transaction_id,
					)
				);
				return;
			}
		} else {
			$received_minor  = (int) ( $charge->amount ?? 0 );
			$received_amount = (float) StripeUtils::from_stripe_amount( $received_minor, $currency );
			$expected_minor  = StripeUtils::to_stripe_amount( $expected_amount, $currency );

			if ( $received_minor > $expected_minor + 1 ) {
				doublescale_get_logger()->error(
					'Stripe invoice payment exceeds balance due',
					array(
						'code'       => 'stripe_invoice_amount_mismatch',
						'invoice_id' => (int) $this->invoice->id,
						'pi_id'      => $transaction_id,
					)
				);
				return;
			}
		}

		$note = PaymentMode::PAYPAL === $payment_mode
			? sprintf(
				/* translators: %s: invoice number */
				__( 'PayPal payment for invoice %s', 'doublescale' ),
				(string) $this->invoice->invoice_number
			)
			: sprintf(
				/* translators: %s: invoice number */
				__( 'Stripe payment for invoice %s', 'doublescale' ),
				(string) $this->invoice->invoice_number
			);

		$payment = new PaymentModel();
		$payment->fill(
			array(
				'invoice_id'          => (int) $this->invoice->id,
				'amount'              => round( $received_amount, 2 ),
				'payment_mode'        => $payment_mode,
				'payment_date'        => current_time( 'Y-m-d' ),
				'transaction_id'      => $transaction_id,
				'note'                => $note,
				'recorded_by_user_id' => get_current_user_id() ?: null,
			)
		);
		$payment->save();

		$this->invoice->set_in_progress_payment_ref( $transaction_id, PaymentMode::STRIPE === $payment_mode );

		( new InvoicePayments() )->sync( $this->invoice );
	}
}
