<?php
/**
 * Authorize.Net invoice webhook handler.
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
use DoubleScale\Pro\Modules\Integrations\AuthorizeNet\Api;
use DoubleScale\Pro\Modules\Integrations\AuthorizeNet\Integration as AuthorizeNetIntegration;
use DoubleScale\Pro\Modules\Pro\Payment\AuthorizeNetGateway;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * AuthorizeNetInvoiceWebhookHandler class.
 */
final class AuthorizeNetInvoiceWebhookHandler {

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
		add_action( 'doublescale_authorize_net_invoice_event', array( $this, 'handle_webhook_event' ), 10, 1 );
	}

	/**
	 * Authorize.Net webhook events for invoice online payments.
	 *
	 * @return string[]
	 */
	public static function default_webhook_events(): array {
		return array(
			'net.authorize.payment.authcapture.created',
			'net.authorize.payment.capture.created',
			'net.authorize.payment.refund.created',
			'net.authorize.payment.void.created',
		);
	}

	/**
	 * @param object $event Authorize.Net webhook event.
	 * @return void
	 */
	public function handle_webhook_event( $event ): void {
		$type    = (string) ( $event->eventType ?? '' );
		$payload = $event->payload ?? null;
		if ( ! $payload ) {
			return;
		}

		$transaction_id = (string) ( $payload->id ?? '' );
		if ( '' === $transaction_id ) {
			return;
		}

		switch ( $type ) {
			case 'net.authorize.payment.authcapture.created':
			case 'net.authorize.payment.capture.created':
				$this->handle_captured( $transaction_id );
				break;
			case 'net.authorize.payment.refund.created':
			case 'net.authorize.payment.void.created':
				$this->handle_refund( $transaction_id, $payload, $type );
				break;
			default:
				doublescale_get_logger()->info(
					'Authorize.Net invoice webhook ignored',
					array(
						'code'  => 'authorize_net_invoice_webhook_ignored',
						'event' => $type,
					)
				);
		}
	}

	/**
	 * The webhook payload is a summary; the transaction must be fetched to get
	 * the invoice number and settled amount.
	 *
	 * @param string $transaction_id Transaction id.
	 * @return void
	 */
	private function handle_captured( string $transaction_id ): void {
		$transaction = $this->fetch_transaction( $transaction_id );
		if ( ! $transaction ) {
			return;
		}

		$status = (string) ( $transaction->transactionStatus ?? '' );
		if ( ! AuthorizeNetGateway::is_paid_status( $status ) ) {
			return;
		}

		$invoice = $this->resolve_invoice( $transaction, $transaction_id );
		if ( ! $invoice ) {
			doublescale_get_logger()->warning(
				'Authorize.Net webhook — invoice not resolved',
				array(
					'code'           => 'authorize_net_webhook_no_invoice',
					'transaction_id' => $transaction_id,
				)
			);
			return;
		}

		$gateway = GatewayManager::instance()->get(
			GatewayManager::CONTEXT_INVOICE,
			PaymentModeSlugs::authorize_net()
		);
		if ( ! $gateway instanceof AuthorizeNetGateway ) {
			return;
		}

		$gateway->record_paid( new InvoicePayableSubject( $invoice ), $transaction );
	}

	/**
	 * @param string $refund_transaction_id Refund transaction id.
	 * @param object $payload               Webhook payload.
	 * @param string $event_type            Event type.
	 * @return void
	 */
	private function handle_refund( string $refund_transaction_id, $payload, string $event_type ): void {
		$refund = $this->fetch_transaction( $refund_transaction_id );

		// A refund references the original charge it reverses.
		$original_id = $refund
			? (string) ( $refund->refTransId ?? '' )
			: (string) ( $payload->refTransId ?? '' );

		if ( '' === $original_id ) {
			return;
		}

		$payment_row = PaymentModel::query()->where( 'transaction_id', $original_id )->first();
		if ( ! $payment_row || ! $payment_row->invoice_id ) {
			doublescale_get_logger()->warning(
				'Authorize.Net invoice refund webhook — payment row not found',
				array(
					'code'           => 'authorize_net_invoice_refund_no_payment',
					'transaction_id' => $original_id,
				)
			);
			return;
		}

		$invoice = InvoiceModel::find( (int) $payment_row->invoice_id );
		if ( ! $invoice ) {
			return;
		}

		$currency        = strtoupper( (string) $invoice->currency );
		$original_amount = round( (float) $payment_row->amount, 2 );
		$refund_amount   = $refund
			? round( (float) ( $refund->settleAmount ?? $refund->authAmount ?? 0 ), 2 )
			: 0.0;

		// A void always reverses the whole charge.
		$is_void        = 'net.authorize.payment.void.created' === $event_type;
		$is_full_refund = $is_void
			|| $refund_amount <= 0
			|| $refund_amount >= $original_amount - 0.01;
		$remaining      = $is_full_refund ? 0.0 : round( max( 0, $original_amount - $refund_amount ), 2 );

		if ( $remaining <= 0 ) {
			$payment_row->delete();
			if ( $is_full_refund ) {
				$invoice->clear_in_progress_payment_refs();
			}
		} else {
			$refund_note = sprintf(
				/* translators: 1: remaining amount, 2: currency, 3: transaction id */
				__( 'Authorize.Net refund applied. Remaining: %1$s %2$s. Transaction: %3$s', 'doublescale' ),
				$remaining,
				$currency,
				$original_id
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
					__( 'Authorize.Net full refund of %1$s %2$s for invoice %3$s.', 'doublescale' ),
					$refund_amount > 0 ? $refund_amount : $original_amount,
					$currency,
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: invoice number */
					__( 'Authorize.Net partial refund of %1$s %2$s. Remaining payment: %3$s %2$s on invoice %4$s.', 'doublescale' ),
					$refund_amount,
					$currency,
					$remaining,
					(string) $invoice->invoice_number
				)
		);

		doublescale_get_logger()->info(
			'Authorize.Net invoice refund processed',
			array(
				'code'           => 'authorize_net_invoice_refund_processed',
				'invoice_id'     => (int) $invoice->id,
				'transaction_id' => $original_id,
				'is_full_refund' => $is_full_refund,
			)
		);
	}

	/**
	 * @param string $transaction_id Transaction id.
	 * @return object|null
	 */
	private function fetch_transaction( string $transaction_id ) {
		$api = AuthorizeNetIntegration::instance()->connect();
		if ( ! $api instanceof Api ) {
			return null;
		}

		$details = $api->get_transaction_details( $transaction_id );
		if ( ! $details['success'] ) {
			return null;
		}

		$transaction = $details['data']['transaction'] ?? array();
		return empty( $transaction ) ? null : (object) $transaction;
	}

	/**
	 * @param object $transaction    Transaction details.
	 * @param string $transaction_id Transaction id.
	 * @return InvoiceModel|null
	 */
	private function resolve_invoice( $transaction, string $transaction_id ): ?InvoiceModel {
		// Already recorded — the invoice is known.
		$existing = PaymentModel::query()->where( 'transaction_id', $transaction_id )->first();
		if ( $existing && $existing->invoice_id ) {
			return InvoiceModel::find( (int) $existing->invoice_id );
		}

		$order = $transaction->order ?? null;

		// `description` carries invoice_<id>, set when the token was requested.
		$description = is_object( $order )
			? (string) ( $order->description ?? '' )
			: ( is_array( $order ) ? (string) ( $order['description'] ?? '' ) : '' );

		if ( preg_match( '/invoice_(\d+)/', $description, $matches ) ) {
			$invoice = InvoiceModel::find( (int) $matches[1] );
			if ( $invoice ) {
				return $invoice;
			}
		}

		// Fall back to the invoice number, which is the in-progress ref.
		$invoice_number = is_object( $order )
			? (string) ( $order->invoiceNumber ?? '' )
			: ( is_array( $order ) ? (string) ( $order['invoiceNumber'] ?? '' ) : '' );

		if ( '' !== $invoice_number ) {
			$invoice = InvoiceModel::find_by_external_payment_ref( $invoice_number );
			if ( $invoice ) {
				return $invoice;
			}

			$invoice = InvoiceModel::query()->where( 'invoice_number', $invoice_number )->first();
			if ( $invoice ) {
				return $invoice;
			}
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
