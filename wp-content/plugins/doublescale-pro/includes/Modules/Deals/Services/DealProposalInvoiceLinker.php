<?php
/**
 * Links an invoice converted from a proposal to the proposal's deals.
 *
 * Conversion happens in the free plugin (manually, or automatically when the
 * customer accepts), which knows nothing about deals. Without this, an invoice
 * created on acceptance is not on its deal, so the deal's financials miss it
 * and DealInvoicePaidCloser never closes the deal when it is paid.
 *
 * @package DoubleScale\Pro\Modules\Deals
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;

/**
 * DealProposalInvoiceLinker class.
 */
class DealProposalInvoiceLinker {

	/**
	 * Register WP action listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		// Early, so the link exists before automations on this hook run.
		add_action( 'doublescale_sales_proposal_converted_to_invoice', array( $this, 'on_converted' ), 5, 2 );
	}

	/**
	 * Attach the new invoice to every deal the proposal is linked to.
	 *
	 * @param mixed $proposal Source ProposalModel.
	 * @param mixed $invoice  Created InvoiceModel.
	 * @return void
	 */
	public function on_converted( $proposal, $invoice ): void {
		if ( ! $proposal || ! isset( $proposal->id ) || ! $invoice || ! isset( $invoice->id ) ) {
			return;
		}

		foreach ( $this->linked_deal_ids( (int) $proposal->id ) as $deal_id ) {
			DealManager::instance()->attach_invoice( $deal_id, (int) $invoice->id );
		}
	}

	/**
	 * Deal ids sharing an activity association with the given proposal.
	 *
	 * @param int $proposal_id Proposal id.
	 * @return int[]
	 */
	private function linked_deal_ids( int $proposal_id ): array {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return array();
		}

		$activity_ids = ActivityAssociationModel::where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROPOSAL )
			->where( 'entity_id', $proposal_id )
			->pluck( 'activity_id' )
			->toArray();

		if ( empty( $activity_ids ) ) {
			return array();
		}

		return array_map(
			'intval',
			ActivityAssociationModel::whereIn( 'activity_id', $activity_ids )
				->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_DEAL )
				->pluck( 'entity_id' )
				->unique()
				->values()
				->toArray()
		);
	}
}
