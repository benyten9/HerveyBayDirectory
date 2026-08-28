<?php
/**
 * Convert a proposal into a draft invoice.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\DocumentTemplate;
use DoubleScale\Modules\Documents\Constants\DocumentTemplateColor;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Sales\Services\SalesNumbering;
use WP_Error;

/**
 * ConvertProposalToInvoice service.
 */
class ConvertProposalToInvoice {

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return InvoiceModel|WP_Error
	 */
	public function convert( ProposalModel $proposal ) {
		$existing = InvoiceModel::query()->where( 'proposal_id', (int) $proposal->id )->first();
		if ( $existing ) {
			return new WP_Error(
				'already_converted',
				__( 'This proposal has already been converted to an invoice.', 'doublescale' ),
				array(
					'status'     => 400,
					'invoice_id' => (int) $existing->id,
				)
			);
		}

		$today    = current_time( 'Y-m-d' );
		$due_date = gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) );

		$invoice = new InvoiceModel();
		$invoice->fill(
			array(
				'status'              => InvoiceStatus::DRAFT,
				'template'            => DocumentTemplate::normalize( $proposal->template ?? DocumentTemplate::DEFAULT ),
				'template_color'      => DocumentTemplateColor::normalize( $proposal->template_color ?? null ),
				'contact_id'          => (int) $proposal->contact_id,
				'proposal_id'         => (int) $proposal->id,
				'sale_agent_user_id'  => $proposal->assigned_user_id ? (int) $proposal->assigned_user_id : null,
				// Copy the raw column. Do not resolve via document_currency() and
				// do not add a getCurrencyAttribute() accessor — NULL must stay
				// NULL (inherit) and an explicit EUR must stay EUR.
				'currency'            => ( null === $proposal->currency || '' === $proposal->currency ) ? null : $proposal->currency,
				'discount_type'       => (string) $proposal->discount_type,
				'discount_value'      => (float) $proposal->discount_value,
				'adjustment'          => (float) $proposal->adjustment,
				'line_items'          => is_array( $proposal->line_items ) ? $proposal->line_items : array(),
				'billing_address'     => self::compose_billing_address( $proposal ),
				'invoice_date'        => $today,
				'due_date'            => $due_date,
				'client_note'         => sprintf(
					/* translators: 1: proposal number, 2: proposal subject */
					__( 'Converted from proposal %1$s: %2$s', 'doublescale' ),
					(string) $proposal->proposal_number,
					(string) $proposal->subject
				),
				'terms'               => $proposal->terms,
				'sections'            => is_array( $proposal->sections ) ? $proposal->sections : array(),
			)
		);
		SalesNumbering::save_with_retry( $invoice );

		$proposal->status = ProposalStatus::ACCEPTED;
		$proposal->save();

		return $invoice->fresh( array( 'contact', 'sale_agent' ) );
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return string
	 */
	private static function compose_billing_address( ProposalModel $proposal ): string {
		$lines = array();

		if ( $proposal->to_name ) {
			$lines[] = (string) $proposal->to_name;
		}
		if ( $proposal->address ) {
			$lines[] = (string) $proposal->address;
		}

		$city_line = trim(
			implode(
				', ',
				array_filter(
					array(
						$proposal->city ? (string) $proposal->city : '',
						$proposal->state ? (string) $proposal->state : '',
					)
				)
			)
		);
		if ( $city_line && $proposal->zip ) {
			$city_line .= ' ' . (string) $proposal->zip;
		} elseif ( $proposal->zip ) {
			$city_line = (string) $proposal->zip;
		}
		if ( $city_line ) {
			$lines[] = $city_line;
		}
		if ( $proposal->country ) {
			$lines[] = (string) $proposal->country;
		}
		if ( $proposal->email ) {
			$lines[] = (string) $proposal->email;
		}
		if ( $proposal->phone ) {
			$lines[] = (string) $proposal->phone;
		}

		return implode( "\n", $lines );
	}
}
