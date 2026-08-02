<?php
/**
 * Sales online payment gateways loader (Pro).
 *
 * @package DoubleScale\Pro\Modules\Sales\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Sales\PaymentGateways;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Pro\Modules\Pro\Payment\PayPalGateway;
use DoubleScale\Pro\Modules\Pro\Payment\StripeGateway;

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
