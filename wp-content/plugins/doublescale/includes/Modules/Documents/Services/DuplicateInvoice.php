<?php
/**
 * Duplicate an invoice as a new draft.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\DocumentTemplate;
use DoubleScale\Modules\Documents\Constants\DocumentTemplateColor;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Sales\Services\SalesNumbering;

/**
 * DuplicateInvoice service.
 */
final class DuplicateInvoice {

	/**
	 * Default number of days between issue and due date when the source has no
	 * usable date span. Matches ConvertProposalToInvoice.
	 */
	private const DEFAULT_DUE_DAYS = 7;

	/**
	 * Copy an invoice into a fresh draft.
	 *
	 * Payment state (amount_paid, payment references), the originating proposal
	 * and subscription links, and lifecycle timestamps are deliberately dropped:
	 * carrying them over would attribute an existing payment to a brand new
	 * document and corrupt financial reporting. Number, hash and totals are left
	 * unset so the model's creating/saving hooks regenerate them.
	 *
	 * @param InvoiceModel $source Source invoice.
	 * @return InvoiceModel
	 */
	public function duplicate( InvoiceModel $source ): InvoiceModel {
		$invoice_date = current_time( 'Y-m-d' );

		$copy = new InvoiceModel();
		$copy->fill(
			array(
				'status'                => InvoiceStatus::DRAFT,
				'template'              => DocumentTemplate::normalize( $source->template ?? DocumentTemplate::DEFAULT ),
				'template_color'        => DocumentTemplateColor::normalize( $source->template_color ?? null ),
				'contact_id'            => (int) $source->contact_id,
				'sale_agent_user_id'    => $source->sale_agent_user_id ? (int) $source->sale_agent_user_id : null,
				'invoice_date'          => $invoice_date,
				'due_date'              => $this->next_due_date( $source, $invoice_date ),
				// Copy the raw column. Do not resolve via document_currency() and
				// do not add a getCurrencyAttribute() accessor — NULL must stay
				// NULL (inherit) and an explicit EUR must stay EUR.
				'currency'              => ( null === $source->currency || '' === $source->currency ) ? null : $source->currency,
				'allowed_payment_modes' => is_array( $source->allowed_payment_modes ) ? $source->allowed_payment_modes : array(),
				'discount_type'         => (string) $source->discount_type,
				'discount_value'        => (float) $source->discount_value,
				'line_items'            => is_array( $source->line_items ) ? $source->line_items : array(),
				'adjustment'            => (float) $source->adjustment,
				'billing_address'       => $source->billing_address,
				'shipping_address'      => $source->shipping_address,
				'client_note'           => $source->client_note,
				'terms'                 => $source->terms,
				'sections'              => is_array( $source->sections ) ? $source->sections : array(),
			)
		);
		SalesNumbering::save_with_retry( $copy );

		return $copy->fresh( array( 'contact', 'sale_agent' ) );
	}

	/**
	 * Preserve the source payment window relative to today.
	 *
	 * @param InvoiceModel $source Source invoice.
	 * @param string       $invoice_date New issue date (Y-m-d).
	 * @return string
	 */
	private function next_due_date( InvoiceModel $source, string $invoice_date ): string {
		$days = self::DEFAULT_DUE_DAYS;

		if ( ! empty( $source->invoice_date ) && ! empty( $source->due_date ) ) {
			$from = strtotime( (string) $source->invoice_date );
			$to   = strtotime( (string) $source->due_date );
			if ( $from && $to && $to >= $from ) {
				$days = (int) floor( ( $to - $from ) / DAY_IN_SECONDS );
			}
		}

		return gmdate( 'Y-m-d', strtotime( $invoice_date . ' +' . $days . ' days' ) );
	}
}
