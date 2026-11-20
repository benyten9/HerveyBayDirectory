<?php
/**
 * Keeps invoices made from a project's documents on the project.
 *
 * Two kinds of invoice are created from a document the project already holds,
 * by code that knows nothing about projects:
 *
 * - converting a proposal (the admin's "Convert to Invoice", or automatically
 *   when the client accepts) — free's Documents module;
 * - each cycle of a recurring invoice — the Recurring Invoices module.
 *
 * Without this the new invoice is not on the project, so the project's money
 * (team view, client portal, shared page) misses it. Deals do the same for
 * conversions in DealProposalInvoiceLinker.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectDocumentLinker class.
 */
class ProjectDocumentLinker {

	/**
	 * Register WP action listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		// Early, so the link exists before automations on this hook run.
		add_action( 'doublescale_sales_proposal_converted_to_invoice', array( $this, 'on_proposal_converted' ), 5, 2 );
		add_action( 'doublescale_recurring_invoice_generated', array( $this, 'on_recurring_invoice_generated' ), 5, 2 );
	}

	/**
	 * Attach the new invoice to every project the proposal is linked to.
	 *
	 * @param mixed $proposal Source ProposalModel.
	 * @param mixed $invoice  Created InvoiceModel.
	 * @return void
	 */
	public function on_proposal_converted( $proposal, $invoice ): void {
		if ( ! $proposal || ! isset( $proposal->id ) ) {
			return;
		}

		$this->link( ProjectManager::instance()->get_project_ids_for_document( (int) $proposal->id, 'proposal' ), $invoice );
	}

	/**
	 * Attach a generated occurrence to every project its template is linked to.
	 *
	 * @param mixed $invoice  Generated InvoiceModel.
	 * @param mixed $template Template InvoiceModel.
	 * @return void
	 */
	public function on_recurring_invoice_generated( $invoice, $template ): void {
		if ( ! $template || ! isset( $template->id ) ) {
			return;
		}

		$this->link( ProjectManager::instance()->get_project_ids_for_document( (int) $template->id, 'invoice' ), $invoice );
	}

	/**
	 * @param array<int> $project_ids Projects.
	 * @param mixed      $invoice     InvoiceModel.
	 * @return void
	 */
	private function link( array $project_ids, $invoice ): void {
		if ( ! $invoice || ! isset( $invoice->id ) ) {
			return;
		}

		foreach ( $project_ids as $project_id ) {
			ProjectManager::instance()->attach_invoice( $project_id, (int) $invoice->id );
		}
	}
}
