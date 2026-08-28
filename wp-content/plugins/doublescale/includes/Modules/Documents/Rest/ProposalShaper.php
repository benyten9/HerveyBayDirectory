<?php
/**
 * Shared REST shaping for proposals.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\DocumentTemplate;
use DoubleScale\Modules\Documents\Constants\DocumentTemplateColor;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Services\ProposalUrl;
use DoubleScale\Modules\Sales\Services\SalesEmailMergeTags;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use DoubleScale\Core\Constants\Currencies;
use DoubleScale\Core\Settings\Settings;

/**
 * ProposalShaper class.
 */
final class ProposalShaper {

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @param bool          $with_relations Include relations.
	 * @return array
	 */
	public static function shape_admin( ProposalModel $proposal, bool $with_relations = false ): array {
		$data = array(
			'id'               => (int) $proposal->id,
			'proposal_number'  => (string) $proposal->proposal_number,
			'hash'             => (string) $proposal->hash,
			'subject'          => (string) $proposal->subject,
			'status'           => (string) $proposal->status,
			'template'         => DocumentTemplate::normalize( $proposal->template ?? DocumentTemplate::DEFAULT ),
			'template_color'   => DocumentTemplateColor::normalize( $proposal->template_color ?? null ),
			'contact_id'       => (int) $proposal->contact_id,
			'assigned_user_id' => $proposal->assigned_user_id ? (int) $proposal->assigned_user_id : null,
			'date'             => $proposal->date,
			'open_till'        => $proposal->open_till,
			'currency'         => Settings::document_currency( $proposal->currency, $proposal->sent_at ),
			'currency_stored'  => Currencies::stored_or_null( $proposal->currency ),
			'discount_type'    => (string) $proposal->discount_type,
			'discount_value'   => (float) $proposal->discount_value,
			'line_items'       => is_array( $proposal->line_items ) ? $proposal->line_items : array(),
			'subtotal'         => (float) $proposal->subtotal,
			'adjustment'       => (float) $proposal->adjustment,
			'total'            => (float) $proposal->total,
			'to_name'          => $proposal->to_name,
			'address'          => $proposal->address,
			'city'             => $proposal->city,
			'state'            => $proposal->state,
			'country'          => $proposal->country,
			'zip'              => $proposal->zip,
			'email'            => $proposal->email,
			'phone'            => $proposal->phone,
			'sections'         => is_array( $proposal->sections ) ? $proposal->sections : array(),
			'terms'            => $proposal->terms ? (string) $proposal->terms : null,
			'sent_at'          => $proposal->sent_at ? (string) $proposal->sent_at : null,
			'viewed_at'        => $proposal->viewed_at ? (string) $proposal->viewed_at : null,
			'accepted_at'      => $proposal->accepted_at ? (string) $proposal->accepted_at : null,
			'declined_at'      => $proposal->declined_at ? (string) $proposal->declined_at : null,
			'decline_reason'   => $proposal->decline_reason ? (string) $proposal->decline_reason : null,
			'signed_name'      => $proposal->signed_name ? (string) $proposal->signed_name : null,
			'has_signature'    => ! empty( $proposal->signature ),
			'is_expired'       => self::is_expired( $proposal ),
			'issuer_snapshot_raw' => $proposal->issuer_snapshot ? (string) $proposal->issuer_snapshot : null,
			'invoice_id'       => self::get_linked_invoice_id( $proposal ),
			'public_url'       => ProposalUrl::get_public_url( $proposal ),
			'created_at'       => $proposal->created_at,
			'updated_at'       => $proposal->updated_at,
		);

		if ( $with_relations ) {
			$contact = $proposal->relationLoaded( 'contact' ) ? $proposal->contact : null;
			if ( $contact ) {
				$data['contact'] = array(
					'id'         => (int) $contact->id,
					'email'      => (string) $contact->email,
					'first_name' => $contact->first_name,
					'last_name'  => $contact->last_name,
				);
			}
			$agent = $proposal->relationLoaded( 'assigned_user' ) ? $proposal->assigned_user : null;
			if ( $agent ) {
				$data['assigned_user'] = array(
					'id'           => (int) $agent->ID,
					'display_name' => (string) $agent->display_name,
					'email'        => (string) $agent->user_email,
				);
			}
		}

		return apply_filters( 'doublescale_sales_proposal_admin_shape', $data, $proposal );
	}

	/**
	 * Admin shape with merge tags resolved, for rendering (PDF, print).
	 *
	 * shape_admin() deliberately returns the raw stored text because it also
	 * feeds the edit form, where resolving tags would save the resolved value
	 * back and destroy them. Rendering paths must use this instead.
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return array
	 */
	public static function shape_for_render( ProposalModel $proposal ): array {
		$data          = self::shape_admin( $proposal, true );
		$merge_context = SalesEmailMergeTags::for_proposal( $proposal );

		$data['sections'] = SalesEmailMergeTags::resolve_sections(
			is_array( $proposal->sections ) ? $proposal->sections : array(),
			$merge_context
		);
		$data['terms']    = $proposal->terms
			? SalesEmailMergeTags::resolve_rich_text( (string) $proposal->terms, $merge_context )
			: null;

		return $data;
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return array
	 */
	public static function shape_public( ProposalModel $proposal ): array {
		$is_expired = self::is_expired( $proposal );
		$can_respond = self::can_respond( $proposal, $is_expired );
		$merge_context = SalesEmailMergeTags::for_proposal( $proposal );
		$sections      = is_array( $proposal->sections ) ? $proposal->sections : array();
		$terms         = $proposal->terms
			? SalesEmailMergeTags::resolve_rich_text( (string) $proposal->terms, $merge_context )
			: null;

		return array(
			'proposal_number' => (string) $proposal->proposal_number,
			'subject'         => (string) $proposal->subject,
			'status'          => (string) $proposal->status,
			'template'        => DocumentTemplate::normalize( $proposal->template ?? DocumentTemplate::DEFAULT ),
			'template_color'  => DocumentTemplateColor::normalize( $proposal->template_color ?? null ),
			'date'            => $proposal->date,
			'open_till'       => $proposal->open_till,
			'currency'        => Settings::document_currency( $proposal->currency, $proposal->sent_at ),
			'currency_stored' => Currencies::stored_or_null( $proposal->currency ),
			'discount_type'   => (string) $proposal->discount_type,
			'discount_value'  => (float) $proposal->discount_value,
			'line_items'      => is_array( $proposal->line_items ) ? $proposal->line_items : array(),
			'subtotal'        => (float) $proposal->subtotal,
			'adjustment'      => (float) $proposal->adjustment,
			'total'           => (float) $proposal->total,
			'to_name'         => $proposal->to_name,
			'address'         => $proposal->address,
			'city'            => $proposal->city,
			'state'           => $proposal->state,
			'country'         => $proposal->country,
			'zip'             => $proposal->zip,
			'email'           => $proposal->email,
			'phone'           => $proposal->phone,
			'sections'        => SalesEmailMergeTags::resolve_sections( $sections, $merge_context ),
			'terms'           => $terms,
			'is_expired'      => $is_expired,
			'can_accept'      => $can_respond,
			'can_decline'     => $can_respond,
			'accepted_at'     => $proposal->accepted_at ? (string) $proposal->accepted_at : null,
			'declined_at'     => $proposal->declined_at ? (string) $proposal->declined_at : null,
			'decline_reason'  => $proposal->decline_reason ? (string) $proposal->decline_reason : null,
			'invoice_id'      => self::get_linked_invoice_id( $proposal ),
			'require_signature' => (bool) SalesSettings::get( 'require_signature_on_accept', true ),
			'signed_name'     => $proposal->signed_name ? (string) $proposal->signed_name : null,
			'has_signature'   => ! empty( $proposal->signature ),
		);
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @param bool          $is_expired Whether the proposal is expired.
	 * @return bool
	 */
	public static function can_respond( ProposalModel $proposal, ?bool $is_expired = null ): bool {
		$is_expired = null === $is_expired ? self::is_expired( $proposal ) : $is_expired;
		if ( $is_expired ) {
			return false;
		}

		return in_array(
			(string) $proposal->status,
			array( ProposalStatus::SENT, ProposalStatus::OPEN ),
			true
		);
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return bool
	 */
	public static function is_expired( ProposalModel $proposal ): bool {
		if ( ! in_array( (string) $proposal->status, array( ProposalStatus::DRAFT, ProposalStatus::SENT, ProposalStatus::OPEN ), true ) ) {
			return false;
		}
		if ( empty( $proposal->open_till ) ) {
			return false;
		}
		return (string) $proposal->open_till < current_time( 'Y-m-d' );
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return int|null
	 */
	public static function get_linked_invoice_id( ProposalModel $proposal ): ?int {
		if ( $proposal->relationLoaded( 'invoice' ) && $proposal->invoice ) {
			return (int) $proposal->invoice->id;
		}

		$invoice_id = InvoiceModel::query()
			->where( 'proposal_id', (int) $proposal->id )
			->value( 'id' );

		return $invoice_id ? (int) $invoice_id : null;
	}
}
