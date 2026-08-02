<?php
/**
 * Stripe invoice webhook handler (refund, dispute, PI lifecycle).
 *
 * @package DoubleScale\Pro\Modules\Sales\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Sales\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Services\InvoicePayments;
use DoubleScale\Pro\Modules\Integrations\Stripe\Utils as StripeUtils;
use DoubleScale\Pro\Modules\Pro\Payment\StripeGateway;

/**
 * StripeInvoiceWebhookHandler class.
 */
final class StripeInvoiceWebhookHandler {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'doublescale_stripe_invoice_event', array( $this, 'handle_webhook_event' ), 10, 2 );
	}

	/**
	 * @param object $event      Stripe event.
	 * @param int    $invoice_id Invoice id from metadata.
	 * @return void
	 */
	public function handle_webhook_event( $event, int $invoice_id ): void {
		$object = $event->data->object ?? null;
		if ( ! $object || empty( $object->id ) ) {
			return;
		}

		$invoice = $invoice_id > 0 ? InvoiceModel::find( $invoice_id ) : null;
		if ( ! $invoice ) {
			$pi_id = $this->pi_id_from_object( $object );
			if ( '' !== $pi_id ) {
				$invoice = InvoiceModel::find_by_external_payment_ref( $pi_id );
			}
		}
		if ( ! $invoice ) {
			return;
		}

		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_INVOICE, 'stripe' );
		if ( ! $gateway instanceof StripeGateway ) {
			return;
		}

		$subject = new InvoicePayableSubject( $invoice );

		switch ( $event->type ?? '' ) {
			case 'payment_intent.succeeded':
				$gateway->record_paid( $subject, $object );
				break;
			case 'payment_intent.payment_failed':
				$this->log_payment_intent_event( $invoice, $object, 'failed' );
				break;
			case 'payment_intent.canceled':
				$this->log_payment_intent_event( $invoice, $object, 'canceled' );
				break;
			case 'charge.refunded':
				$this->mark_refunded( $invoice, $object );
				break;
			case 'charge.dispute.created':
				$this->mark_disputed( $invoice, $object );
				break;
			default:
				doublescale_get_logger()->info(
					'Stripe invoice webhook ignored',
					array(
						'code'       => 'stripe_invoice_webhook_ignored',
						'event'      => (string) ( $event->type ?? '' ),
						'invoice_id' => (int) $invoice->id,
					)
				);
		}
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $charge  Stripe charge.
	 * @return void
	 */
	private function mark_refunded( InvoiceModel $invoice, $charge ): void {
		$pi_id = $this->pi_id_from_object( $charge );
		if ( '' === $pi_id ) {
			return;
		}

		$payment = $this->find_stripe_payment( $invoice, $pi_id );
		if ( ! $payment ) {
			doublescale_get_logger()->warning(
				'Stripe invoice refund webhook — payment row not found',
				array(
					'code'       => 'stripe_invoice_refund_no_payment',
					'invoice_id' => (int) $invoice->id,
					'pi_id'      => $pi_id,
					'charge_id'  => (string) ( $charge->id ?? '' ),
				)
			);
			return;
		}

		$amount_refunded = (int) ( $charge->amount_refunded ?? 0 );
		$amount_total    = (int) ( $charge->amount ?? 0 );
		$currency        = strtolower( (string) ( $charge->currency ?? $invoice->currency ) );
		$is_full_refund  = $amount_refunded > 0 && $amount_refunded >= $amount_total;
		$remaining_minor = max( 0, $amount_total - $amount_refunded );
		$remaining_major = round( (float) StripeUtils::from_stripe_amount( $remaining_minor, $currency ), 2 );

		if ( $remaining_major <= 0 ) {
			$payment->delete();
			if ( $is_full_refund ) {
				$invoice->clear_in_progress_payment_refs();
			}
		} else {
			$refund_note = sprintf(
				/* translators: 1: remaining amount, 2: currency, 3: charge id */
				__( 'Stripe refund applied. Remaining: %1$s %2$s. Charge: %3$s', 'doublescale' ),
				$remaining_major,
				strtoupper( $currency ),
				(string) ( $charge->id ?? '' )
			);
			$payment->amount = $remaining_major;
			$payment->note   = trim( (string) ( $payment->note ?? '' ) . ' ' . $refund_note );
			$payment->save();
		}

		( new InvoicePayments() )->sync( $invoice->fresh() );

		$refunded_display = StripeUtils::from_stripe_amount( $amount_refunded, $currency );
		$this->log_invoice_activity(
			$invoice,
			$is_full_refund
				? sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: invoice number */
					__( 'Stripe full refund of %1$s %2$s for invoice %3$s.', 'doublescale' ),
					$refunded_display,
					strtoupper( $currency ),
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: invoice number */
					__( 'Stripe partial refund of %1$s %2$s. Remaining payment: %3$s %2$s on invoice %4$s.', 'doublescale' ),
					$refunded_display,
					strtoupper( $currency ),
					$remaining_major,
					(string) $invoice->invoice_number
				)
		);

		doublescale_get_logger()->info(
			'Stripe invoice refund processed',
			array(
				'code'           => 'stripe_invoice_refund_processed',
				'invoice_id'     => (int) $invoice->id,
				'pi_id'          => $pi_id,
				'charge_id'      => (string) ( $charge->id ?? '' ),
				'is_full_refund' => $is_full_refund,
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $dispute Stripe dispute.
	 * @return void
	 */
	private function mark_disputed( InvoiceModel $invoice, $dispute ): void {
		$pi_id = $this->pi_id_from_object( $dispute );
		if ( '' !== $pi_id ) {
			$payment = $this->find_stripe_payment( $invoice, $pi_id );
			if ( $payment ) {
				$dispute_note = sprintf(
					/* translators: 1: dispute reason, 2: dispute id */
					__( 'Stripe dispute opened — reason: %1$s. Dispute: %2$s.', 'doublescale' ),
					(string) ( $dispute->reason ?? 'unknown' ),
					(string) ( $dispute->id ?? '' )
				);
				$payment->note = trim( (string) ( $payment->note ?? '' ) . ' ' . $dispute_note );
				$payment->save();
			}
		}

		doublescale_get_logger()->warning(
			'Stripe invoice dispute opened',
			array(
				'code'       => 'stripe_invoice_dispute',
				'invoice_id' => (int) $invoice->id,
				'dispute_id' => (string) ( $dispute->id ?? '' ),
				'reason'     => (string) ( $dispute->reason ?? '' ),
			)
		);

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: dispute reason, 2: dispute id, 3: invoice number */
				__( 'Cardholder dispute on invoice %3$s — reason: %1$s. Dispute: %2$s. Review in Stripe.', 'doublescale' ),
				(string) ( $dispute->reason ?? 'unknown' ),
				(string) ( $dispute->id ?? '' ),
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice        Invoice.
	 * @param object       $payment_intent Stripe PI.
	 * @param string       $reason         `failed` or `canceled`.
	 * @return void
	 */
	private function log_payment_intent_event( InvoiceModel $invoice, $payment_intent, string $reason ): void {
		$pi_id    = (string) ( $payment_intent->id ?? '' );
		$currency = strtoupper( (string) ( $payment_intent->currency ?? $invoice->currency ) );
		$amount   = StripeUtils::from_stripe_amount( (int) ( $payment_intent->amount ?? 0 ), strtolower( $currency ) );

		if ( 'canceled' === $reason && (float) $invoice->amount_paid <= 0 ) {
			$active_ref = $invoice->in_progress_payment_ref();
			if ( null !== $active_ref && $pi_id === $active_ref ) {
				$invoice->clear_in_progress_payment_refs();
			}
		}

		if ( 'canceled' === $reason ) {
			$cancel_reason = (string) ( $payment_intent->cancellation_reason ?? 'expired' );
			$this->log_invoice_activity(
				$invoice,
				sprintf(
					/* translators: 1: amount, 2: currency, 3: cancellation reason, 4: invoice number */
					__( 'Stripe payment for invoice %4$s canceled (%1$s %2$s): %3$s', 'doublescale' ),
					$amount,
					$currency,
					$cancel_reason,
					(string) $invoice->invoice_number
				)
			);
			return;
		}

		$error_message = isset( $payment_intent->last_payment_error->message )
			? (string) $payment_intent->last_payment_error->message
			: __( 'Unknown error', 'doublescale' );

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: amount, 2: currency, 3: error message, 4: invoice number */
				__( 'Stripe payment for invoice %4$s failed (%1$s %2$s): %3$s', 'doublescale' ),
				$amount,
				$currency,
				$error_message,
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $pi_id   Payment intent id.
	 * @return PaymentModel|null
	 */
	private function find_stripe_payment( InvoiceModel $invoice, string $pi_id ): ?PaymentModel {
		$payment = PaymentModel::query()->where( 'transaction_id', $pi_id )->first();
		if ( $payment ) {
			return $payment;
		}

		return PaymentModel::query()
			->where( 'invoice_id', (int) $invoice->id )
			->where( 'payment_mode', PaymentMode::STRIPE )
			->orderBy( 'id', 'desc' )
			->first();
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $note    Activity note.
	 * @return void
	 */
	private function log_invoice_activity( InvoiceModel $invoice, string $note ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $invoice->contact_id,
				'activity_type' => ActivityTypes::STATUS_CHANGED,
				'data'          => array(
					'title'      => __( 'Invoice payment update', 'doublescale' ),
					'type'       => 'system',
					'note'       => $note,
					'invoice_id' => (int) $invoice->id,
				),
				'user_id'       => null,
			)
		);
	}

	/**
	 * @param object $object Stripe object.
	 * @return string
	 */
	private function pi_id_from_object( $object ): string {
		$type = isset( $object->object ) ? (string) $object->object : '';
		if ( 'payment_intent' === $type ) {
			return (string) ( $object->id ?? '' );
		}
		if ( in_array( $type, array( 'charge', 'dispute' ), true ) && ! empty( $object->payment_intent ) ) {
			return (string) $object->payment_intent;
		}
		return '';
	}
}
