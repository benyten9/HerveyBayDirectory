<?php
/**
 * Shared XRechnung export for every invoice download surface.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Services\DocumentIssuerSnapshot;
use DoubleScale\Modules\Sales\Services\SalesEmailMergeTags;
use WP_Error;

/**
 * XRechnungExporter service.
 *
 * The admin route and the public/portal route both call this, so the same
 * invoice produces the same XML regardless of who downloads it.
 */
final class XRechnungExporter {

	public const ERROR_INCOMPLETE = 'doublescale_xrechnung_incomplete';

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return string|WP_Error XML, or a 422 error whose data carries `blockers`.
	 */
	public static function for_invoice( InvoiceModel $invoice ) {
		list( $data, $seller, $buyer ) = self::inputs( $invoice );

		$blockers = XRechnungValidator::preflight( $data, $seller, $buyer );
		if ( ! empty( $blockers ) ) {
			return new WP_Error(
				self::ERROR_INCOMPLETE,
				self::blocked_lead() . ' ' . implode( ' ', $blockers ),
				array(
					'status'   => 422,
					'lead'     => self::blocked_lead(),
					'blockers' => $blockers,
				)
			);
		}

		$mapped = XRechnungInvoiceMapper::map( $data, $seller, $buyer );

		/**
		 * Filter the EN 16931 data before it is serialised as XRechnung.
		 *
		 * @param array<string, mixed> $mapped  Mapped invoice (see XRechnungInvoiceMapper).
		 * @param InvoiceModel         $invoice Source invoice.
		 */
		$mapped = apply_filters( 'doublescale_xrechnung_mapped_invoice', $mapped, $invoice );

		return XRechnungBuilder::build( $mapped, '380' );
	}

	/**
	 * Heading shown above the list of blockers.
	 *
	 * @return string
	 */
	public static function blocked_lead(): string {
		return __( 'This invoice cannot be exported as XRechnung yet. Fix the following:', 'doublescale' );
	}

	/**
	 * Whether the invoice can be exported right now (no pre-flight blockers).
	 *
	 * Customer-facing surfaces use this to hide the download instead of
	 * showing the customer the seller's missing settings.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return bool
	 */
	public static function is_available( InvoiceModel $invoice ): bool {
		if ( ! \DoubleScale\Pro\Modules\Sales\EInvoicing\EInvoicing::enabled() ) {
			return false;
		}

		list( $data, $seller, $buyer ) = self::inputs( $invoice );

		return empty( XRechnungValidator::preflight( $data, $seller, $buyer ) );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}
	 */
	private static function inputs( InvoiceModel $invoice ): array {
		SalesEmailMergeTags::ensure_document_contact_loaded( $invoice );
		$contact = $invoice->relationLoaded( 'contact' ) ? $invoice->contact : null;

		return array(
			self::invoice_data( $invoice ),
			XRechnungProfile::seller( self::company( $invoice ) ),
			XRechnungProfile::buyer( $contact ),
		);
	}

	/**
	 * Download filename for an invoice.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return string
	 */
	public static function filename( InvoiceModel $invoice ): string {
		return (string) $invoice->invoice_number . '-xrechnung';
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return array<string, mixed>
	 */
	private static function invoice_data( InvoiceModel $invoice ): array {
		$merge_context = SalesEmailMergeTags::for_invoice( $invoice );

		return array(
			'invoice_number'  => (string) $invoice->invoice_number,
			'invoice_date'    => (string) $invoice->invoice_date,
			'due_date'        => (string) $invoice->due_date,
			'service_date'    => (string) ( $invoice->service_date ?? '' ),
			'service_date_end' => (string) ( $invoice->service_date_end ?? '' ),
			// Frozen per document; never the live global once sent.
			'currency'        => Settings::document_currency( $invoice->currency, $invoice->sent_at ),
			'buyer_reference' => (string) ( $invoice->buyer_reference ?? '' ),
			'discount_type'   => (string) $invoice->discount_type,
			'discount_value'  => (float) $invoice->discount_value,
			'line_items'      => is_array( $invoice->line_items ) ? $invoice->line_items : array(),
			'total'           => (float) $invoice->total,
			'amount_paid'     => (float) $invoice->amount_paid,
			'note'            => (string) SalesEmailMergeTags::resolve_rich_text(
				$invoice->client_note ? (string) $invoice->client_note : null,
				$merge_context
			),
			'terms'           => (string) SalesEmailMergeTags::resolve_rich_text(
				$invoice->terms ? (string) $invoice->terms : null,
				$merge_context
			),
		);
	}

	/**
	 * Issuer block: the snapshot frozen at send time, else live settings.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return array<string, mixed>
	 */
	private static function company( InvoiceModel $invoice ): array {
		return DocumentIssuerSnapshot::resolve_company_for_shaped(
			array(
				'sent_at'             => $invoice->sent_at ? (string) $invoice->sent_at : null,
				'issuer_snapshot_raw' => $invoice->issuer_snapshot ? (string) $invoice->issuer_snapshot : null,
			)
		);
	}
}
