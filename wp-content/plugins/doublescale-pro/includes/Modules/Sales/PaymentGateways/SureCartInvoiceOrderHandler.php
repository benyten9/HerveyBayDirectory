<?php
/**
 * SureCart checkout handler for invoice payments.
 *
 * Authority for marking an invoice paid comes from SureCart checkout hooks
 * (and from Gateway::confirm re-checking the invoice server-side). The browser
 * return URL is a UI cue only.
 *
 * @package DoubleScale\Pro\Modules\Sales\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Sales\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Pro\Modules\Pro\Payment\SureCartGateway;

/**
 * SureCartInvoiceOrderHandler class.
 */
final class SureCartInvoiceOrderHandler {

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
		add_action( 'surecart/checkout_confirmed', array( $this, 'handle_checkout_confirmed' ), 20, 2 );
		add_action( 'surecart/purchase_created', array( $this, 'handle_purchase_created' ), 20, 1 );
	}

	/**
	 * Record invoice payment when a linked SureCart checkout is confirmed.
	 *
	 * @param object $checkout SureCart checkout.
	 * @param mixed  $request  REST request (unused).
	 * @return void
	 */
	public function handle_checkout_confirmed( $checkout, $request = null ): void {
		unset( $request );

		$this->maybe_record_from_checkout( $checkout );
	}

	/**
	 * Fallback when purchase_created fires without checkout_confirmed in the same request.
	 *
	 * @param object $purchase SureCart purchase.
	 * @return void
	 */
	public function handle_purchase_created( $purchase ): void {
		if ( ! is_object( $purchase ) || empty( $purchase->initial_order ) ) {
			return;
		}

		if ( ! class_exists( '\SureCart\Models\Order' ) ) {
			return;
		}

		$order = \SureCart\Models\Order::with( array( 'checkout' ) )->find( (string) $purchase->initial_order );
		if ( ! $order || is_wp_error( $order ) || empty( $order->checkout ) ) {
			return;
		}

		$this->maybe_record_from_checkout( $order->checkout );
	}

	/**
	 * @param object $checkout SureCart checkout.
	 * @return void
	 */
	private function maybe_record_from_checkout( $checkout ): void {
		if ( ! is_object( $checkout ) ) {
			return;
		}

		$gateway = GatewayManager::instance()->get( GatewayManager::CONTEXT_INVOICE, 'surecart' );
		if ( ! $gateway instanceof SureCartGateway ) {
			return;
		}

		if ( ! $this->checkout_is_paid( $checkout ) ) {
			return;
		}

		$invoice_id = $this->resolve_doublescale_invoice_id( $checkout );
		if ( $invoice_id <= 0 ) {
			return;
		}

		$invoice = InvoiceModel::find( $invoice_id );
		if ( ! $invoice ) {
			return;
		}

		$sc_invoice = $this->resolve_surecart_invoice( $checkout );
		if ( ! $sc_invoice ) {
			$sc_invoice = (object) array(
				'id'       => (string) ( $checkout->invoice ?? $checkout->id ?? '' ),
				'checkout' => $checkout,
				'status'   => (string) ( $checkout->status ?? 'paid' ),
			);
		}

		$gateway->record_paid( new InvoicePayableSubject( $invoice ), $sc_invoice );
	}

	/**
	 * @param object $checkout SureCart checkout.
	 * @return int
	 */
	private function resolve_doublescale_invoice_id( $checkout ): int {
		$metadata = $checkout->metadata ?? null;
		if ( is_object( $metadata ) && isset( $metadata->{SureCartGateway::CHECKOUT_META_INVOICE_ID} ) ) {
			return (int) $metadata->{SureCartGateway::CHECKOUT_META_INVOICE_ID};
		}
		if ( is_array( $metadata ) && isset( $metadata[ SureCartGateway::CHECKOUT_META_INVOICE_ID ] ) ) {
			return (int) $metadata[ SureCartGateway::CHECKOUT_META_INVOICE_ID ];
		}

		$ref = '';
		if ( ! empty( $checkout->invoice ) ) {
			$ref = is_object( $checkout->invoice ) ? (string) ( $checkout->invoice->id ?? '' ) : (string) $checkout->invoice;
		}

		if ( '' === $ref ) {
			return 0;
		}

		$invoice = InvoiceModel::find_by_external_payment_ref( $ref );
		return $invoice ? (int) $invoice->id : 0;
	}

	/**
	 * @param object $checkout SureCart checkout.
	 * @return object|null
	 */
	private function resolve_surecart_invoice( $checkout ) {
		if ( ! class_exists( '\SureCart\Models\Invoice' ) ) {
			return null;
		}

		$invoice_id = '';
		if ( ! empty( $checkout->invoice ) ) {
			$invoice_id = is_object( $checkout->invoice ) ? (string) ( $checkout->invoice->id ?? '' ) : (string) $checkout->invoice;
		}

		if ( '' === $invoice_id ) {
			return null;
		}

		$sc_invoice = \SureCart\Models\Invoice::with( array( 'checkout' ) )->find( $invoice_id );
		if ( ! $sc_invoice || is_wp_error( $sc_invoice ) ) {
			return null;
		}

		return $sc_invoice;
	}

	/**
	 * @param object $checkout SureCart checkout.
	 * @return bool
	 */
	private function checkout_is_paid( $checkout ): bool {
		if ( 'paid' === (string) ( $checkout->status ?? '' ) ) {
			return true;
		}

		return ! empty( $checkout->paid_at );
	}
}
