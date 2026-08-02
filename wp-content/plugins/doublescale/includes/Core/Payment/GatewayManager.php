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
				'slug'              => (string) $gateway->slug,
				'name'              => (string) $gateway->name,
				'description'       => (string) $gateway->description,
				'available'         => $gateway->is_available(),
				'configured'        => $gateway->is_configured(),
				'enabled_for_sales' => $enabled_for_sales,
				'ready'             => $enabled_for_sales
					&& $gateway->is_available()
					&& $gateway->is_configured(),
				'integration_url'   => $this->integration_url_for( $gateway->slug ),
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
				'slug'       => (string) $gateway->slug,
				'name'       => (string) $gateway->name,
				'available'  => $gateway->is_available(),
				'configured' => $gateway->is_configured(),
				'can_pay'    => $gateway->is_available()
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

		if ( self::CONTEXT_INVOICE === $context ) {
			$legacy = $this->apply_legacy_filter( 'init', $slug, null, $subject );
			if ( null !== $legacy ) {
				return $legacy;
			}
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

		if ( self::CONTEXT_INVOICE === $context ) {
			$legacy = $this->apply_legacy_filter( 'confirm', $slug, null, $subject );
			if ( null !== $legacy ) {
				return $legacy;
			}
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
		if ( 'stripe' !== $slug ) {
			return $default;
		}
		$hook = 'init' === $action
			? 'doublescale_sales_invoice_stripe_init'
			: 'doublescale_sales_invoice_stripe_confirm';

		$invoice = null;
		if ( is_callable( array( $subject, 'get_invoice' ) ) ) {
			$invoice = $subject->get_invoice();
		}

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

		return '';
	}
}
