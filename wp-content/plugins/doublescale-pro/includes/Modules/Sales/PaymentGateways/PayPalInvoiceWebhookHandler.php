<?php
/**
 * PayPal invoice webhook handler.
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
use DoubleScale\Pro\Modules\Pro\Payment\PayPalGateway;

/**
 * PayPalInvoiceWebhookHandler class.
 */
final class PayPalInvoiceWebhookHandler {

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
		add_action( 'doublescale_paypal_invoice_event', array( $this, 'handle_webhook_event' ), 10, 2 );
	}

	/**
	 * Default PayPal webhook events for invoice online payments.
	 *
	 * @return string[]
	 */
	public static function default_webhook_events(): array {
		return array(
			'PAYMENT.CAPTURE.COMPLETED',
			'PAYMENT.CAPTURE.DENIED',
			'PAYMENT.CAPTURE.REFUNDED',
			'PAYMENT.CAPTURE.REVERSED',
			'CUSTOMER.DISPUTE.CREATED',
			'CUSTOMER.DISPUTE.UPDATED',
			'CUSTOMER.DISPUTE.RESOLVED',
			'CHECKOUT.ORDER.DECLINED',
			'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
		);
	}

	/**
	 * @param object $event      PayPal webhook event.
	 * @param int    $invoice_id Invoice id.
	 * @return void
	 */
	public function handle_webhook_event( $event, int $invoice_id ): void {
		$resource = isset( $event->resource ) ? $event->resource : null;
		if ( ! $resource ) {
			return;
		}

		$invoice = $this->resolve_invoice( $invoice_id, $resource );
		if ( ! $invoice ) {
			return;
		}

		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_INVOICE, 'paypal' );
		if ( ! $gateway instanceof PayPalGateway ) {
			return;
		}

		$subject = new InvoicePayableSubject( $invoice );
		$type    = (string) ( $event->event_type ?? '' );

		switch ( $type ) {
			case 'PAYMENT.CAPTURE.COMPLETED':
				$gateway->record_paid( $subject, $resource );
				break;
			case 'PAYMENT.CAPTURE.DENIED':
				$this->log_capture_event(
					$invoice,
					$resource,
					__( 'PayPal payment denied for invoice %3$s (%1$s %2$s).', 'doublescale' )
				);
				break;
			case 'PAYMENT.CAPTURE.REFUNDED':
				$this->mark_refunded( $invoice, $resource );
				break;
			case 'PAYMENT.CAPTURE.REVERSED':
				$this->mark_reversed( $invoice, $resource );
				break;
			case 'CUSTOMER.DISPUTE.CREATED':
				$this->mark_disputed( $invoice, $resource );
				break;
			case 'CUSTOMER.DISPUTE.UPDATED':
				$this->mark_dispute_updated( $invoice, $resource );
				break;
			case 'CUSTOMER.DISPUTE.RESOLVED':
				$this->mark_dispute_resolved( $invoice, $resource );
				break;
			case 'CHECKOUT.ORDER.DECLINED':
			case 'CHECKOUT.PAYMENT-APPROVAL.REVERSED':
				$this->handle_checkout_abandoned( $invoice, $resource, $type );
				break;
			default:
				doublescale_get_logger()->info(
					'PayPal invoice webhook ignored',
					array(
						'code'       => 'paypal_invoice_webhook_ignored',
						'event'      => $type,
						'invoice_id' => (int) $invoice->id,
					)
				);
		}
	}

	/**
	 * @param int    $invoice_id Invoice id from webhook resolver.
	 * @param object $resource   PayPal resource payload.
	 * @return InvoiceModel|null
	 */
	private function resolve_invoice( int $invoice_id, $resource ): ?InvoiceModel {
		if ( $invoice_id > 0 ) {
			$invoice = InvoiceModel::find( $invoice_id );
			if ( $invoice ) {
				return $invoice;
			}
		}

		$custom_id = $this->custom_id_from_resource( $resource );
		if ( preg_match( '/^invoice_(\d+)$/', $custom_id, $matches ) ) {
			$invoice = InvoiceModel::find( (int) $matches[1] );
			if ( $invoice ) {
				return $invoice;
			}
		}

		$order_id = '';
		if ( isset( $resource->purchase_units ) || isset( $resource->intent ) ) {
			$order_id = (string) ( $resource->id ?? '' );
		}

		$related_order_id = (string) ( $resource->supplementary_data->related_ids->order_id ?? '' );
		if ( '' !== $related_order_id ) {
			$order_id = $related_order_id;
		}

		if ( '' !== $order_id ) {
			$invoice = InvoiceModel::find_by_external_payment_ref( $order_id );
			if ( $invoice ) {
				return $invoice;
			}
		}

		$capture_id = $this->capture_id_from_resource( $resource );
		if ( '' !== $capture_id ) {
			$payment = PaymentModel::query()->where( 'transaction_id', $capture_id )->first();
			if ( $payment && $payment->invoice_id ) {
				return InvoiceModel::find( (int) $payment->invoice_id );
			}
		}

		return null;
	}

	/**
	 * @param InvoiceModel $invoice  Invoice.
	 * @param object       $resource Refund or capture resource.
	 * @return void
	 */
	private function mark_refunded( InvoiceModel $invoice, $resource ): void {
		$capture_id    = $this->capture_id_from_resource( $resource );
		$refund_amount = $this->refund_amount_from_resource( $resource );

		$payment = $this->find_paypal_payment( $invoice, $capture_id );
		if ( ! $payment ) {
			doublescale_get_logger()->warning(
				'PayPal invoice refund webhook — payment row not found',
				array(
					'code'       => 'paypal_invoice_refund_no_payment',
					'invoice_id' => (int) $invoice->id,
					'capture_id' => $capture_id,
				)
			);
			return;
		}

		$currency        = strtoupper( (string) $invoice->currency );
		$original_amount = round( (float) $payment->amount, 2 );
		$is_full_refund  = $refund_amount <= 0
			|| $refund_amount >= $original_amount - 0.01;
		$remaining       = $is_full_refund ? 0.0 : round( max( 0, $original_amount - $refund_amount ), 2 );

		if ( $remaining <= 0 ) {
			$payment->delete();
			if ( $is_full_refund ) {
				$invoice->clear_in_progress_payment_refs();
			}
		} else {
			$refund_note = sprintf(
				/* translators: 1: refunded amount, 2: currency, 3: capture id */
				__( 'PayPal refund applied. Remaining: %1$s %2$s. Capture: %3$s', 'doublescale' ),
				$remaining,
				$currency,
				'' !== $capture_id ? $capture_id : (string) ( $resource->id ?? '' )
			);
			$payment->amount = $remaining;
			$payment->note   = trim( (string) ( $payment->note ?? '' ) . ' ' . $refund_note );
			$payment->save();
		}

		( new InvoicePayments() )->sync( $invoice->fresh() );

		$this->log_invoice_activity(
			$invoice,
			$is_full_refund
				? sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: invoice number */
					__( 'PayPal full refund of %1$s %2$s for invoice %3$s.', 'doublescale' ),
					$refund_amount > 0 ? $refund_amount : $original_amount,
					$currency,
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: invoice number */
					__( 'PayPal partial refund of %1$s %2$s. Remaining payment: %3$s %2$s on invoice %4$s.', 'doublescale' ),
					$refund_amount,
					$currency,
					$remaining,
					(string) $invoice->invoice_number
				)
		);

		doublescale_get_logger()->info(
			'PayPal invoice refund processed',
			array(
				'code'           => 'paypal_invoice_refund_processed',
				'invoice_id'     => (int) $invoice->id,
				'capture_id'     => $capture_id,
				'is_full_refund' => $is_full_refund,
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice  Invoice.
	 * @param object       $capture  Reversed capture resource.
	 * @return void
	 */
	private function mark_reversed( InvoiceModel $invoice, $capture ): void {
		$this->mark_refunded( $invoice, $capture );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $dispute PayPal dispute resource.
	 * @return void
	 */
	private function mark_disputed( InvoiceModel $invoice, $dispute ): void {
		$capture_id = $this->capture_id_from_dispute( $dispute );
		if ( '' !== $capture_id ) {
			$payment = $this->find_paypal_payment( $invoice, $capture_id );
			if ( $payment ) {
				$dispute_note = sprintf(
					/* translators: 1: dispute reason, 2: dispute id */
					__( 'PayPal dispute opened — reason: %1$s. Dispute: %2$s.', 'doublescale' ),
					(string) ( $dispute->reason ?? 'unknown' ),
					(string) ( $dispute->id ?? '' )
				);
				$payment->note = trim( (string) ( $payment->note ?? '' ) . ' ' . $dispute_note );
				$payment->save();
			}
		}

		doublescale_get_logger()->warning(
			'PayPal invoice dispute opened',
			array(
				'code'       => 'paypal_invoice_dispute',
				'invoice_id' => (int) $invoice->id,
				'dispute_id' => (string) ( $dispute->id ?? '' ),
				'reason'     => (string) ( $dispute->reason ?? '' ),
			)
		);

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: dispute reason, 2: dispute id, 3: invoice number */
				__( 'PayPal dispute on invoice %3$s — reason: %1$s. Dispute: %2$s. Review in PayPal.', 'doublescale' ),
				(string) ( $dispute->reason ?? 'unknown' ),
				(string) ( $dispute->id ?? '' ),
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $dispute PayPal dispute resource.
	 * @return void
	 */
	private function mark_dispute_updated( InvoiceModel $invoice, $dispute ): void {
		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: dispute status, 2: dispute id, 3: invoice number */
				__( 'PayPal dispute updated on invoice %3$s — status: %1$s. Dispute: %2$s.', 'doublescale' ),
				(string) ( $dispute->status ?? 'unknown' ),
				(string) ( $dispute->id ?? '' ),
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $dispute PayPal dispute resource.
	 * @return void
	 */
	private function mark_dispute_resolved( InvoiceModel $invoice, $dispute ): void {
		$outcome = (string) ( $dispute->dispute_outcome->outcome_code ?? $dispute->status ?? 'resolved' );

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: dispute outcome, 2: dispute id, 3: invoice number */
				__( 'PayPal dispute resolved on invoice %3$s — outcome: %1$s. Dispute: %2$s.', 'doublescale' ),
				$outcome,
				(string) ( $dispute->id ?? '' ),
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice    Invoice.
	 * @param object       $order      PayPal order resource.
	 * @param string       $event_type Webhook event type.
	 * @return void
	 */
	private function handle_checkout_abandoned( InvoiceModel $invoice, $order, string $event_type ): void {
		$order_id = (string) ( $order->id ?? '' );
		if ( '' === $order_id ) {
			return;
		}

		if ( (float) $invoice->amount_paid > 0 ) {
			return;
		}

		$active_ref = $invoice->in_progress_payment_ref();
		if ( null === $active_ref || $order_id !== $active_ref ) {
			return;
		}

		$invoice->clear_in_progress_payment_refs();

		$label = 'CHECKOUT.ORDER.DECLINED' === $event_type
			? __( 'declined', 'doublescale' )
			: __( 'approval reversed', 'doublescale' );

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: checkout outcome label, 2: order id, 3: invoice number */
				__( 'PayPal checkout for invoice %3$s was %1$s (order %2$s).', 'doublescale' ),
				$label,
				$order_id,
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice    Invoice.
	 * @param object       $capture    Capture resource.
	 * @param string       $template   sprintf template.
	 * @return void
	 */
	private function log_capture_event( InvoiceModel $invoice, $capture, string $template ): void {
		$amount_data = $capture->amount ?? null;
		$amount      = is_object( $amount_data ) ? (float) ( $amount_data->value ?? 0 ) : 0;
		$currency    = is_object( $amount_data ) ? strtoupper( (string) ( $amount_data->currency_code ?? $invoice->currency ) ) : strtoupper( (string) $invoice->currency );

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				$template,
				$amount,
				$currency,
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice    Invoice.
	 * @param string       $capture_id PayPal capture id.
	 * @return PaymentModel|null
	 */
	private function find_paypal_payment( InvoiceModel $invoice, string $capture_id ): ?PaymentModel {
		if ( '' !== $capture_id ) {
			$payment = PaymentModel::query()->where( 'transaction_id', $capture_id )->first();
			if ( $payment ) {
				return $payment;
			}
		}

		return PaymentModel::query()
			->where( 'invoice_id', (int) $invoice->id )
			->where( 'payment_mode', PaymentMode::PAYPAL )
			->orderBy( 'id', 'desc' )
			->first();
	}

	/**
	 * @param object $resource PayPal webhook resource.
	 * @return string
	 */
	private function custom_id_from_resource( $resource ): string {
		$custom_id = trim( (string) ( $resource->custom_id ?? '' ) );
		if ( '' !== $custom_id ) {
			return $custom_id;
		}

		$units = $resource->purchase_units ?? null;
		if ( is_array( $units ) && ! empty( $units[0] ) ) {
			$unit = $units[0];
			if ( is_object( $unit ) && ! empty( $unit->custom_id ) ) {
				return (string) $unit->custom_id;
			}
			if ( is_array( $unit ) && ! empty( $unit['custom_id'] ) ) {
				return (string) $unit['custom_id'];
			}
		}

		return '';
	}

	/**
	 * @param object $resource Refund, capture, or dispute resource.
	 * @return string
	 */
	private function capture_id_from_resource( $resource ): string {
		$related = (string) ( $resource->supplementary_data->related_ids->capture_id ?? '' );
		if ( '' !== $related ) {
			return $related;
		}

		foreach ( (array) ( $resource->links ?? array() ) as $link ) {
			$rel = is_object( $link ) ? (string) ( $link->rel ?? '' ) : (string) ( $link['rel'] ?? '' );
			if ( 'up' !== $rel ) {
				continue;
			}
			$href = is_object( $link ) ? (string) ( $link->href ?? '' ) : (string) ( $link['href'] ?? '' );
			if ( preg_match( '#/v2/payments/captures/([^/?]+)#', $href, $matches ) ) {
				return (string) $matches[1];
			}
		}

		$status = strtoupper( (string) ( $resource->status ?? '' ) );
		if ( in_array( $status, array( 'PARTIALLY_REFUNDED', 'REFUNDED', 'REVERSED' ), true ) ) {
			return (string) ( $resource->id ?? '' );
		}

		return '';
	}

	/**
	 * @param object $resource Refund or capture resource.
	 * @return float Refund amount in major units; 0 when unknown (treated as full refund).
	 */
	private function refund_amount_from_resource( $resource ): float {
		$amount_data = $resource->amount ?? null;
		if ( is_object( $amount_data ) && isset( $amount_data->value ) ) {
			return round( (float) $amount_data->value, 2 );
		}
		if ( is_array( $amount_data ) && isset( $amount_data['value'] ) ) {
			return round( (float) $amount_data['value'], 2 );
		}

		$breakdown = $resource->seller_payable_breakdown->total_refunded_amount ?? null;
		if ( is_object( $breakdown ) && isset( $breakdown->value ) ) {
			return round( (float) $breakdown->value, 2 );
		}

		return 0.0;
	}

	/**
	 * @param object $dispute PayPal dispute resource.
	 * @return string
	 */
	private function capture_id_from_dispute( $dispute ): string {
		$transactions = $dispute->disputed_transactions ?? array();
		if ( ! is_array( $transactions ) ) {
			return '';
		}

		foreach ( $transactions as $transaction ) {
			$seller_txn = is_object( $transaction )
				? (string) ( $transaction->seller_transaction_id ?? '' )
				: (string) ( $transaction['seller_transaction_id'] ?? '' );
			if ( '' !== $seller_txn ) {
				return $seller_txn;
			}
		}

		return '';
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
