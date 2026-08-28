<?php
/**
 * Shape credit note models for REST responses.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteApplicationModel;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNoteUrl;
use DoubleScale\Core\Constants\Currencies;
use DoubleScale\Core\Settings\Settings;

/**
 * CreditNoteShaper class.
 */
class CreditNoteShaper {

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @param bool            $with_relations Include relations.
	 * @return array
	 */
	public static function shape( CreditNoteModel $credit_note, bool $with_relations = false ): array {
		$data = array(
			'id'                 => (int) $credit_note->id,
			'credit_note_number' => (string) $credit_note->credit_note_number,
			'hash'               => (string) $credit_note->hash,
			'status'             => (string) $credit_note->status,
			'contact_id'         => (int) $credit_note->contact_id,
			'invoice_id'         => $credit_note->invoice_id ? (int) $credit_note->invoice_id : null,
			'sale_agent_user_id' => $credit_note->sale_agent_user_id ? (int) $credit_note->sale_agent_user_id : null,
			'credit_note_date'   => $credit_note->credit_note_date,
			'reason'             => $credit_note->reason,
			'currency'           => \DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $credit_note->currency, $credit_note->sent_at ),
			'currency_stored'    => Currencies::stored_or_null( $credit_note->currency ),
			'discount_type'      => (string) $credit_note->discount_type,
			'discount_value'     => (float) $credit_note->discount_value,
			'line_items'         => is_array( $credit_note->line_items ) ? $credit_note->line_items : array(),
			'subtotal'           => (float) $credit_note->subtotal,
			'total_tax'          => (float) $credit_note->total_tax,
			'adjustment'         => (float) $credit_note->adjustment,
			'total'              => (float) $credit_note->total,
			'amount_applied'     => (float) $credit_note->amount_applied,
			'remaining'          => self::remaining( $credit_note ),
			'sent_at'            => $credit_note->sent_at ? (string) $credit_note->sent_at : null,
			'viewed_at'          => $credit_note->viewed_at ? (string) $credit_note->viewed_at : null,
			'billing_address'    => $credit_note->billing_address,
			'client_note'        => $credit_note->client_note,
			'terms'              => $credit_note->terms,
			'issuer_snapshot_raw' => $credit_note->issuer_snapshot ? (string) $credit_note->issuer_snapshot : null,
			'public_url'         => CreditNoteUrl::get_public_url( $credit_note ),
			'created_at'         => $credit_note->created_at,
			'updated_at'         => $credit_note->updated_at,
		);

		if ( $with_relations ) {
			$contact = $credit_note->relationLoaded( 'contact' ) ? $credit_note->contact : null;
			if ( $contact ) {
				$data['contact'] = array(
					'id'         => (int) $contact->id,
					'email'      => (string) $contact->email,
					'first_name' => $contact->first_name,
					'last_name'  => $contact->last_name,
				);
			}

			$agent = $credit_note->relationLoaded( 'sale_agent' ) ? $credit_note->sale_agent : null;
			if ( $agent ) {
				$data['sale_agent'] = array(
					'id'           => (int) $agent->ID,
					'display_name' => (string) $agent->display_name,
					'email'        => (string) $agent->user_email,
				);
			}

			$invoice = $credit_note->relationLoaded( 'invoice' ) ? $credit_note->invoice : null;
			if ( $invoice ) {
				$data['invoice'] = array(
					'id'             => (int) $invoice->id,
					'invoice_number' => (string) $invoice->invoice_number,
					'status'         => (string) $invoice->status,
				);
			}

			if ( $credit_note->relationLoaded( 'applications' ) ) {
				$data['applications'] = array();
				foreach ( $credit_note->applications as $application ) {
					if ( $application instanceof CreditNoteApplicationModel ) {
						$data['applications'][] = self::shape_application( $application, true );
					}
				}
			}
		}

		return apply_filters( 'doublescale_sales_credit_note_admin_shape', $data, $credit_note );
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return array
	 */
	public static function shape_public( CreditNoteModel $credit_note ): array {
		$contact = $credit_note->relationLoaded( 'contact' ) ? $credit_note->contact : null;

		return array(
			'credit_note_number' => (string) $credit_note->credit_note_number,
			'status'             => (string) $credit_note->status,
			'credit_note_date'   => $credit_note->credit_note_date,
			'reason'             => $credit_note->reason,
			'currency'           => \DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $credit_note->currency, $credit_note->sent_at ),
			'discount_type'      => (string) $credit_note->discount_type,
			'discount_value'     => (float) $credit_note->discount_value,
			'line_items'         => is_array( $credit_note->line_items ) ? $credit_note->line_items : array(),
			'subtotal'           => (float) $credit_note->subtotal,
			'total_tax'          => (float) $credit_note->total_tax,
			'adjustment'         => (float) $credit_note->adjustment,
			'total'              => (float) $credit_note->total,
			'amount_applied'     => (float) $credit_note->amount_applied,
			'remaining'          => self::remaining( $credit_note ),
			'billing_address'    => $credit_note->billing_address,
			'client_note'        => $credit_note->client_note,
			'terms'              => $credit_note->terms,
			'contact'            => $contact ? array(
				'first_name' => $contact->first_name,
				'last_name'  => $contact->last_name,
			) : null,
		);
	}

	/**
	 * @param CreditNoteApplicationModel $application Application.
	 * @param bool                       $with_relations Include relations.
	 * @return array
	 */
	public static function shape_application( CreditNoteApplicationModel $application, bool $with_relations = false ): array {
		$data = array(
			'id'                  => (int) $application->id,
			'credit_note_id'      => (int) $application->credit_note_id,
			'invoice_id'          => (int) $application->invoice_id,
			'amount'              => (float) $application->amount,
			'applied_date'        => $application->applied_date,
			'note'                => $application->note,
			'applied_by_user_id'  => $application->applied_by_user_id ? (int) $application->applied_by_user_id : null,
			'created_at'          => $application->created_at,
			'updated_at'          => $application->updated_at,
		);

		if ( $with_relations ) {
			$invoice = $application->relationLoaded( 'invoice' ) ? $application->invoice : null;
			if ( $invoice ) {
				$data['invoice'] = array(
					'id'             => (int) $invoice->id,
					'invoice_number' => (string) $invoice->invoice_number,
					'status'         => (string) $invoice->status,
				);
			}
		}

		return $data;
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return float
	 */
	public static function remaining( CreditNoteModel $credit_note ): float {
		return max( 0, round( (float) $credit_note->total - (float) $credit_note->amount_applied, 2 ) );
	}
}
