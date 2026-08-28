<?php
/**
 * Mollie invoice webhook handler.
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
use DoubleScale\Pro\Modules\Pro\Payment\MollieGateway;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * MollieInvoiceWebhookHandler class.
 */
final class MollieInvoiceWebhookHandler {

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
		add_action( 'doublescale_mollie_invoice_event', array( $this, 'handle_webhook_event' ), 10, 1 );
	}

	/**
	 * Mollie sends one webhook per status transition on a single payment
	 * object — there are no separate event types to subscribe to.
	 *
	 * @param object $payment Mollie payment resource (freshly fetched).
	 * @return void
	 */
	public function handle_webhook_event( $payment ): void {
		$payment_id = (string) ( $payment->id ?? '' );
		if ( '' === $payment_id ) {
			return;
		}

		$status  = strtolower( (string) ( $payment->status ?? '' ) );
		$invoice = $this->resolve_invoice( $payment );
		if ( ! $invoice ) {
			doublescale_get_logger()->warning(
				'Mollie webhook — invoice not resolved',
				array(
					'code'       => 'mollie_webhook_no_invoice',
					'payment_id' => $payment_id,
					'status'      => $status,
				)
			);
			return;
		}

		if ( MollieGateway::is_paid_status( $status ) ) {
			$this->handle_paid( $invoice, $payment );
			return;
		}

		// A refund can arrive on an otherwise `paid` payment, so check amounts
		// before treating a terminal status as a plain failure.
		if ( $this->refunded_amount( $payment ) > 0 ) {
			$this->handle_refund( $invoice, $payment );
			return;
		}

		if ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
			$this->handle_unsuccessful( $invoice, $payment, $status );
			return;
		}

		doublescale_get_logger()->info(
			'Mollie invoice webhook ignored',
			array(
				'code'       => 'mollie_invoice_webhook_ignored',
				'status'     => $status,
				'invoice_id' => (int) $invoice->id,
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $payment Mollie payment.
	 * @return void
	 */
	private function handle_paid( InvoiceModel $invoice, $payment ): void {
		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_INVOICE, PaymentModeSlugs::mollie() );
		if ( ! $gateway instanceof MollieGateway ) {
			return;
		}

		// A refund on a still-`paid` payment must not re-record the payment.
		if ( $this->refunded_amount( $payment ) > 0 ) {
			$this->handle_refund( $invoice, $payment );
			return;
		}

		$gateway->record_paid( new InvoicePayableSubject( $invoice ), $payment );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $payment Mollie payment.
	 * @return void
	 */
	private function handle_refund( InvoiceModel $invoice, $payment ): void {
		$payment_id = (string) ( $payment->id ?? '' );

		$payment_row = PaymentModel::query()->where( 'transaction_id', $payment_id )->first();
		if ( ! $payment_row ) {
			doublescale_get_logger()->warning(
				'Mollie invoice refund webhook — payment row not found',
				array(
					'code'       => 'mollie_invoice_refund_no_payment',
					'invoice_id' => (int) $invoice->id,
					'payment_id' => $payment_id,
				)
			);
			return;
		}

		$currency        = strtoupper( (string) $invoice->currency );
		$refund_amount   = $this->refunded_amount( $payment );
		$original_amount = round( (float) $payment_row->amount, 2 );
		$is_full_refund  = $refund_amount <= 0 || $refund_amount >= $original_amount - 0.01;
		$remaining       = $is_full_refund ? 0.0 : round( max( 0, $original_amount - $refund_amount ), 2 );

		if ( $remaining <= 0 ) {
			$payment_row->delete();
			if ( $is_full_refund ) {
				$invoice->clear_in_progress_payment_refs();
			}
		} else {
			$refund_note = sprintf(
				/* translators: 1: remaining amount, 2: currency, 3: payment id */
				__( 'Mollie refund applied. Remaining: %1$s %2$s. Payment: %3$s', 'doublescale' ),
				$remaining,
				$currency,
				$payment_id
			);
			$payment_row->amount = $remaining;
			$payment_row->note   = trim( (string) ( $payment_row->note ?? '' ) . ' ' . $refund_note );
			$payment_row->save();
		}

		( new InvoicePayments() )->sync( $invoice->fresh() );

		$this->log_invoice_activity(
			$invoice,
			$is_full_refund
				? sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: invoice number */
					__( 'Mollie full refund of %1$s %2$s for invoice %3$s.', 'doublescale' ),
					$refund_amount > 0 ? $refund_amount : $original_amount,
					$currency,
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: invoice number */
					__( 'Mollie partial refund of %1$s %2$s. Remaining payment: %3$s %2$s on invoice %4$s.', 'doublescale' ),
					$refund_amount,
					$currency,
					$remaining,
					(string) $invoice->invoice_number
				)
		);

		doublescale_get_logger()->info(
			'Mollie invoice refund processed',
			array(
				'code'           => 'mollie_invoice_refund_processed',
				'invoice_id'     => (int) $invoice->id,
				'payment_id'     => $payment_id,
				'is_full_refund' => $is_full_refund,
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $payment Mollie payment.
	 * @param string       $status  Payment status.
	 * @return void
	 */
	private function handle_unsuccessful( InvoiceModel $invoice, $payment, string $status ): void {
		$payment_id = (string) ( $payment->id ?? '' );

		if ( (float) $invoice->amount_paid > 0 ) {
			return;
		}

		// Only clear the ref when it is still the one in flight.
		$active_ref = $invoice->in_progress_payment_ref();
		if ( null === $active_ref || $payment_id !== $active_ref ) {
			return;
		}

		$invoice->clear_in_progress_payment_refs();

		$labels = array(
			'failed'   => __( 'failed', 'doublescale' ),
			'canceled' => __( 'canceled', 'doublescale' ),
			'expired'  => __( 'expired', 'doublescale' ),
		);

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: outcome label, 2: payment id, 3: invoice number */
				__( 'Mollie checkout for invoice %3$s %1$s (payment %2$s).', 'doublescale' ),
				$labels[ $status ] ?? $status,
				$payment_id,
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * Total refunded on a payment, in major units.
	 *
	 * @param object $payment Mollie payment.
	 * @return float
	 */
	private function refunded_amount( $payment ): float {
		$refunded = $payment->amountRefunded ?? null;

		if ( is_object( $refunded ) ) {
			return round( (float) ( $refunded->value ?? 0 ), 2 );
		}
		if ( is_array( $refunded ) ) {
			return round( (float) ( $refunded['value'] ?? 0 ), 2 );
		}

		return 0.0;
	}

	/**
	 * Resolve the invoice from the payment's metadata, falling back to the
	 * stored in-progress ref.
	 *
	 * @param object $payment Mollie payment.
	 * @return InvoiceModel|null
	 */
	private function resolve_invoice( $payment ): ?InvoiceModel {
		$metadata   = $payment->metadata ?? null;
		$invoice_id = 0;

		if ( is_object( $metadata ) ) {
			$invoice_id = (int) ( $metadata->invoice_id ?? 0 );
		} elseif ( is_array( $metadata ) ) {
			$invoice_id = (int) ( $metadata['invoice_id'] ?? 0 );
		}

		if ( $invoice_id > 0 ) {
			$invoice = InvoiceModel::find( $invoice_id );
			if ( $invoice ) {
				return $invoice;
			}
		}

		$payment_id = (string) ( $payment->id ?? '' );
		if ( '' === $payment_id ) {
			return null;
		}

		$invoice = InvoiceModel::find_by_external_payment_ref( $payment_id );
		if ( $invoice ) {
			return $invoice;
		}

		$row = PaymentModel::query()->where( 'transaction_id', $payment_id )->first();
		if ( $row && $row->invoice_id ) {
			return InvoiceModel::find( (int) $row->invoice_id );
		}

		return null;
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
}
