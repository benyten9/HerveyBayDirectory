<?php
/**
 * WooCommerce Checkout payment gateway for invoice context.
 *
 * Creates a hidden virtual product + order as a payment vehicle so the
 * customer can pay the invoice balance via any WooCommerce payment method.
 *
 * @package DoubleScale\Pro\Modules\Pro\Payment
 */

namespace DoubleScale\Pro\Modules\Pro\Payment;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\Gateway;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;
use DoubleScale\Pro\Compat\PaymentModeSlugs;

/**
 * WooCommerceGateway class.
 */
class WooCommerceGateway extends Gateway {

	public $name = 'WooCommerce Checkout';

	public $slug = 'woocommerce';

	public $description = 'Pay via the store WooCommerce checkout. Requires at least one enabled WooCommerce payment method.';

	/**
	 * Order meta key linking a WC order to a DoubleScale invoice.
	 */
	public const ORDER_INVOICE_META = '_doublescale_invoice_id';

	/**
	 * Option storing the shared hidden virtual product id.
	 */
	private const PRODUCT_OPTION = 'doublescale_woo_invoice_payment_product_id';

	/**
	 * Query arg appended to the invoice return URL after checkout.
	 */
	public const RETURN_QUERY_ARG = 'ds_woo_return';

	/**
	 * @return void
	 */
	protected function register(): void {
		GatewayManager::instance()->register( GatewayManager::CONTEXT_INVOICE, $this );
	}

	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * WooCommerce hands the customer off to the store checkout.
	 *
	 * @return string
	 */
	public function return_query_arg(): string {
		return self::RETURN_QUERY_ARG;
	}

	/**
	 * @param string $invoice_number Invoice number.
	 * @return string
	 */
	public function payment_note( string $invoice_number ): string {
		return sprintf(
			/* translators: %s: invoice number */
			__( 'WooCommerce payment for invoice %s', 'doublescale' ),
			$invoice_number
		);
	}

	public function is_configured(): bool {
		if ( ! $this->is_available() || ! function_exists( 'WC' ) ) {
			return false;
		}

		$wc = WC();
		if ( ! $wc || ! is_object( $wc->payment_gateways() ) ) {
			return false;
		}

		$gateways = $wc->payment_gateways()->payment_gateways();
		if ( ! is_array( $gateways ) ) {
			return false;
		}

		foreach ( $gateways as $gateway ) {
			if ( is_object( $gateway ) && isset( $gateway->enabled ) && 'yes' === $gateway->enabled ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'WooCommerce Checkout is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $subject instanceof InvoicePayableSubject ) {
			return new WP_Error(
				'invalid_subject',
				__( 'WooCommerce Checkout is only available for invoices.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$amount   = $subject->amount_due();
		$currency = strtoupper( $subject->currency() );

		if ( $amount <= 0 ) {
			return new WP_Error(
				'nothing_due',
				__( 'This invoice has no balance due.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$store_currency = strtoupper( (string) get_woocommerce_currency() );
		$mismatch       = PaymentCurrency::guard( $currency, $store_currency, 'WooCommerce' );
		if ( is_wp_error( $mismatch ) ) {
			return new WP_Error(
				'currency_mismatch',
				sprintf(
					/* translators: 1: invoice currency, 2: store currency */
					__( 'Invoice currency (%1$s) does not match the WooCommerce store currency (%2$s). Change the invoice currency to %2$s, or set WooCommerce → Settings → General → Currency to %1$s.', 'doublescale' ),
					$currency,
					$store_currency
				),
				array( 'status' => 400 )
			);
		}

		try {
			$order = $this->resolve_or_create_order( $subject, $amount, $currency );
			if ( is_wp_error( $order ) ) {
				return $order;
			}

			if ( $order->is_paid() ) {
				$this->record_paid( $subject, $order );
				return $this->shape_already_paid_response( $subject, $order );
			}

			return array(
				'gateway'      => $this->slug,
				'redirect_url' => $order->get_checkout_payment_url(),
				'amount'       => $amount,
				'currency'     => strtolower( $currency ),
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'WooCommerce invoice payment init failed',
				array(
					'code'    => 'woo_invoice_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'woocommerce_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function confirm( PayableSubject $subject ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'WooCommerce is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		$order_id = $subject->external_payment_ref();
		if ( null === $order_id || '' === $order_id || ! ctype_digit( (string) $order_id ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'No WooCommerce order is in progress.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'woocommerce_error',
				__( 'Could not retrieve the WooCommerce order.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$status = $order->get_status();
		if ( $order->is_paid() ) {
			$this->record_paid( $subject, $order );
			$status = 'paid';
		}

		$response = array(
			'gateway' => $this->slug,
			'status'  => $status,
		);

		if ( $subject instanceof InvoicePayableSubject ) {
			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );
		}

		return $response;
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  WC_Order or charge-shaped object.
	 * @return void
	 */
	public function record_paid( PayableSubject $subject, object $charge ): void {
		$normalized = $this->normalize_charge( $charge );
		if ( ! $normalized ) {
			return;
		}
		$subject->record_payment( $normalized );
	}

	/**
	 * @param PayableSubject $subject  Subject.
	 * @param float          $amount   Major units.
	 * @param string         $currency ISO currency (uppercase).
	 * @return \WC_Order|WP_Error
	 */
	private function resolve_or_create_order( InvoicePayableSubject $subject, float $amount, string $currency ) {
		$ref = $subject->external_payment_ref();
		if ( null !== $ref && '' !== $ref && ctype_digit( (string) $ref ) ) {
			$existing = wc_get_order( (int) $ref );
			if ( $existing && $this->order_belongs_to_invoice( $existing, $subject ) ) {
				if ( $existing->is_paid() ) {
					return $existing;
				}
				if ( $existing->needs_payment() ) {
					$this->sync_order_totals( $existing, $subject, $amount, $currency );
					return $existing;
				}
			}
		}

		$product = $this->ensure_payment_product( $amount );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$order = wc_create_order(
			array(
				'status'      => 'pending',
				'customer_id' => 0,
			)
		);

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$invoice = $subject->get_invoice();
		$label   = sprintf(
			/* translators: %s: invoice number */
			__( 'Invoice %s', 'doublescale' ),
			(string) $invoice->invoice_number
		);

		$item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => $amount,
				'total'    => $amount,
			)
		);

		if ( $item_id && ! is_wp_error( $item_id ) ) {
			$item = $order->get_item( $item_id );
			if ( $item ) {
				$item->set_name( $label );
				$item->save();
			}
		}

		$order->set_currency( $currency );
		$order->set_prices_include_tax( true );

		$email = $subject->customer_email();
		$name  = $subject->customer_name();
		if ( $email ) {
			$order->set_billing_email( $email );
		}
		if ( $name ) {
			$parts = preg_split( '/\s+/', $name, 2 );
			$order->set_billing_first_name( $parts[0] ?? $name );
			if ( ! empty( $parts[1] ) ) {
				$order->set_billing_last_name( $parts[1] );
			}
		}

		$order->update_meta_data( self::ORDER_INVOICE_META, (int) $invoice->id );

		$return_url = $this->invoice_return_url( $subject );
		if ( '' !== $return_url ) {
			$order->update_meta_data( '_doublescale_return_url', $return_url );
		}

		$order->calculate_totals( false );
		$order->set_total( $amount );
		$order->save();

		// Non-Stripe refs must not populate stripe_payment_intent_id.
		$subject->get_invoice()->set_in_progress_payment_ref( (string) $order->get_id(), false );

		return $order;
	}

	/**
	 * @param \WC_Order              $order   Existing unpaid order.
	 * @param InvoicePayableSubject  $subject Subject.
	 * @param float                  $amount  Major units.
	 * @param string                 $currency ISO currency.
	 * @return void
	 */
	private function sync_order_totals( $order, InvoicePayableSubject $subject, float $amount, string $currency ): void {
		$invoice = $subject->get_invoice();
		$label   = sprintf(
			/* translators: %s: invoice number */
			__( 'Invoice %s', 'doublescale' ),
			(string) $invoice->invoice_number
		);

		foreach ( $order->get_items() as $item ) {
			if ( is_callable( array( $item, 'set_subtotal' ) ) ) {
				$item->set_subtotal( $amount );
			}
			if ( is_callable( array( $item, 'set_total' ) ) ) {
				$item->set_total( $amount );
			}
			if ( is_callable( array( $item, 'set_name' ) ) ) {
				$item->set_name( $label );
			}
			$item->save();
		}

		$order->set_currency( $currency );
		$order->calculate_totals( false );
		$order->set_total( $amount );
		$order->save();
	}

	/**
	 * @param \WC_Order             $order   Order.
	 * @param InvoicePayableSubject $subject Subject.
	 * @return bool
	 */
	private function order_belongs_to_invoice( $order, InvoicePayableSubject $subject ): bool {
		$meta_id = (int) $order->get_meta( self::ORDER_INVOICE_META );
		return $meta_id === (int) $subject->entity_id();
	}

	/**
	 * @param float $amount Major units (sets product price).
	 * @return \WC_Product|WP_Error
	 */
	private function ensure_payment_product( float $amount ) {
		$product_id = (int) get_option( self::PRODUCT_OPTION, 0 );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
			$product->set_name( __( 'Invoice Payment', 'doublescale' ) );
			$product->set_status( 'private' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( true );
			$product->set_downloadable( false );
			$product->set_manage_stock( false );
			$product->set_sold_individually( true );
			$product->set_reviews_allowed( false );
		}

		$product->set_regular_price( (string) $amount );
		$product->set_price( (string) $amount );
		$product->save();

		update_option( self::PRODUCT_OPTION, (int) $product->get_id(), false );

		return $product;
	}

	/**
	 * @param InvoicePayableSubject $subject Subject.
	 * @return string
	 */
	private function invoice_return_url( InvoicePayableSubject $subject ): string {
		$base = InvoiceUrl::get_public_url( $subject->get_invoice() );
		if ( '' === $base ) {
			return '';
		}
		return add_query_arg( self::RETURN_QUERY_ARG, '1', $base );
	}

	/**
	 * @param object $charge WC_Order or charge-shaped object.
	 * @return object|null
	 */
	private function normalize_charge( object $charge ) {
		if ( isset( $charge->payment_mode ) && PaymentModeSlugs::woocommerce() === (string) $charge->payment_mode ) {
			$txn = (string) ( $charge->transaction_id ?? '' );
			return '' !== $txn ? $charge : null;
		}

		if ( ! $charge instanceof \WC_Order && ! is_callable( array( $charge, 'get_id' ) ) ) {
			return null;
		}

		$order_id = (string) $charge->get_id();
		if ( '' === $order_id || '0' === $order_id ) {
			return null;
		}

		return (object) array(
			'payment_mode'   => PaymentModeSlugs::woocommerce(),
			'transaction_id' => $order_id,
			'id'             => $order_id,
			'amount'         => (float) $charge->get_total(),
			'currency'       => strtolower( (string) $charge->get_currency() ),
		);
	}

	/**
	 * @param PayableSubject $subject Subject.
	 * @param \WC_Order      $order   Paid order.
	 * @return array<string, mixed>
	 */
	private function shape_already_paid_response( PayableSubject $subject, $order ): array {
		$response = array(
			'gateway'      => $this->slug,
			'already_paid' => true,
			'status'       => 'paid',
			'amount'       => (float) $order->get_total(),
			'currency'     => strtolower( (string) $order->get_currency() ),
		);

		if ( $subject instanceof InvoicePayableSubject ) {
			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );
		}

		return $response;
	}
}
