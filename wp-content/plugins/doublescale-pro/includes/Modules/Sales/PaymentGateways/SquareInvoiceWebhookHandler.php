<?php
/**
 * Square invoice webhook handler.
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
use DoubleScale\Pro\Modules\Integrations\Square\Api;
use DoubleScale\Pro\Modules\Integrations\Square\Integration as SquareIntegration;
use DoubleScale\Pro\Modules\Pro\Payment\SquareGateway;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * SquareInvoiceWebhookHandler class.
 */
final class SquareInvoiceWebhookHandler {

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
		add_action( 'doublescale_square_invoice_event', array( $this, 'handle_webhook_event' ), 10, 1 );
	}

	/**
	 * Square webhook events for invoice online payments.
	 *
	 * @return string[]
	 */
	public static function default_webhook_events(): array {
		return array(
			'payment.updated',
			'refund.created',
			'refund.updated',
			'dispute.created',
			'dispute.state.updated',
		);
	}

	/**
	 * @param object $event Square webhook event.
	 * @return void
	 */
	public function handle_webhook_event( $event ): void {
		$type   = (string) ( $event->type ?? '' );
		$object = $event->data->object ?? null;
		if ( ! $object ) {
			return;
		}

		switch ( $type ) {
			case 'payment.updated':
				$this->handle_payment_updated( $object );
				break;
			case 'refund.created':
			case 'refund.updated':
				$this->handle_refund( $object );
				break;
			case 'dispute.created':
			case 'dispute.state.updated':
				$this->handle_dispute( $object, $type );
				break;
			default:
				doublescale_get_logger()->info(
					'Square invoice webhook ignored',
					array(
						'code'  => 'square_invoice_webhook_ignored',
						'event' => $type,
					)
				);
		}
	}

	/**
	 * @param object $object Event object wrapper.
	 * @return void
	 */
	private function handle_payment_updated( $object ): void {
		$payment = $object->payment ?? null;
		if ( ! $payment ) {
			return;
		}

		$status = strtoupper( (string) ( $payment->status ?? '' ) );
		if ( 'COMPLETED' !== $status ) {
			return;
		}

		$invoice = $this->resolve_invoice_from_payment( $payment );
		if ( ! $invoice ) {
			return;
		}

		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_INVOICE, PaymentModeSlugs::square() );
		if ( ! $gateway instanceof SquareGateway ) {
			return;
		}

		$gateway->record_paid( new InvoicePayableSubject( $invoice ), $payment );
	}

	/**
	 * @param object $object Event object wrapper.
	 * @return void
	 */
	private function handle_refund( $object ): void {
		$refund = $object->refund ?? null;
		if ( ! $refund ) {
			return;
		}

		$status = strtoupper( (string) ( $refund->status ?? '' ) );
		if ( 'COMPLETED' !== $status ) {
			return;
		}

		$payment_id = (string) ( $refund->payment_id ?? '' );
		if ( '' === $payment_id ) {
			return;
		}

		$payment_row = PaymentModel::query()->where( 'transaction_id', $payment_id )->first();
		if ( ! $payment_row || ! $payment_row->invoice_id ) {
			doublescale_get_logger()->warning(
				'Square invoice refund webhook — payment row not found',
				array(
					'code'       => 'square_invoice_refund_no_payment',
					'payment_id' => $payment_id,
				)
			);
			return;
		}

		$invoice = InvoiceModel::find( (int) $payment_row->invoice_id );
		if ( ! $invoice ) {
			return;
		}

		$currency        = strtoupper( (string) $invoice->currency );
		$refund_amount   = $this->money_to_major( $refund->amount_money ?? null, $currency );
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
				__( 'Square refund applied. Remaining: %1$s %2$s. Payment: %3$s', 'doublescale' ),
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
					__( 'Square full refund of %1$s %2$s for invoice %3$s.', 'doublescale' ),
					$refund_amount > 0 ? $refund_amount : $original_amount,
					$currency,
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: invoice number */
					__( 'Square partial refund of %1$s %2$s. Remaining payment: %3$s %2$s on invoice %4$s.', 'doublescale' ),
					$refund_amount,
					$currency,
					$remaining,
					(string) $invoice->invoice_number
				)
		);

		doublescale_get_logger()->info(
			'Square invoice refund processed',
			array(
				'code'           => 'square_invoice_refund_processed',
				'invoice_id'     => (int) $invoice->id,
				'payment_id'     => $payment_id,
				'is_full_refund' => $is_full_refund,
			)
		);
	}

	/**
	 * @param object $object     Event object wrapper.
	 * @param string $event_type Webhook event type.
	 * @return void
	 */
	private function handle_dispute( $object, string $event_type ): void {
		$dispute = $object->dispute ?? null;
		if ( ! $dispute ) {
			return;
		}

		$payment_id = (string) ( $dispute->disputed_payment->payment_id ?? '' );
		if ( '' === $payment_id ) {
			return;
		}

		$payment_row = PaymentModel::query()->where( 'transaction_id', $payment_id )->first();
		if ( ! $payment_row || ! $payment_row->invoice_id ) {
			return;
		}

		$invoice = InvoiceModel::find( (int) $payment_row->invoice_id );
		if ( ! $invoice ) {
			return;
		}

		$state  = (string) ( $dispute->state ?? 'unknown' );
		$reason = (string) ( $dispute->reason ?? 'unknown' );

		doublescale_get_logger()->warning(
			'Square invoice dispute',
			array(
				'code'       => 'square_invoice_dispute',
				'invoice_id' => (int) $invoice->id,
				'dispute_id' => (string) ( $dispute->id ?? '' ),
				'state'      => $state,
				'event'      => $event_type,
			)
		);

		$this->log_invoice_activity(
			$invoice,
			sprintf(
				/* translators: 1: dispute reason, 2: dispute state, 3: invoice number */
				__( 'Square dispute on invoice %3$s — reason: %1$s, state: %2$s. Review in the Square Dashboard.', 'doublescale' ),
				$reason,
				$state,
				(string) $invoice->invoice_number
			)
		);
	}

	/**
	 * Resolve the invoice behind a Square payment.
	 *
	 * The order id is the reliable link: the invoice stores the payment-link id,
	 * and the link carries the order id.
	 *
	 * @param object $payment Square payment resource.
	 * @return InvoiceModel|null
	 */
	private function resolve_invoice_from_payment( $payment ): ?InvoiceModel {
		// A payment already recorded gives us the invoice directly.
		$payment_id = (string) ( $payment->id ?? '' );
		if ( '' !== $payment_id ) {
			$existing = PaymentModel::query()->where( 'transaction_id', $payment_id )->first();
			if ( $existing && $existing->invoice_id ) {
				return InvoiceModel::find( (int) $existing->invoice_id );
			}
		}

		// `note` round-trips the payment_note we set when creating the link.
		$invoice_id = $this->invoice_id_from_note( (string) ( $payment->note ?? '' ) );
		if ( $invoice_id > 0 ) {
			$invoice = InvoiceModel::find( $invoice_id );
			if ( $invoice ) {
				return $invoice;
			}
		}

		$order_id = (string) ( $payment->order_id ?? '' );
		if ( '' === $order_id ) {
			return null;
		}

		// Map order -> payment link -> stored invoice ref.
		$api = SquareIntegration::instance()->connect();
		if ( ! $api instanceof Api ) {
			return null;
		}

		$order = $api->get_order( $order_id );
		if ( $order['success'] ) {
			$note       = (string) ( $order['data']['order']['reference_id'] ?? '' );
			$invoice_id = $this->invoice_id_from_note( $note );
			if ( $invoice_id > 0 ) {
				$invoice = InvoiceModel::find( $invoice_id );
				if ( $invoice ) {
					return $invoice;
				}
			}
		}

		return null;
	}

	/**
	 * @param string $note Note carrying `invoice_<id>`.
	 * @return int
	 */
	private function invoice_id_from_note( string $note ): int {
		$note = trim( $note );
		if ( '' === $note ) {
			return 0;
		}
		if ( preg_match( '/invoice_(\d+)/', $note, $matches ) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * @param mixed  $money    Square money object (minor units).
	 * @param string $currency Fallback ISO currency.
	 * @return float Major units.
	 */
	private function money_to_major( $money, string $currency ): float {
		if ( is_object( $money ) ) {
			$amount = (int) ( $money->amount ?? 0 );
			$code   = (string) ( $money->currency ?? $currency );
		} elseif ( is_array( $money ) ) {
			$amount = (int) ( $money['amount'] ?? 0 );
			$code   = (string) ( $money['currency'] ?? $currency );
		} else {
			return 0.0;
		}

		return Api::from_minor_units( $amount, $code );
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
