<?php
/**
 * Shared guards for payable invoices (admin + public online gateway flows).
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use WP_Error;

/**
 * InvoicePayable service.
 */
final class InvoicePayable {

	/**
	 * Base payable checks plus optional gateway allow-list.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @param string|null  $gateway_slug Online gateway slug; null skips gateway allow-list.
	 * @return true|WP_Error
	 */
	public static function guard( InvoiceModel $invoice, ?string $gateway_slug = null ) {
		if ( InvoiceStatus::DRAFT === (string) $invoice->status ) {
			return new WP_Error( 'invalid_status', __( 'Draft invoices cannot be paid online.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( InvoiceStatus::PAID === (string) $invoice->status ) {
			return new WP_Error( 'invalid_status', __( 'This invoice is already paid.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$balance = round( (float) $invoice->total - (float) $invoice->amount_paid, 2 );
		if ( $balance <= 0 ) {
			return new WP_Error( 'invalid_data', __( 'Nothing is due on this invoice.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( null !== $gateway_slug && ! self::gateway_allowed( $invoice, $gateway_slug ) ) {
			return new WP_Error(
				'invalid_data',
				/* translators: %s: payment gateway slug */
				sprintf( __( '%s is not an allowed payment method for this invoice.', 'doublescale' ), $gateway_slug ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $gateway_slug Gateway slug.
	 * @return bool
	 */
	public static function gateway_allowed( InvoiceModel $invoice, string $gateway_slug ): bool {
		$gateway_slug = sanitize_key( $gateway_slug );
		if ( '' === $gateway_slug ) {
			return false;
		}

		$modes = PaymentMode::normalize_list( $invoice->allowed_payment_modes );
		if ( empty( $modes ) ) {
			return true;
		}

		return in_array( $gateway_slug, $modes, true );
	}

	/**
	 * Whether the invoice can be paid online via any configured gateway.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return bool
	 */
	public static function can_pay_online( InvoiceModel $invoice ): bool {
		return ! empty( GatewayManager::instance()->get_payable_for_invoice( $invoice ) );
	}
}
