<?php
/**
 * SureCart Checkout payment gateway for invoice context.
 *
 * Creates a SureCart invoice (hosted checkout) as a payment vehicle so the
 * customer can pay the DoubleScale invoice balance via SureCart.
 *
 * @package DoubleScale\Pro\Modules\Pro\Payment
 */

namespace DoubleScale\Pro\Modules\Pro\Payment;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Payment\Gateway;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Core\Payment\PayableSubject;
use DoubleScale\Modules\Documents\Rest\InvoiceShaper;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Pro\Compat\PaymentModeSlugs;
use DoubleScale\Pro\Modules\Sales\PaymentGateways\InvoicePayableSubject;
use WP_Error;

/**
 * SureCartGateway class.
 */
class SureCartGateway extends Gateway {

	public $name = 'SureCart Checkout';

	public $slug = 'surecart';

	public $description = 'Pay via SureCart hosted checkout. Requires a connected SureCart store.';

	/**
	 * Checkout metadata key linking a SureCart checkout to a DoubleScale invoice.
	 */
	public const CHECKOUT_META_INVOICE_ID = 'doublescale_invoice_id';

	/**
	 * Query arg appended to the invoice return URL after checkout.
	 */
	public const RETURN_QUERY_ARG = 'ds_surecart_return';

	/**
	 * Option storing the shared hidden product id.
	 */
	private const PRODUCT_OPTION = 'doublescale_surecart_invoice_payment_product_id';

	/**
	 * Option storing ad-hoc price ids keyed by lowercase currency code.
	 */
	private const PRICES_OPTION = 'doublescale_surecart_invoice_payment_prices';

	/**
	 * @return void
	 */
	protected function register(): void {
		GatewayManager::instance()->register( GatewayManager::CONTEXT_INVOICE, $this );
	}

	public function is_available(): bool {
		return defined( 'SURECART_PLUGIN_FILE' )
			&& class_exists( '\SureCart\Models\Invoice' );
	}

	/**
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
			__( 'SureCart payment for invoice %s', 'doublescale' ),
			$invoice_number
		);
	}

	public function is_configured(): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		// SureCart resolves account() via __callStatic, so method_exists( '\SureCart', 'account' )
		// is always false even on a connected store. ApiToken is the same signal SureCart's
		// own health check uses.
		$connected = false;
		if ( class_exists( '\SureCart\Support\ApiToken' ) ) {
			$connected = (bool) \SureCart\Support\ApiToken::get();
		} elseif ( class_exists( '\SureCart' ) && is_callable( array( '\SureCart', 'account' ) ) ) {
			try {
				$account     = \SureCart::account();
				$connected = $account && method_exists( $account, 'isConnected' ) && $account->isConnected();
			} catch ( \Throwable $e ) {
				$connected = false;
			}
		}

		if ( ! $connected ) {
			return false;
		}

		// A connected store still cannot take payments without an enabled processor in the
		// mode the checkout will use (live Stripe vs test/mock on local installs).
		return $this->has_enabled_processor( $this->resolve_live_mode() );
	}

	/**
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( PayableSubject $subject ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'SureCart Checkout is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $subject instanceof InvoicePayableSubject ) {
			return new WP_Error(
				'invalid_subject',
				__( 'SureCart Checkout is only available for invoices.', 'doublescale' ),
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

		$price = $this->ensure_payment_price( $currency );
		if ( is_wp_error( $price ) ) {
			return $price;
		}

		$price_currency = strtoupper( (string) ( $price->currency ?? $currency ) );
		$mismatch       = PaymentCurrency::guard( $currency, $price_currency, 'SureCart' );
		if ( is_wp_error( $mismatch ) ) {
			return new WP_Error(
				'currency_mismatch',
				sprintf(
					/* translators: 1: invoice currency, 2: SureCart price currency */
					__( 'Invoice currency (%1$s) does not match the SureCart price currency (%2$s). Add a SureCart price in %1$s or change the invoice currency.', 'doublescale' ),
					$currency,
					$price_currency
				),
				array( 'status' => 400 )
			);
		}

		try {
			$this->ensure_test_checkout_allowed();
			$sc_invoice = $this->resolve_or_create_surecart_invoice( $subject, $amount, $currency, (string) $price->id );
			if ( is_wp_error( $sc_invoice ) ) {
				return $sc_invoice;
			}

			if ( $this->surecart_invoice_is_paid( $sc_invoice ) ) {
				$this->record_paid( $subject, $sc_invoice );
				return $this->shape_already_paid_response( $subject, $sc_invoice );
			}

			$redirect = $this->checkout_url_for_invoice( $sc_invoice );
			if ( '' === $redirect ) {
				return new WP_Error(
					'surecart_error',
					__( 'Could not resolve the SureCart checkout URL.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'gateway'      => $this->slug,
				'redirect_url' => $redirect,
				'amount'       => $amount,
				'currency'     => strtolower( $currency ),
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'SureCart invoice payment init failed',
				array(
					'code'    => 'surecart_invoice_payment_init_failed',
					'context' => $subject->context(),
					'message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'surecart_error', $e->getMessage(), array( 'status' => 500 ) );
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
				__( 'SureCart is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		$ref = $subject->external_payment_ref();
		if ( null === $ref || '' === $ref ) {
			return new WP_Error(
				'invalid_data',
				__( 'No SureCart invoice is in progress.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$sc_invoice = \SureCart\Models\Invoice::with( array( 'checkout' ) )->find( (string) $ref );
		if ( ! $sc_invoice || is_wp_error( $sc_invoice ) ) {
			return new WP_Error(
				'surecart_error',
				__( 'Could not retrieve the SureCart invoice.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$status = (string) ( $sc_invoice->status ?? '' );
		if ( $this->surecart_invoice_is_paid( $sc_invoice ) ) {
			$this->record_paid( $subject, $sc_invoice );
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
	 * @param object         $charge  SureCart invoice/checkout or charge-shaped object.
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
	 * @param InvoicePayableSubject $subject   Subject.
	 * @param float                 $amount    Major units.
	 * @param string                $currency  ISO currency (uppercase).
	 * @param string                $price_id  SureCart ad-hoc price id.
	 * @return object|WP_Error
	 */
	private function resolve_or_create_surecart_invoice( InvoicePayableSubject $subject, float $amount, string $currency, string $price_id ) {
		$ref = $subject->external_payment_ref();
		if ( null !== $ref && '' !== $ref ) {
			$existing = \SureCart\Models\Invoice::with(
				array(
					'checkout',
					'checkout.line_items',
				)
			)->find( (string) $ref );
			if ( $existing && ! is_wp_error( $existing ) && $this->surecart_invoice_belongs_to_invoice( $existing, $subject ) ) {
				if ( $this->surecart_invoice_is_paid( $existing ) ) {
					return $existing;
				}
				if (
					'open' === (string) ( $existing->status ?? '' )
					&& $this->checkout_matches_payable_mode( $existing )
					&& $this->checkout_amount_matches( $existing, $amount )
				) {
					$this->sync_surecart_invoice_totals( $existing, $subject, $amount, $currency, $price_id );
					return $existing;
				}
			}
		}

		return $this->create_surecart_invoice( $subject, $amount, $currency, $price_id );
	}

	/**
	 * @param InvoicePayableSubject $subject  Subject.
	 * @param float                 $amount   Major units.
	 * @param string                $currency ISO currency.
	 * @param string                $price_id SureCart price id.
	 * @return object|WP_Error
	 */
	private function create_surecart_invoice( InvoicePayableSubject $subject, float $amount, string $currency, string $price_id ) {
		$invoice = $subject->get_invoice();
		$minor   = $this->to_minor_units( $amount );

		$sc_invoice = \SureCart\Models\Invoice::create(
			array(
				'live_mode' => $this->resolve_live_mode() ? 'true' : 'false',
			)
		);
		if ( is_wp_error( $sc_invoice ) ) {
			return $sc_invoice;
		}

		$checkout_id = $this->resolve_checkout_id( $sc_invoice );
		if ( '' === $checkout_id ) {
			return new WP_Error(
				'surecart_error',
				__( 'SureCart invoice was created but no checkout was found.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$customer = $this->resolve_customer( $subject, $this->resolve_live_mode() );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$return_url = $this->invoice_return_url( $subject );
		$metadata   = array(
			self::CHECKOUT_META_INVOICE_ID => (string) $invoice->id,
			'doublescale_invoice_number'   => (string) $invoice->invoice_number,
			'doublescale_invoice_hash'     => (string) $invoice->hash,
		);
		if ( '' !== $return_url ) {
			$metadata['doublescale_return_url'] = $return_url;
		}

		$checkout_update = array(
			'id'          => $checkout_id,
			'customer_id' => (string) $customer->id,
			'metadata'    => $metadata,
		);
		if ( '' !== $return_url ) {
			$checkout_update['return_url'] = $return_url;
		}

		$checkout = \SureCart\Models\Checkout::with(
			array(
				'customer',
				'customer.shipping_address',
			)
		)->update( $checkout_update );

		if ( is_wp_error( $checkout ) ) {
			return $checkout;
		}

		if ( ! empty( $checkout->customer->shipping_address ) ) {
			$shipping_address = $checkout->customer->shipping_address;
			$address_data     = is_object( $shipping_address ) ? (array) $shipping_address : $shipping_address;

			$checkout = \SureCart\Models\Checkout::update(
				array(
					'id'               => $checkout_id,
					'customer_id'      => (string) $customer->id,
					'shipping_address' => $address_data,
				)
			);

			if ( is_wp_error( $checkout ) ) {
				return $checkout;
			}
		}

		$line_item = \SureCart\Models\LineItem::create(
			array(
				'checkout'      => $checkout_id,
				'price'         => $price_id,
				'quantity'      => 1,
				'ad_hoc_amount' => $minor,
			)
		);

		if ( is_wp_error( $line_item ) ) {
			return $line_item;
		}

		$memo = sprintf(
			/* translators: %s: invoice number */
			__( 'Invoice %s', 'doublescale' ),
			(string) $invoice->invoice_number
		);

		$updated = \SureCart\Models\Invoice::update(
			array(
				'id'                      => (string) $sc_invoice->id,
				'due_date'                => ( new \DateTime( 'now' ) )->modify( '+30 days' )->getTimestamp(),
				'memo'                    => $memo,
				'notifications_enabled'   => false,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$opened = \SureCart\Models\Invoice::with(
			array(
				'checkout',
				'checkout.line_items',
			)
		)->open( (string) $sc_invoice->id );

		if ( is_wp_error( $opened ) ) {
			return $opened;
		}

		$subject->get_invoice()->set_in_progress_payment_ref( (string) $opened->id, false );

		return $opened;
	}

	/**
	 * @param object                $sc_invoice SureCart invoice.
	 * @param InvoicePayableSubject $subject    Subject.
	 * @param float                 $amount     Major units.
	 * @param string                $currency   ISO currency.
	 * @param string                $price_id   SureCart price id.
	 * @return void
	 */
	private function sync_surecart_invoice_totals( $sc_invoice, InvoicePayableSubject $subject, float $amount, string $currency, string $price_id ): void {
		unset( $currency );

		$checkout_id = $this->resolve_checkout_id( $sc_invoice );
		if ( '' === $checkout_id ) {
			return;
		}

		$minor     = $this->to_minor_units( $amount );
		$line_item = $this->resolve_primary_line_item( $sc_invoice, $checkout_id );

		if ( null !== $line_item && ! empty( $line_item->id ) ) {
			\SureCart\Models\LineItem::update(
				array(
					'id'            => (string) $line_item->id,
					'ad_hoc_amount' => $minor,
				)
			);
		} else {
			\SureCart\Models\LineItem::create(
				array(
					'checkout'      => $checkout_id,
					'price'         => $price_id,
					'quantity'      => 1,
					'ad_hoc_amount' => $minor,
				)
			);
		}

		$invoice = $subject->get_invoice();
		$memo    = sprintf(
			/* translators: %s: invoice number */
			__( 'Invoice %s', 'doublescale' ),
			(string) $invoice->invoice_number
		);

		\SureCart\Models\Invoice::update(
			array(
				'id'   => (string) $sc_invoice->id,
				'memo' => $memo,
			)
		);
	}

	/**
	 * @param object                $sc_invoice SureCart invoice.
	 * @param InvoicePayableSubject $subject    Subject.
	 * @return bool
	 */
	private function surecart_invoice_belongs_to_invoice( $sc_invoice, InvoicePayableSubject $subject ): bool {
		$checkout = $sc_invoice->checkout ?? null;
		if ( $checkout && isset( $checkout->metadata ) ) {
			$meta_id = 0;
			if ( is_object( $checkout->metadata ) && isset( $checkout->metadata->{self::CHECKOUT_META_INVOICE_ID} ) ) {
				$meta_id = (int) $checkout->metadata->{self::CHECKOUT_META_INVOICE_ID};
			} elseif ( is_array( $checkout->metadata ) && isset( $checkout->metadata[ self::CHECKOUT_META_INVOICE_ID ] ) ) {
				$meta_id = (int) $checkout->metadata[ self::CHECKOUT_META_INVOICE_ID ];
			}
			if ( $meta_id > 0 ) {
				return $meta_id === (int) $subject->entity_id();
			}
		}

		return (string) ( $sc_invoice->id ?? '' ) === (string) $subject->external_payment_ref();
	}

	/**
	 * @param object $sc_invoice SureCart invoice.
	 * @return bool
	 */
	public function surecart_invoice_is_paid( $sc_invoice ): bool {
		if ( 'paid' === (string) ( $sc_invoice->status ?? '' ) ) {
			return true;
		}

		$checkout = $sc_invoice->checkout ?? null;
		if ( ! $checkout ) {
			return false;
		}

		if ( 'paid' === (string) ( $checkout->status ?? '' ) ) {
			return true;
		}

		return ! empty( $checkout->paid_at );
	}

	/**
	 * @param object $sc_invoice SureCart invoice.
	 * @return string
	 */
	private function checkout_url_for_invoice( $sc_invoice ): string {
		$url = '';
		if ( ! empty( $sc_invoice->checkout_url ) ) {
			$url = (string) $sc_invoice->checkout_url;
		} else {
			$checkout_id = $this->resolve_checkout_id( $sc_invoice );
			if ( '' !== $checkout_id && class_exists( '\SureCart' ) && is_callable( array( '\SureCart', 'pages' ) ) ) {
				$url = (string) add_query_arg( 'checkout_id', $checkout_id, \SureCart::pages()->url( 'checkout' ) );
			}
		}

		if ( '' === $url ) {
			return '';
		}

		// SureCart checkout pages default to live mode unless told otherwise. When the
		// invoice was created in test mode (mock processor on local installs), the URL
		// must carry live_mode=false or payment will spin forever with no processor.
		if ( ! $this->invoice_is_live_mode( $sc_invoice ) ) {
			$url = add_query_arg( 'live_mode', 'false', $url );
		}

		return $url;
	}

	/**
	 * @param object $sc_invoice SureCart invoice.
	 * @return string
	 */
	private function resolve_checkout_id( $sc_invoice ): string {
		$checkout = $sc_invoice->checkout ?? null;
		if ( is_string( $checkout ) && '' !== $checkout ) {
			return $checkout;
		}
		if ( is_object( $checkout ) && ! empty( $checkout->id ) ) {
			return (string) $checkout->id;
		}
		if ( ! empty( $sc_invoice->checkout_id ) ) {
			return (string) $sc_invoice->checkout_id;
		}
		return '';
	}

	/**
	 * @param InvoicePayableSubject $subject   Subject.
	 * @param bool                  $live_mode Whether the SureCart checkout is live or test.
	 * @return object|WP_Error
	 */
	private function resolve_customer( InvoicePayableSubject $subject, bool $live_mode ) {
		$email = $subject->customer_email();
		if ( ! $email ) {
			return new WP_Error(
				'missing_customer',
				__( 'A customer email is required to start SureCart checkout.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$customer = \SureCart\Models\Customer::where(
			array(
				'email'     => $email,
				'live_mode' => $live_mode,
			)
		)->first();
		if ( $customer && ! is_wp_error( $customer ) && ! empty( $customer->id ) ) {
			return $customer;
		}

		$name  = $subject->customer_name() ?? '';
		$parts = preg_split( '/\s+/', trim( $name ), 2 );
		$data  = array(
			'email'     => $email,
			'name'      => $name,
			'live_mode' => $live_mode,
		);
		if ( ! empty( $parts[0] ) ) {
			$data['first_name'] = $parts[0];
		}
		if ( ! empty( $parts[1] ) ) {
			$data['last_name'] = $parts[1];
		}

		$created = \SureCart\Models\Customer::create( $data, false );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return $created;
	}

	/**
	 * @param string $currency Uppercase ISO currency.
	 * @return object|WP_Error
	 */
	private function ensure_payment_price( string $currency ) {
		$currency = strtolower( $currency );
		$prices   = get_option( self::PRICES_OPTION, array() );
		if ( ! is_array( $prices ) ) {
			$prices = array();
		}

		if ( ! empty( $prices[ $currency ] ) ) {
			$price = \SureCart\Models\Price::find( (string) $prices[ $currency ] );
			if ( $price && ! is_wp_error( $price ) && empty( $price->archived ) ) {
				return $price;
			}
		}

		$product = $this->ensure_payment_product();
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$price = \SureCart\Models\Price::create(
			array(
				'product'  => (string) $product->id,
				'currency' => $currency,
				'ad_hoc'   => true,
				'amount'   => 0,
				'name'     => __( 'Invoice Payment', 'doublescale' ),
			)
		);

		if ( is_wp_error( $price ) ) {
			return $price;
		}

		$prices[ $currency ] = (string) $price->id;
		update_option( self::PRICES_OPTION, $prices, false );

		return $price;
	}

	/**
	 * @return object|WP_Error
	 */
	private function ensure_payment_product() {
		$product_id = (int) get_option( self::PRODUCT_OPTION, 0 );
		if ( $product_id > 0 ) {
			$product = \SureCart\Models\Product::find( (string) $product_id );
			if ( $product && ! is_wp_error( $product ) && empty( $product->archived ) ) {
				return $product;
			}
		}

		$product = \SureCart\Models\Product::create(
			array(
				'name'        => __( 'Invoice Payment', 'doublescale' ),
				'description' => __( 'Hidden product used by DoubleScale to collect invoice payments via SureCart.', 'doublescale' ),
			)
		);

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		update_option( self::PRODUCT_OPTION, (string) $product->id, false );

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
	 * @param object $charge SureCart invoice/checkout or charge-shaped object.
	 * @return object|null
	 */
	private function normalize_charge( object $charge ) {
		if ( isset( $charge->payment_mode ) && PaymentModeSlugs::surecart() === (string) $charge->payment_mode ) {
			$txn = (string) ( $charge->transaction_id ?? '' );
			return '' !== $txn ? $charge : null;
		}

		$checkout = null;
		if ( isset( $charge->checkout ) ) {
			$checkout = $charge->checkout;
		} elseif ( isset( $charge->paid_at ) || isset( $charge->status ) ) {
			$checkout = $charge;
		}

		$invoice_id = (string) ( $charge->id ?? '' );
		$amount     = 0.0;
		$currency   = '';

		if ( isset( $charge->total_amount ) ) {
			$amount   = $this->from_minor_units( (int) $charge->total_amount );
			$currency = strtolower( (string) ( $charge->currency ?? '' ) );
		} elseif ( $checkout && isset( $checkout->total_amount ) ) {
			$amount   = $this->from_minor_units( (int) $checkout->total_amount );
			$currency = strtolower( (string) ( $checkout->currency ?? '' ) );
		} elseif ( $checkout && isset( $checkout->paid_amount ) ) {
			$amount   = $this->from_minor_units( (int) $checkout->paid_amount );
			$currency = strtolower( (string) ( $checkout->currency ?? '' ) );
		}

		if ( '' === $invoice_id || $amount <= 0 ) {
			return null;
		}

		return (object) array(
			'payment_mode'   => PaymentModeSlugs::surecart(),
			'transaction_id' => $invoice_id,
			'id'             => $invoice_id,
			'amount'         => $amount,
			'currency'       => $currency,
		);
	}

	/**
	 * @param PayableSubject $subject    Subject.
	 * @param object         $sc_invoice SureCart invoice.
	 * @return array<string, mixed>
	 */
	private function shape_already_paid_response( PayableSubject $subject, $sc_invoice ): array {
		$amount   = 0.0;
		$currency = '';

		if ( isset( $sc_invoice->checkout->total_amount ) ) {
			$amount   = $this->from_minor_units( (int) $sc_invoice->checkout->total_amount );
			$currency = strtolower( (string) ( $sc_invoice->checkout->currency ?? '' ) );
		}

		$response = array(
			'gateway'      => $this->slug,
			'already_paid' => true,
			'status'       => 'paid',
			'amount'       => $amount,
			'currency'     => $currency,
		);

		if ( $subject instanceof InvoicePayableSubject ) {
			$invoice = $subject->get_invoice();
			$invoice->refresh();
			$response['invoice'] = InvoiceShaper::shape( $invoice, true );
		}

		return $response;
	}

	/**
	 * @param float $amount Major units.
	 * @return int
	 */
	private function to_minor_units( float $amount ): int {
		return (int) round( $amount * 100 );
	}

	/**
	 * @param int $amount Minor units.
	 * @return float
	 */
	private function from_minor_units( int $amount ): float {
		return round( $amount / 100, 2 );
	}

	/**
	 * SureCart blocks test finalize for guests unless unrestricted_test_mode is on.
	 *
	 * @return void
	 */
	private function ensure_test_checkout_allowed(): void {
		if ( $this->resolve_live_mode() ) {
			return;
		}

		if ( ! class_exists( '\SureCart' ) || ! is_callable( array( '\SureCart', 'settings' ) ) ) {
			return;
		}

		if ( ! empty( \SureCart::settings()->get( 'unrestricted_test_mode' ) ) ) {
			return;
		}

		update_option( 'surecart_unrestricted_test_mode', true );
	}

	/**
	 * @param object $sc_invoice SureCart invoice.
	 * @param float  $amount     Major units.
	 * @return bool
	 */
	private function checkout_amount_matches( $sc_invoice, float $amount ): bool {
		$checkout = $sc_invoice->checkout ?? null;
		if ( ! is_object( $checkout ) ) {
			return true;
		}

		$expected = $this->to_minor_units( $amount );
		$due      = isset( $checkout->amount_due ) ? (int) $checkout->amount_due : null;
		if ( null !== $due && $due > 0 ) {
			return $due === $expected;
		}

		$total = isset( $checkout->total_amount ) ? (int) $checkout->total_amount : 0;
		return 0 === $total || $total === $expected;
	}

	/**
	 * @param object $sc_invoice  SureCart invoice.
	 * @param string $checkout_id Checkout id.
	 * @return object|null
	 */
	private function resolve_primary_line_item( $sc_invoice, string $checkout_id ) {
		if ( isset( $sc_invoice->checkout->line_items->data ) && is_array( $sc_invoice->checkout->line_items->data ) ) {
			foreach ( $sc_invoice->checkout->line_items->data as $line_item ) {
				if ( is_object( $line_item ) && ! empty( $line_item->id ) ) {
					return $line_item;
				}
			}
		}

		if ( ! class_exists( '\SureCart\Models\Checkout' ) ) {
			return null;
		}

		$checkout = \SureCart\Models\Checkout::with( array( 'line_items' ) )->find( $checkout_id );
		if ( ! $checkout || is_wp_error( $checkout ) || empty( $checkout->line_items->data ) ) {
			return null;
		}

		foreach ( $checkout->line_items->data as $line_item ) {
			if ( is_object( $line_item ) && ! empty( $line_item->id ) ) {
				return $line_item;
			}
		}

		return null;
	}

	/**
	 * Prefer live processors when present; otherwise use test/mock (typical on local dev).
	 *
	 * @return bool
	 */
	private function resolve_live_mode(): bool {
		return $this->has_enabled_processor( true );
	}

	/**
	 * @param bool $live_mode Whether to look for live (true) or test (false) processors.
	 * @return bool
	 */
	private function has_enabled_processor( bool $live_mode ): bool {
		if ( ! class_exists( '\SureCart\Models\Processor' ) ) {
			return false;
		}

		try {
			$processors = \SureCart\Models\Processor::get();
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( is_wp_error( $processors ) || ! is_array( $processors ) ) {
			return false;
		}

		foreach ( $processors as $processor ) {
			if ( ! is_object( $processor ) ) {
				continue;
			}
			if ( empty( $processor->enabled ) || empty( $processor->approved ) ) {
				continue;
			}
			$processor_live = ! empty( $processor->live_mode );
			if ( $processor_live === $live_mode ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param object $sc_invoice SureCart invoice.
	 * @return bool
	 */
	private function invoice_is_live_mode( $sc_invoice ): bool {
		if ( isset( $sc_invoice->live_mode ) ) {
			return rest_sanitize_boolean( $sc_invoice->live_mode );
		}

		$checkout = $sc_invoice->checkout ?? null;
		if ( is_object( $checkout ) && isset( $checkout->live_mode ) ) {
			return rest_sanitize_boolean( $checkout->live_mode );
		}

		return $this->resolve_live_mode();
	}

	/**
	 * Reuse only when the checkout mode still has a processor to charge with.
	 *
	 * @param object $sc_invoice SureCart invoice.
	 * @return bool
	 */
	private function checkout_matches_payable_mode( $sc_invoice ): bool {
		return $this->has_enabled_processor( $this->invoice_is_live_mode( $sc_invoice ) );
	}
}
