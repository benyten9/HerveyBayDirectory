<?php
/**
 * Shape invoice models for REST responses.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\PaymentMode;
use DoubleScale\Modules\Documents\Constants\DocumentTemplate;
use DoubleScale\Modules\Documents\Constants\DocumentTemplateColor;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\PaymentModel;
use DoubleScale\Core\Payment\GatewayManager;
use DoubleScale\Modules\Documents\Services\InvoicePayable;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Modules\Sales\Services\SalesEmailMergeTags;
use DoubleScale\Core\Constants\Currencies;
use DoubleScale\Core\Settings\Settings;

/**
 * InvoiceShaper class.
 */
class InvoiceShaper {

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param bool         $with_relations Include relations.
	 * @return array
	 */
	public static function shape( InvoiceModel $invoice, bool $with_relations = false ): array {
		$data = array(
			'id'                    => (int) $invoice->id,
			'invoice_number'        => (string) $invoice->invoice_number,
			'hash'                  => (string) $invoice->hash,
			'status'                => (string) $invoice->status,
			'template'              => DocumentTemplate::normalize( $invoice->template ?? DocumentTemplate::DEFAULT ),
			'template_color'        => DocumentTemplateColor::normalize( $invoice->template_color ?? null ),
			'contact_id'            => (int) $invoice->contact_id,
			'proposal_id'           => $invoice->proposal_id ? (int) $invoice->proposal_id : null,
			'sale_agent_user_id'    => $invoice->sale_agent_user_id ? (int) $invoice->sale_agent_user_id : null,
			'invoice_date'          => $invoice->invoice_date,
			'due_date'              => $invoice->due_date,
			// Resolved display code. Round-trip inherit via currency_stored (null).
			'currency'              => Settings::document_currency( $invoice->currency, $invoice->sent_at ),
			'currency_stored'       => Currencies::stored_or_null( $invoice->currency ),
			'allowed_payment_modes' => PaymentMode::normalize_list( $invoice->allowed_payment_modes ),
			'discount_type'         => (string) $invoice->discount_type,
			'discount_value'        => (float) $invoice->discount_value,
			'line_items'            => is_array( $invoice->line_items ) ? $invoice->line_items : array(),
			'subtotal'              => (float) $invoice->subtotal,
			'total_tax'             => (float) $invoice->total_tax,
			'adjustment'            => (float) $invoice->adjustment,
			'total'                 => (float) $invoice->total,
			'amount_paid'           => (float) $invoice->amount_paid,
			'balance'               => self::balance( $invoice ),
			'is_overdue'            => self::is_overdue( $invoice ),
			'sent_at'               => $invoice->sent_at ? (string) $invoice->sent_at : null,
			'viewed_at'             => $invoice->viewed_at ? (string) $invoice->viewed_at : null,
			'billing_address'       => $invoice->billing_address,
			'shipping_address'      => $invoice->shipping_address,
			'client_note'           => $invoice->client_note,
			'terms'                 => $invoice->terms,
			'sections'              => is_array( $invoice->sections ) ? $invoice->sections : array(),
			'issuer_snapshot_raw'   => $invoice->issuer_snapshot ? (string) $invoice->issuer_snapshot : null,
			'public_url'            => InvoiceUrl::get_public_url( $invoice ),
			'created_at'            => $invoice->created_at,
			'updated_at'            => $invoice->updated_at,
		);

		if ( $with_relations ) {
			$contact = $invoice->relationLoaded( 'contact' ) ? $invoice->contact : null;
			if ( $contact ) {
				$data['contact'] = array(
					'id'         => (int) $contact->id,
					'email'      => (string) $contact->email,
					'first_name' => $contact->first_name,
					'last_name'  => $contact->last_name,
				);
			}
			$agent = $invoice->relationLoaded( 'sale_agent' ) ? $invoice->sale_agent : null;
			if ( $agent ) {
				$data['sale_agent'] = array(
					'id'           => (int) $agent->ID,
					'display_name' => (string) $agent->display_name,
					'email'        => (string) $agent->user_email,
				);
			}
			$proposal = $invoice->relationLoaded( 'proposal' ) ? $invoice->proposal : null;
			if ( $proposal ) {
				$data['proposal'] = array(
					'id'              => (int) $proposal->id,
					'proposal_number' => (string) $proposal->proposal_number,
					'subject'         => (string) $proposal->subject,
				);
			}
		}

		return apply_filters( 'doublescale_sales_invoice_admin_shape', $data, $invoice );
	}

	/**
	 * Admin shape with merge tags resolved, for rendering (PDF, print).
	 *
	 * shape() deliberately returns the raw stored text because it also feeds
	 * the edit form, where resolving tags would save the resolved value back
	 * and destroy them. Rendering paths must use this instead.
	 *
	 * @param InvoiceModel $invoice Invoice.
	 * @return array
	 */
	public static function shape_for_render( InvoiceModel $invoice ): array {
		$data          = self::shape( $invoice, true );
		$merge_context = SalesEmailMergeTags::for_invoice( $invoice );

		$data['sections']    = SalesEmailMergeTags::resolve_sections(
			is_array( $invoice->sections ) ? $invoice->sections : array(),
			$merge_context
		);
		$data['client_note'] = SalesEmailMergeTags::resolve_rich_text(
			$invoice->client_note ? (string) $invoice->client_note : null,
			$merge_context
		);
		$data['terms']       = SalesEmailMergeTags::resolve_rich_text(
			$invoice->terms ? (string) $invoice->terms : null,
			$merge_context
		);

		return $data;
	}

	public static function shape_public( InvoiceModel $invoice ): array {
		SalesEmailMergeTags::ensure_document_contact_loaded( $invoice );
		$contact = $invoice->relationLoaded( 'contact' ) ? $invoice->contact : null;
		$merge_context = SalesEmailMergeTags::for_invoice( $invoice );
		$sections      = is_array( $invoice->sections ) ? $invoice->sections : array();

		return array(
			'invoice_number'        => (string) $invoice->invoice_number,
			'status'                => (string) $invoice->status,
			'template'              => DocumentTemplate::normalize( $invoice->template ?? DocumentTemplate::DEFAULT ),
			'template_color'        => DocumentTemplateColor::normalize( $invoice->template_color ?? null ),
			'invoice_date'          => $invoice->invoice_date,
			'due_date'              => $invoice->due_date,
			// Resolved display code. Round-trip inherit via currency_stored (null).
			'currency'              => Settings::document_currency( $invoice->currency, $invoice->sent_at ),
			'currency_stored'       => Currencies::stored_or_null( $invoice->currency ),
			'allowed_payment_modes' => PaymentMode::normalize_list( $invoice->allowed_payment_modes ),
			'discount_type'         => (string) $invoice->discount_type,
			'discount_value'        => (float) $invoice->discount_value,
			'line_items'            => is_array( $invoice->line_items ) ? $invoice->line_items : array(),
			'subtotal'              => (float) $invoice->subtotal,
			'total_tax'             => (float) $invoice->total_tax,
			'adjustment'            => (float) $invoice->adjustment,
			'total'                 => (float) $invoice->total,
			'amount_paid'           => (float) $invoice->amount_paid,
			'balance'               => self::balance( $invoice ),
			'is_overdue'            => self::is_overdue( $invoice ),
			'can_pay'                 => self::can_pay( $invoice ),
			'online_payment_gateways' => GatewayManager::instance()->shape_for_invoice( $invoice ),
			'billing_address'       => $invoice->billing_address,
			'shipping_address'      => $invoice->shipping_address,
			'client_note'           => SalesEmailMergeTags::resolve_rich_text(
				$invoice->client_note ? (string) $invoice->client_note : null,
				$merge_context
			),
			'terms'                 => SalesEmailMergeTags::resolve_rich_text(
				$invoice->terms ? (string) $invoice->terms : null,
				$merge_context
			),
			'sections'              => SalesEmailMergeTags::resolve_sections( $sections, $merge_context ),
			'contact'               => $contact ? array(
				'first_name' => $contact->first_name,
				'last_name'  => $contact->last_name,
			) : null,
		);
	}

	/**
	 * @param PaymentModel $payment Payment.
	 * @return array
	 */
	public static function shape_public_payment( PaymentModel $payment ): array {
		return array(
			'amount'         => (float) $payment->amount,
			'payment_mode'   => $payment->payment_mode,
			'payment_date'   => $payment->payment_date,
			'transaction_id' => $payment->transaction_id ? (string) $payment->transaction_id : null,
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return float
	 */
	public static function balance( InvoiceModel $invoice ): float {
		return max( 0, round( (float) $invoice->total - (float) $invoice->amount_paid, 2 ) );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return bool
	 */
	public static function is_overdue( InvoiceModel $invoice ): bool {
		$status = (string) $invoice->status;
		if ( ! in_array( $status, array( InvoiceStatus::UNPAID, InvoiceStatus::PARTIALLY_PAID ), true ) ) {
			return false;
		}
		if ( empty( $invoice->due_date ) ) {
			return false;
		}

		return (string) $invoice->due_date < \current_time( 'Y-m-d' );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return bool
	 */
	public static function can_pay( InvoiceModel $invoice ): bool {
		return InvoicePayable::can_pay_online( $invoice );
	}
}
