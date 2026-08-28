<?php
/**
 * The one place an invoice send happens.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Services\DocumentCurrency;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use WP_Error;

/**
 * Extracted from RestInvoiceController so the REST endpoint and the MCP ability
 * cannot drift apart.
 *
 * Sending an invoice is not "send an email". It advances draft to unpaid,
 * snapshots the customer's billing details, freezes the issuer block and the
 * currency, stamps `sent_at`, writes the activity row, and fires the hook other
 * modules listen on. A second implementation that missed any one of those would
 * leave a customer-visible document in a state the UI cannot explain — and the
 * currency freeze in particular is what stops a later global-currency change
 * from silently restating what the customer already owes.
 *
 * Preconditions are enforced here too, in the same order the REST endpoint used
 * them, so an agent gets the same refusal a dashboard user would.
 */
final class SendInvoice {

	/**
	 * Refuse to send, or send and return the fresh invoice.
	 *
	 * @since 1.0.0
	 *
	 * @param InvoiceModel $invoice Invoice to send.
	 * @param string       $message Optional custom covering message.
	 * @param string       $channel Delivery channel ('email' or 'whatsapp').
	 * @return InvoiceModel|WP_Error
	 */
	public static function send( InvoiceModel $invoice, string $message = '', string $channel = 'email' ) {
		$blocked = self::check_preconditions( $invoice );
		if ( $blocked instanceof WP_Error ) {
			return $blocked;
		}

		// WhatsApp shares are delivered by the client opening wa.me, so that
		// channel records the send without dispatching mail here.
		if ( 'whatsapp' !== $channel ) {
			$notifier = new InvoiceNotifications();
			if ( ! $notifier->send_invoice( $invoice, $message ) ) {
				return new WP_Error(
					'email_failed',
					__( 'Failed to send the invoice email. Check the customer email and SMTP settings.', 'doublescale' ),
					array( 'status' => 500 )
				);
			}
		}

		return self::finish( $invoice, $message, $channel );
	}

	/**
	 * Everything that must be true before an invoice may leave the building.
	 *
	 * @since 1.0.0
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return WP_Error|null Null when sending is allowed.
	 */
	public static function check_preconditions( InvoiceModel $invoice ): ?WP_Error {
		if ( InvoiceStatus::PAID === (string) $invoice->status ) {
			return new WP_Error(
				'invalid_status',
				__( 'Paid invoices cannot be sent.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Without the shortcode page the email would carry a link to nothing.
		if ( '' === InvoiceUrl::get_page_url() ) {
			return new WP_Error(
				'no_invoice_page',
				__( 'Create a WordPress page with the [doublescale_invoice] shortcode before sending invoices.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Last chance for another module to veto a send.
		 *
		 * @param WP_Error|null $gate    Set a WP_Error to block the send.
		 * @param string        $type    Document type.
		 * @param InvoiceModel  $invoice The invoice.
		 */
		$gate = apply_filters( 'doublescale_sales_send_gate', null, 'invoice', $invoice );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return null;
	}

	/**
	 * Advance status, freeze the snapshots, and record the send.
	 *
	 * @since 1.0.0
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $message Custom message.
	 * @param string       $channel Delivery channel.
	 * @return InvoiceModel
	 */
	private static function finish( InvoiceModel $invoice, string $message, string $channel ): InvoiceModel {
		if ( InvoiceStatus::DRAFT === (string) $invoice->status ) {
			$invoice->status = InvoiceStatus::UNPAID;
		}

		DocumentCustomerDetails::snapshot_billing_from_contact( $invoice );
		DocumentIssuerSnapshot::freeze_if_needed( $invoice );
		DocumentCurrency::freeze_on_send( $invoice );

		$invoice->sent_at = current_time( 'mysql' );
		$invoice->save();

		self::log_sent( $invoice, $message, $channel );

		/**
		 * Fires once an invoice has been delivered.
		 *
		 * @param InvoiceModel $invoice The invoice.
		 * @param string       $message Custom message.
		 * @param string       $channel Delivery channel.
		 */
		do_action( 'doublescale_sales_invoice_sent', $invoice, $message, $channel );

		return $invoice;
	}

	/**
	 * Write the send onto the contact's timeline.
	 *
	 * @since 1.0.0
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $message Custom message.
	 * @param string       $channel Delivery channel.
	 * @return void
	 */
	private static function log_sent( InvoiceModel $invoice, string $message = '', string $channel = 'email' ): void {
		if ( ! class_exists( ActivityModel::class ) ) {
			return;
		}

		$note = 'whatsapp' === $channel
			? sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice %s sent to customer via WhatsApp.', 'doublescale' ),
				(string) $invoice->invoice_number
			)
			: sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice %s sent to customer.', 'doublescale' ),
				(string) $invoice->invoice_number
			);

		if ( '' !== trim( $message ) ) {
			$note .= ' — ' . $message;
		}

		ActivityModel::create(
			array(
				'contact_id'    => (int) $invoice->contact_id,
				'activity_type' => ActivityTypes::EMAIL_SENT,
				'data'          => array(
					'title'      => __( 'Invoice sent', 'doublescale' ),
					'type'       => 'system',
					'note'       => $note,
					'invoice_id' => (int) $invoice->id,
				),
				'user_id'       => get_current_user_id() ?: null,
			)
		);
		// TODO(morph): wire proposal/invoice associations (ENTITY_TYPE_INVOICE).
	}
}
