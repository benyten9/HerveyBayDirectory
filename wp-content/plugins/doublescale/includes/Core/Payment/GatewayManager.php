<?php
/**
 * Unified payment gateway registry and lifecycle entrypoints.
 *
 * @package DoubleScale\Core\Payment
 */

namespace DoubleScale\Core\Payment;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Services\InvoicePayable;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use WP_Error;

/**
 * GatewayManager class.
 */
final class GatewayManager {

	public const CONTEXT_INVOICE = 'invoice';

	public const CONTEXT_BOOKING = 'booking';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @var array<string, array<string, Gateway>>
	 */
	private $gateways = array();

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @param string  $context Payment context (`invoice` or `booking`).
	 * @param Gateway $gateway Gateway instance.
	 * @return void
	 */
	public function register( string $context, Gateway $gateway ): void {
		$context = sanitize_key( $context );
		$slug    = sanitize_key( (string) $gateway->slug );
		if ( '' === $context || '' === $slug ) {
			return;
		}
		if ( ! isset( $this->gateways[ $context ] ) ) {
			$this->gateways[ $context ] = array();
		}
		if ( isset( $this->gateways[ $context ][ $slug ] ) ) {
			return;
		}
		$this->gateways[ $context ][ $slug ] = $gateway;
	}

	/**
	 * @param string $context Payment context.
	 * @param string $slug    Gateway slug.
	 * @return Gateway|null
	 */
	public function get( string $context, string $slug ): ?Gateway {
		$context = sanitize_key( $context );
		$slug    = sanitize_key( $slug );
		return $this->gateways[ $context ][ $slug ] ?? null;
	}

	/**
	 * @param string $context Payment context.
	 * @return Gateway[]
	 */
	public function all( string $context ): array {
		$context = sanitize_key( $context );
		return array_values( $this->gateways[ $context ] ?? array() );
	}

	/**
	 * @param string $context Payment context.
	 * @return string[]
	 */
	public function slugs( string $context ): array {
		$context = sanitize_key( $context );
		return array_keys( $this->gateways[ $context ] ?? array() );
	}

	/**
	 * Invoice-context shorthand for {@see self::slugs()}.
	 *
	 * @return string[]
	 */
	public function invoice_slugs(): array {
		return $this->slugs( self::CONTEXT_INVOICE );
	}

	/**
	 * Whether a gateway slug is enabled in Sales settings.
	 *
	 * @param string $slug Gateway slug.
	 * @return bool
	 */
	public function is_enabled_for_sales( string $slug ): bool {
		$slug    = sanitize_key( $slug );
		$enabled = SalesSettings::get_resolved_enabled_online_gateways();

		return in_array( $slug, $enabled, true );
	}

	/**
	 * Gateways allowed on this invoice and ready to accept payment.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return Gateway[]
	 */
	public function get_payable_for_invoice( InvoiceModel $invoice ): array {
		$payable = array();
		foreach ( $this->all( self::CONTEXT_INVOICE ) as $gateway ) {
			if ( ! $this->is_enabled_for_sales( $gateway->slug ) ) {
				continue;
			}
			if ( ! InvoicePayable::gateway_allowed( $invoice, $gateway->slug ) ) {
				continue;
			}
			if ( ! $gateway->is_available() || ! $gateway->is_configured() ) {
				continue;
			}
			$payable[] = $gateway;
		}
		return $payable;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function shape_status_list(): array {
		$list = array();
		foreach ( $this->all( self::CONTEXT_INVOICE ) as $gateway ) {
			$enabled_for_sales = $this->is_enabled_for_sales( $gateway->slug );
			$list[]            = array(
				'slug'                => (string) $gateway->slug,
				'name'                => (string) $gateway->name,
				'description'         => (string) $gateway->description,
				'available'           => $gateway->is_available(),
				'configured'          => $gateway->is_configured(),
				'return_query_arg'    => $gateway->return_query_arg(),
				'enabled_for_sales'   => $enabled_for_sales,
				'ready'               => $enabled_for_sales
					&& $gateway->is_available()
					&& $gateway->is_configured(),
				'integration_url'     => $this->integration_url_for( $gateway->slug ),
				'configuration_hint'  => $this->configuration_hint_for( $gateway ),
			);
		}
		return $list;
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return array<int, array<string, mixed>>
	 */
	public function shape_for_invoice( InvoiceModel $invoice ): array {
		$list = array();
		foreach ( $this->all( self::CONTEXT_INVOICE ) as $gateway ) {
			if ( ! $this->is_enabled_for_sales( $gateway->slug ) ) {
				continue;
			}
			if ( ! InvoicePayable::gateway_allowed( $invoice, $gateway->slug ) ) {
				continue;
			}
			$list[] = array(
				'slug'             => (string) $gateway->slug,
				'name'             => (string) $gateway->name,
				'available'        => $gateway->is_available(),
				'configured'       => $gateway->is_configured(),
				'return_query_arg' => $gateway->return_query_arg(),
				'can_pay'          => $gateway->is_available()
					&& $gateway->is_configured()
					&& true === InvoicePayable::guard( $invoice, $gateway->slug ),
			);
		}
		return $list;
	}

	/**
	 * @param string         $context Payment context.
	 * @param string         $slug    Gateway slug.
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function init( string $context, string $slug, PayableSubject $subject ) {
		// The legacy filter is the back-compat hook for a Stripe implementation
		// supplied outside this registry, so it has to be consulted before the
		// "is a gateway registered?" checks — otherwise it can never fire.
		if ( self::CONTEXT_INVOICE === $context ) {
			$legacy = $this->apply_legacy_filter( 'init', $slug, null, $subject );
			if ( null !== $legacy ) {
				return $legacy;
			}
		}

		$gateway = $this->get( $context, $slug );
		if ( ! $gateway ) {
			return new WP_Error(
				'gateway_not_found',
				__( 'Payment gateway not found.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		if ( self::CONTEXT_INVOICE === $context && ! $this->is_enabled_for_sales( $slug ) ) {
			return new WP_Error(
				'gateway_disabled',
				__( 'This payment gateway is disabled in Sales settings.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $gateway->is_available() ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'This payment gateway is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $gateway->is_configured() ) {
			return new WP_Error(
				'gateway_not_configured',
				__( 'This payment gateway is not configured.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		return $gateway->init( $subject );
	}

	/**
	 * @param string         $context Payment context.
	 * @param string         $slug    Gateway slug.
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	public function confirm( string $context, string $slug, PayableSubject $subject ) {
		// Mirrors init(): the legacy hook must win before registration checks.
		if ( self::CONTEXT_INVOICE === $context ) {
			$legacy = $this->apply_legacy_filter( 'confirm', $slug, null, $subject );
			if ( null !== $legacy ) {
				return $legacy;
			}
		}

		$gateway = $this->get( $context, $slug );
		if ( ! $gateway ) {
			return new WP_Error(
				'gateway_not_found',
				__( 'Payment gateway not found.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $gateway->is_available() ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'This payment gateway is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		return $gateway->confirm( $subject );
	}

	/**
	 * Invoice init with payability guard — builds subject via filter when Pro is active.
	 *
	 * @param string       $slug    Gateway slug.
	 * @param InvoiceModel $invoice Invoice.
	 * @return array|WP_Error
	 */
	public function init_payment( string $slug, InvoiceModel $invoice ) {
		$guard = InvoicePayable::guard( $invoice, $slug );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// The legacy hook works off the invoice, not a PayableSubject, so it can
		// serve a Stripe implementation supplied without Pro — check it before
		// requiring a subject that only Pro provides.
		$legacy = $this->apply_invoice_legacy_filter( 'init', $slug, $invoice );
		if ( null !== $legacy ) {
			return $legacy;
		}

		/**
		 * Build a PayableSubject for an invoice.
		 *
		 * Pro registers `InvoicePayableSubject`; free returns null when Pro is inactive.
		 *
		 * @param PayableSubject|null $subject Default null.
		 * @param InvoiceModel        $invoice Invoice.
		 */
		$subject = apply_filters( 'doublescale_sales_invoice_payable_subject', null, $invoice );
		if ( ! $subject instanceof PayableSubject ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'This payment gateway is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		return $this->init( self::CONTEXT_INVOICE, $slug, $subject );
	}

	/**
	 * Invoice confirm — builds subject via filter when Pro is active.
	 *
	 * @param string       $slug    Gateway slug.
	 * @param InvoiceModel $invoice Invoice.
	 * @return array|WP_Error
	 */
	public function confirm_payment( string $slug, InvoiceModel $invoice ) {
		// Mirrors init_payment(): the legacy hook precedes the Pro-only subject.
		$legacy = $this->apply_invoice_legacy_filter( 'confirm', $slug, $invoice );
		if ( null !== $legacy ) {
			return $legacy;
		}

		/**
		 * @param PayableSubject|null $subject Default null.
		 * @param InvoiceModel        $invoice Invoice.
		 */
		$subject = apply_filters( 'doublescale_sales_invoice_payable_subject', null, $invoice );
		if ( ! $subject instanceof PayableSubject ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'This payment gateway is not available.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		return $this->confirm( self::CONTEXT_INVOICE, $slug, $subject );
	}

	/**
	 * Backward compatibility for stripe-specific filters.
	 *
	 * @param string         $action  init|confirm.
	 * @param string         $slug    Gateway slug.
	 * @param mixed          $default Default value.
	 * @param PayableSubject $subject Payable subject.
	 * @return mixed
	 */
	private function apply_legacy_filter( string $action, string $slug, $default, PayableSubject $subject ) {
		$invoice = null;
		if ( is_callable( array( $subject, 'get_invoice' ) ) ) {
			$invoice = $subject->get_invoice();
		}

		return $this->apply_invoice_legacy_filter( $action, $slug, $invoice, $default );
	}

	/**
	 * Backward compatibility for the stripe-specific filters, keyed off the
	 * invoice rather than a PayableSubject.
	 *
	 * Returning null means "no legacy handler took this", so callers fall
	 * through to the registered gateway.
	 *
	 * @param string            $action  init|confirm.
	 * @param string            $slug    Gateway slug.
	 * @param InvoiceModel|null $invoice Invoice.
	 * @param mixed             $default Default value.
	 * @return mixed
	 */
	private function apply_invoice_legacy_filter( string $action, string $slug, $invoice, $default = null ) {
		if ( 'stripe' !== $slug ) {
			return $default;
		}

		$hook = 'init' === $action
			? 'doublescale_sales_invoice_stripe_init'
			: 'doublescale_sales_invoice_stripe_confirm';

		return apply_filters( $hook, $default, $invoice );
	}

	/**
	 * Admin URL for gateway credentials (Integrations, etc.).
	 *
	 * @param string $slug Gateway slug.
	 * @return string
	 */
	private function integration_url_for( string $slug ): string {
		$url = apply_filters( 'doublescale_sales_payment_gateway_integration_url', '', $slug );
		if ( '' !== $url ) {
			return (string) $url;
		}

		if ( 'stripe' === $slug ) {
			return admin_url( 'admin.php?page=doublescale&path=integrations/stripe' );
		}

		if ( 'paypal' === $slug ) {
			return admin_url( 'admin.php?page=doublescale&path=integrations/paypal' );
		}

		if ( 'woocommerce' === $slug ) {
			return admin_url( 'admin.php?page=wc-settings&tab=checkout' );
		}

		return '';
	}

	/**
	 * Human-readable reason when a gateway is available but not configured.
	 *
	 * @param Gateway $gateway Gateway.
	 * @return string
	 */
	private function configuration_hint_for( Gateway $gateway ): string {
		if ( $gateway->is_configured() || ! $gateway->is_available() ) {
			return '';
		}

		$hint = '';
		if ( 'woocommerce' === $gateway->slug ) {
			$hint = __(
				'Enable at least one payment method in WooCommerce → Settings → Payments (e.g. Cash on Delivery).',
				'doublescale'
			);
		}

		/**
		 * Human-readable reason a gateway is available but not yet configured.
		 *
		 * Lets gateways registered outside core supply a hint without editing
		 * this method.
		 *
		 * @param string $hint Default hint ('' when none).
		 * @param string $slug Gateway slug.
		 */
		return (string) apply_filters(
			'doublescale_sales_payment_gateway_configuration_hint',
			$hint,
			(string) $gateway->slug
		);
	}
}
