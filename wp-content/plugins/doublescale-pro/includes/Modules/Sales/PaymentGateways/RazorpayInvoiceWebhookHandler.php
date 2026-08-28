<?php
/**
 * Razorpay invoice webhook handler.
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
use DoubleScale\Pro\Modules\Integrations\Razorpay\Api;
use DoubleScale\Pro\Modules\Pro\Payment\RazorpayGateway;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * RazorpayInvoiceWebhookHandler class.
 */
final class RazorpayInvoiceWebhookHandler {

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
		add_action( 'doublescale_razorpay_invoice_event', array( $this, 'handle_webhook_event' ), 10, 1 );
	}

	/**
	 * Razorpay webhook events for invoice online payments.
	 *
	 * @return string[]
	 */
	public static function default_webhook_events(): array {
		return array(
			'payment_link.paid',
			'payment.captured',
			'payment.failed',
			'refund.processed',
			'refund.created',
		);
	}

	/**
	 * @param object $event Razorpay webhook event.
	 * @return void
	 */
	public function handle_webhook_event( $event ): void {
		$type = (string) ( $event->event ?? '' );

		switch ( $type ) {
			case 'payment_link.paid':
				$this->handle_link_paid( $event );
				break;
			case 'payment.captured':
				$this->handle_payment_captured( $event );
				break;
			case 'payment.failed':
				$this->handle_payment_failed( $event );
				break;
			case 'refund.processed':
			case 'refund.created':
				$this->handle_refund( $event );
				break;
			default:
				doublescale_get_logger()->info(
					'Razorpay invoice webhook ignored',
					array(
						'code'  => 'razorpay_invoice_webhook_ignored',
						'event' => $type,
					)
				);
		}
	}

	/**
	 * `payment_link.paid` carries both the link and the payment entity.
	 *
	 * @param object $event Webhook event.
	 * @return void
	 */
	private function handle_link_paid( $event ): void {
		$link    = $this->entity( $event, 'payment_link' );
		$payment = $this->entity( $event, 'payment' );

		if ( ! $link && ! $payment ) {
			return;
		}

		$invoice = $this->resolve_invoice( $link, $payment );
		if ( ! $invoice ) {
			$this->log_unresolved( 'payment_link.paid', $link, $payment );
			return;
		}

		if ( ! $payment ) {
			return;
		}

		$this->record( $invoice, $payment );
	}

	/**
	 * @param object $event Webhook event.
	 * @return void
	 */
	private function handle_payment_captured( $event ): void {
		$payment = $this->entity( $event, 'payment' );
		if ( ! $payment ) {
			return;
		}

		$invoice = $this->resolve_invoice( null, $payment );
		if ( ! $invoice ) {
			$this->log_unresolved( 'payment.captured', null, $payment );
			return;
		}

		$this->record( $invoice, $payment );
	}

	/**
	 * @param object $event Webhook event.
	 * @return void
	 */
	private function handle_payment_failed( $event ): void {
		$payment = $this->entity( $event, 'payment' );
		if ( ! $payment ) {
			return;
		}

		$invoice = $this->resolve_invoice( null, $payment );
		if ( ! $invoice ) {
			return;
		}

		if ( (float) $invoice->amount_paid > 0 ) {
			return;
		}

		$description = (string) ( $payment->error_description ?? $payment->error_reason ?? '' );

		$this->log_invoice_activity(
			$invoice,
			'' !== $description
				? sprintf(
					/* translators: 1: failure reason, 2: invoice number */
					__( 'Razorpay payment failed for invoice %2$s — %1$s', 'doublescale' ),
					$description,
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: %s: invoice number */
					__( 'Razorpay payment failed for invoice %s.', 'doublescale' ),
					(string) $invoice->invoice_number
				)
		);
	}

	/**
	 * @param object $event Webhook event.
	 * @return void
	 */
	private function handle_refund( $event ): void {
		$refund = $this->entity( $event, 'refund' );
		if ( ! $refund ) {
			return;
		}

		$payment_id = (string) ( $refund->payment_id ?? '' );
		if ( '' === $payment_id ) {
			return;
		}

		$payment_row = PaymentModel::query()->where( 'transaction_id', $payment_id )->first();
		if ( ! $payment_row || ! $payment_row->invoice_id ) {
			doublescale_get_logger()->warning(
				'Razorpay invoice refund webhook — payment row not found',
				array(
					'code'       => 'razorpay_invoice_refund_no_payment',
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
		$refund_amount   = Api::from_minor_units( (int) ( $refund->amount ?? 0 ), $currency );
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
				__( 'Razorpay refund applied. Remaining: %1$s %2$s. Payment: %3$s', 'doublescale' ),
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
					__( 'Razorpay full refund of %1$s %2$s for invoice %3$s.', 'doublescale' ),
					$refund_amount > 0 ? $refund_amount : $original_amount,
					$currency,
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: invoice number */
					__( 'Razorpay partial refund of %1$s %2$s. Remaining payment: %3$s %2$s on invoice %4$s.', 'doublescale' ),
					$refund_amount,
					$currency,
					$remaining,
					(string) $invoice->invoice_number
				)
		);

		doublescale_get_logger()->info(
			'Razorpay invoice refund processed',
			array(
				'code'           => 'razorpay_invoice_refund_processed',
				'invoice_id'     => (int) $invoice->id,
				'payment_id'     => $payment_id,
				'is_full_refund' => $is_full_refund,
			)
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param object       $payment Razorpay payment entity.
	 * @return void
	 */
	private function record( InvoiceModel $invoice, $payment ): void {
		$status = strtolower( (string) ( $payment->status ?? '' ) );
		if ( '' !== $status && ! in_array( $status, array( 'captured', 'authorized' ), true ) ) {
			return;
		}

		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_INVOICE, PaymentModeSlugs::razorpay() );
		if ( ! $gateway instanceof RazorpayGateway ) {
			return;
		}

		$gateway->record_paid( new InvoicePayableSubject( $invoice ), $payment );
	}

	/**
	 * Razorpay nests entities as payload.<name>.entity.
	 *
	 * @param object $event Webhook event.
	 * @param string $name  Entity name.
	 * @return object|null
	 */
	private function entity( $event, string $name ) {
		$payload = $event->payload ?? null;
		if ( ! $payload ) {
			return null;
		}

		$wrapper = is_object( $payload )
			? ( $payload->{$name} ?? null )
			: ( is_array( $payload ) ? ( $payload[ $name ] ?? null ) : null );

		if ( ! $wrapper ) {
			return null;
		}

		$entity = is_object( $wrapper )
			? ( $wrapper->entity ?? null )
			: ( is_array( $wrapper ) ? ( $wrapper['entity'] ?? null ) : null );

		if ( is_array( $entity ) ) {
			return (object) $entity;
		}

		return is_object( $entity ) ? $entity : null;
	}

	/**
	 * Resolve the invoice from notes, the stored link ref, or a recorded payment.
	 *
	 * @param object|null $link    Payment link entity.
	 * @param object|null $payment Payment entity.
	 * @return InvoiceModel|null
	 */
	private function resolve_invoice( $link, $payment ): ?InvoiceModel {
		// A payment already recorded gives us the invoice directly.
		$payment_id = (string) ( $payment->id ?? '' );
		if ( '' !== $payment_id ) {
			$existing = PaymentModel::query()->where( 'transaction_id', $payment_id )->first();
			if ( $existing && $existing->invoice_id ) {
				return InvoiceModel::find( (int) $existing->invoice_id );
			}
		}

		foreach ( array( $link, $payment ) as $entity ) {
			$invoice_id = $this->invoice_id_from_notes( $entity );
			if ( $invoice_id > 0 ) {
				$invoice = InvoiceModel::find( $invoice_id );
				if ( $invoice ) {
					return $invoice;
				}
			}
		}

		// The invoice stores the payment-link id as its in-progress ref.
		$link_id = (string) ( $link->id ?? $payment->payment_link_id ?? '' );
		if ( '' !== $link_id ) {
			$invoice = InvoiceModel::find_by_external_payment_ref( $link_id );
			if ( $invoice ) {
				return $invoice;
			}
		}

		return null;
	}

	/**
	 * @param object|null $entity Entity carrying `notes`.
	 * @return int
	 */
	private function invoice_id_from_notes( $entity ): int {
		if ( ! $entity ) {
			return 0;
		}

		$notes = $entity->notes ?? null;
		if ( is_object( $notes ) ) {
			return (int) ( $notes->invoice_id ?? 0 );
		}
		if ( is_array( $notes ) ) {
			return (int) ( $notes['invoice_id'] ?? 0 );
		}

		return 0;
	}

	/**
	 * @param string      $event   Event type.
	 * @param object|null $link    Payment link entity.
	 * @param object|null $payment Payment entity.
	 * @return void
	 */
	private function log_unresolved( string $event, $link, $payment ): void {
		doublescale_get_logger()->warning(
			'Razorpay webhook — invoice not resolved',
			array(
				'code'       => 'razorpay_webhook_no_invoice',
				'event'      => $event,
				'link_id'    => (string) ( $link->id ?? '' ),
				'payment_id' => (string) ( $payment->id ?? '' ),
			)
		);
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
