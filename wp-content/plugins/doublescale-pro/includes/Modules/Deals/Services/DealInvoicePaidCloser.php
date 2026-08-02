<?php
/**
 * Auto-closes deals linked to an invoice once that invoice is fully paid.
 *
 * Opt-in via the Sales setting `auto_close_deals_on_paid`. Listens on the
 * existing `doublescale_sales_invoice_paid` action (fired once, on the
 * transition into PAID) and marks each linked open deal as Won through the
 * canonical DealManager path so notifications and automations fire identically
 * to a manual win.
 *
 * @package DoubleScale\Pro\Modules\Deals
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Sales\Services\SalesSettings;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;

/**
 * DealInvoicePaidCloser class.
 */
class DealInvoicePaidCloser {

	/**
	 * Register WP action listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_sales_invoice_paid', array( $this, 'on_invoice_paid' ), 10, 1 );
	}

	/**
	 * Mark deals linked to the paid invoice as Won, if the setting is on.
	 *
	 * @param mixed $invoice Paid InvoiceModel instance.
	 * @return void
	 */
	public function on_invoice_paid( $invoice ): void {
		if ( ! $invoice || ! isset( $invoice->id ) ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		foreach ( $this->linked_deal_ids( (int) $invoice->id ) as $deal_id ) {
			$this->close_deal( $deal_id );
		}
	}

	/**
	 * Whether the opt-in setting is enabled.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		if ( ! class_exists( SalesSettings::class ) ) {
			return false;
		}

		return (bool) SalesSettings::get( 'auto_close_deals_on_paid', false );
	}

	/**
	 * Deal ids sharing an activity association with the given invoice.
	 *
	 * Reverse of DealManager::get_linked_document_ids(): an attach creates one
	 * activity carrying both a DEAL and an INVOICE association, so we walk from
	 * the invoice association back to the deal one.
	 *
	 * @param int $invoice_id Invoice id.
	 * @return int[]
	 */
	private function linked_deal_ids( int $invoice_id ): array {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return array();
		}

		$activity_ids = ActivityAssociationModel::where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_INVOICE )
			->where( 'entity_id', $invoice_id )
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

	/**
	 * Mark a single open deal as Won via the canonical service path.
	 *
	 * @param int $deal_id Deal id.
	 * @return void
	 */
	private function close_deal( int $deal_id ): void {
		$deal = DealModel::find( $deal_id );

		// Only touch open deals — never reopen or overwrite a Lost deal.
		if ( ! $deal || 'open' !== (string) $deal->status ) {
			return;
		}

		DealManager::instance()->update_deal(
			$deal_id,
			array( 'status' => 'won' )
		);
	}
}
