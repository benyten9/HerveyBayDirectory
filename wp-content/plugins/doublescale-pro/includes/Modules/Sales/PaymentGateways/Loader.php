<?php
/**
 * Sales online payment gateways loader (Pro).
 *
 * @package DoubleScale\Pro\Modules\Sales\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Sales\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Pro\Compat\PaymentModeSlugs;
use DoubleScale\Pro\Modules\Pro\Payment\AuthorizeNetGateway;
use DoubleScale\Pro\Modules\Pro\Payment\MollieGateway;
use DoubleScale\Pro\Modules\Pro\Payment\PayPalGateway;
use DoubleScale\Pro\Modules\Pro\Payment\RazorpayGateway;
use DoubleScale\Pro\Modules\Pro\Payment\SquareGateway;
use DoubleScale\Pro\Modules\Pro\Payment\StripeGateway;
use DoubleScale\Pro\Modules\Pro\Payment\SureCartGateway;
use DoubleScale\Pro\Modules\Pro\Payment\WooCommerceGateway;

/**
 * Loader class.
 */
final class Loader {

	/**
	 * @return void
	 */
	public static function register(): void {
		StripeGateway::instance();
		StripeInvoiceWebhookHandler::instance();
		PayPalGateway::instance();
		PayPalInvoiceWebhookHandler::instance();
		WooCommerceGateway::instance();
		WooInvoiceOrderHandler::instance();
		SureCartGateway::instance();
		SureCartInvoiceOrderHandler::instance();
		SureCartCheckoutRedirectFix::register();
		SquareGateway::instance();
		SquareInvoiceWebhookHandler::instance();
		MollieGateway::instance();
		MollieInvoiceWebhookHandler::instance();
		RazorpayGateway::instance();
		RazorpayInvoiceWebhookHandler::instance();
		AuthorizeNetGateway::instance();
		AuthorizeNetInvoiceWebhookHandler::instance();

		add_filter(
			'doublescale_sales_online_payment_gateway_slugs',
			static function ( array $slugs ): array {
				// Use PaymentModeSlugs — free PaymentMode may lack these consts.
				$slugs[] = PaymentModeSlugs::woocommerce();
				$slugs[] = PaymentModeSlugs::surecart();
				$slugs[] = PaymentModeSlugs::square();
				$slugs[] = PaymentModeSlugs::mollie();
				$slugs[] = PaymentModeSlugs::razorpay();
				$slugs[] = PaymentModeSlugs::authorize_net();
				return $slugs;
			}
		);

		add_filter(
			'doublescale_sales_payment_gateway_integration_url',
			static function ( string $url, string $slug ): string {
				// Gateways whose credentials live on an Integrations page.
				$integration_slugs = array(
					PaymentModeSlugs::square()        => 'square',
					PaymentModeSlugs::mollie()        => 'mollie',
					PaymentModeSlugs::razorpay()      => 'razorpay',
					PaymentModeSlugs::authorize_net() => 'authorize_net',
				);

				if ( isset( $integration_slugs[ $slug ] ) ) {
					return admin_url(
						'admin.php?page=doublescale&path=integrations/' . $integration_slugs[ $slug ]
					);
				}

				if ( PaymentModeSlugs::surecart() === $slug ) {
					return admin_url( 'admin.php?page=sc-settings&tab=connection' );
				}

				return $url;
			},
			10,
			2
		);

		add_filter(
			'doublescale_sales_payment_gateway_configuration_hint',
			static function ( string $hint, string $slug ): string {
				if ( PaymentModeSlugs::surecart() === $slug ) {
					return __(
						'Connect your site to SureCart under SureCart → Settings → Connection.',
						'doublescale'
					);
				}

				return $hint;
			},
			10,
			2
		);

		add_filter(
			'doublescale_sales_invoice_payable_subject',
			static function ( $subject, $invoice ) {
				if ( $subject || ! $invoice instanceof InvoiceModel ) {
					return $subject;
				}
				return new InvoicePayableSubject( $invoice );
			},
			10,
			2
		);
	}
}
