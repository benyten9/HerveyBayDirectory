<?php
/**
 * WooCommerce order status handler for invoice payments.
 *
 * Authority for marking an invoice paid comes from the order status hooks
 * (and from Gateway::confirm re-checking the order server-side). The browser
 * return URL is a UI cue only.
 *
 * @package DoubleScale\Pro\Modules\Sales\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Sales\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Modules\Documents\Services\InvoicePayments;
use DoubleScale\Pro\Modules\Pro\Payment\WooCommerceGateway;

/**
 * WooInvoiceOrderHandler class.
 */
final class WooInvoiceOrderHandler {

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
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_paid' ), 20, 1 );

		// Fires after both partial and full refunds, once the parent order status
		// has already been updated. WooCommerce allows several refunds per order,
		// so the handler reads the cumulative total rather than this refund alone.
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refunded' ), 20, 1 );

		add_filter( 'woocommerce_get_return_url', array( $this, 'filter_return_url' ), 20, 2 );
	}

	/**
	 * Record invoice payment when a linked WooCommerce order is paid.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function handle_order_paid( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		$invoice_id = (int) $order->get_meta( WooCommerceGateway::ORDER_INVOICE_META );
		if ( $invoice_id <= 0 ) {
			return;
		}

		$invoice = InvoiceModel::find( $invoice_id );
		if ( ! $invoice ) {
			return;
		}

		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_INVOICE, 'woocommerce' );
		if ( ! $gateway instanceof WooCommerceGateway ) {
			return;
		}

		$gateway->record_paid( new InvoicePayableSubject( $invoice ), $order );
	}

	/**
	 * Reverse the recorded invoice payment when a linked order is refunded.
	 *
	 * Mirrors the Stripe refund flow: a full refund removes the payment row, a
	 * partial refund reduces it to the amount still held, and InvoicePayments
	 * re-derives the invoice status from the resulting totals.
	 *
	 * WooCommerce reports money in major units, so no minor-unit conversion is
	 * applied here — that is a Stripe-only concern.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function handle_order_refunded( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$invoice_id = (int) $order->get_meta( WooCommerceGateway::ORDER_INVOICE_META );
		if ( $invoice_id <= 0 ) {
			return;
		}

		$invoice = InvoiceModel::find( $invoice_id );
		if ( ! $invoice ) {
			return;
		}

		// Resolve the exact payment this order created: record_payment() stores the
		// WooCommerce order id as the transaction_id, so the mapping is
		// order id <-> payment row, not "the invoice's only payment". An invoice
		// may legitimately carry several rows (a second WooCommerce order, a
		// Stripe charge, a manual bank transfer) and each refund must only ever
		// touch its own. Deliberately no fallback to "latest payment" — refunding
		// the wrong row is worse than skipping and logging.
		$payment = PaymentModel::query()
			->where( 'invoice_id', $invoice_id )
			->where( 'transaction_id', (string) $order_id )
			->first();

		if ( ! $payment ) {
			doublescale_get_logger()->warning(
				'WooCommerce invoice refund — payment row not found',
				array(
					'code'       => 'woo_invoice_refund_no_payment',
					'invoice_id' => $invoice_id,
					'order_id'   => $order_id,
				)
			);
			return;
		}

		// Cumulative across every refund on this order, not just the latest one.
		$refunded = round( (float) $order->get_total_refunded(), 2 );
		if ( $refunded <= 0 ) {
			return;
		}

		// Subtract from the amount originally charged, never from the payment row:
		// the row already shrinks with each partial refund, and `$refunded` is
		// cumulative, so using it here would double-count on the second refund.
		// The order total is the charged amount (a part-paid invoice is charged
		// only its outstanding balance).
		$charged   = round( (float) $order->get_total(), 2 );
		$remaining = round( $charged - $refunded, 2 );
		$currency  = strtoupper( (string) $order->get_currency() );

		if ( $remaining > 0 ) {
			$payment->amount = $remaining;
			$payment->note   = trim(
				(string) ( $payment->note ?? '' ) . ' ' . sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: order id */
					__( 'WooCommerce refund applied: %1$s %2$s. Remaining: %3$s %2$s. Order: %4$s', 'doublescale' ),
					$refunded,
					$currency,
					$remaining,
					$order_id
				)
			);
			$payment->save();
		} else {
			$payment->delete();
			$invoice->clear_in_progress_payment_refs();
		}

		( new InvoicePayments() )->sync( $invoice->fresh() );

		$this->log_invoice_activity(
			$invoice,
			$remaining > 0
				? sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: remaining amount, 4: invoice number */
					__( 'WooCommerce partial refund of %1$s %2$s. Remaining payment: %3$s %2$s on invoice %4$s.', 'doublescale' ),
					$refunded,
					$currency,
					$remaining,
					(string) $invoice->invoice_number
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: currency, 3: invoice number */
					__( 'WooCommerce full refund of %1$s %2$s for invoice %3$s.', 'doublescale' ),
					$refunded,
					$currency,
					(string) $invoice->invoice_number
				)
		);

		doublescale_get_logger()->info(
			'WooCommerce invoice refund processed',
			array(
				'code'           => 'woo_invoice_refund_processed',
				'invoice_id'     => $invoice_id,
				'order_id'       => $order_id,
				'refunded'       => $refunded,
				'remaining'      => max( 0, $remaining ),
				'is_full_refund' => $remaining <= 0,
			)
		);
	}

	/**
	 * Record a system activity against the invoice's contact.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $note    Human-readable note.
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
	 * Send the customer back to the public invoice page after checkout.
	 *
	 * @param string         $return_url Default WC return URL.
	 * @param \WC_Order|null $order      Order.
	 * @return string
	 */
	public function filter_return_url( $return_url, $order = null ): string {
		if ( ! $order || ! is_callable( array( $order, 'get_meta' ) ) ) {
			return (string) $return_url;
		}

		$custom = (string) $order->get_meta( '_doublescale_return_url' );
		if ( '' === $custom ) {
			return (string) $return_url;
		}

		return $custom;
	}
}
