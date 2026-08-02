<?php
/**
 * Base merge tag for sales automation context.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Modules\Documents\Services\InvoiceUrl;
use DoubleScale\Modules\Documents\Services\ProposalUrl;

/**
 * AbstractSalesMergeTag class.
 */
abstract class AbstractSalesMergeTag extends MergeTag {

	/**
	 * @var string
	 */
	public $group = 'sales';

	/**
	 * @var bool
	 */
	public $is_automation = true;

	/**
	 * @param AutomationContactModel|null $contact Contact.
	 * @return ProposalModel|null
	 */
	protected function resolve_proposal( $contact ): ?ProposalModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'documents', ProposalModel::class ) ) {
			return null;
		}
		$proposal_id = (int) ( $contact->data['proposal_id'] ?? 0 );
		if ( $proposal_id <= 0 ) {
			$invoice = $this->resolve_invoice( $contact );
			if ( $invoice instanceof InvoiceModel && ! empty( $invoice->proposal_id ) ) {
				$proposal_id = (int) $invoice->proposal_id;
			}
		}
		if ( $proposal_id <= 0 ) {
			return null;
		}
		$proposal = ProposalModel::find( $proposal_id );
		return $proposal instanceof ProposalModel ? $proposal : null;
	}

	/**
	 * @param AutomationContactModel|null $contact Contact.
	 * @return InvoiceModel|null
	 */
	protected function resolve_invoice( $contact ): ?InvoiceModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'documents', InvoiceModel::class ) ) {
			return null;
		}
		$invoice_id = (int) ( $contact->data['invoice_id'] ?? 0 );
		if ( $invoice_id <= 0 ) {
			return null;
		}
		$invoice = InvoiceModel::find( $invoice_id );
		return $invoice instanceof InvoiceModel ? $invoice : null;
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return string
	 */
	protected function format_money( ProposalModel $proposal ): string {
		$total    = number_format( (float) $proposal->total, 2, '.', '' );
		$currency = (string) $proposal->currency;
		return trim( $currency . ' ' . $total );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return string
	 */
	protected function format_invoice_money( InvoiceModel $invoice, string $field = 'total' ): string {
		$amount   = 'balance' === $field ? max( 0, (float) $invoice->total - (float) $invoice->amount_paid ) : (float) $invoice->total;
		$total    = number_format( $amount, 2, '.', '' );
		$currency = (string) $invoice->currency;
		return trim( $currency . ' ' . $total );
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @return string
	 */
	protected function proposal_public_url( ProposalModel $proposal ): string {
		return ProposalUrl::get_public_url( $proposal );
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @return string
	 */
	protected function invoice_public_url( InvoiceModel $invoice ): string {
		return InvoiceUrl::get_public_url( $invoice );
	}

	/**
	 * Resolve a friendly customer name from automation context.
	 *
	 * @param AutomationContactModel|mixed $contact Contact context.
	 * @return string
	 */
	protected function resolve_customer_display_name( $contact ): string {
		if ( ! $contact instanceof AutomationContactModel ) {
			return __( 'there', 'doublescale' );
		}

		if ( $contact->relationLoaded( 'contact' ) && $contact->contact ) {
			$name = trim( (string) $contact->contact->first_name . ' ' . (string) $contact->contact->last_name );
			if ( '' !== $name ) {
				return $name;
			}
		}

		$proposal = $this->resolve_proposal( $contact );
		if ( $proposal && '' !== trim( (string) $proposal->to_name ) ) {
			return trim( (string) $proposal->to_name );
		}

		return __( 'there', 'doublescale' );
	}
}

/**
 * Proposal-scoped sales merge tags.
 */
abstract class AbstractProposalSalesMergeTag extends AbstractSalesMergeTag {

	/**
	 * @var array<int, string>
	 */
	public $required_triggers = array(
		'proposal_sent',
		'proposal_declined',
		'proposal_accepted',
		'proposal_converted_to_invoice',
		'invoice_sent',
		'invoice_paid',
	);
}

/**
 * Invoice-scoped sales merge tags.
 */
abstract class AbstractInvoiceSalesMergeTag extends AbstractSalesMergeTag {

	/**
	 * @var array<int, string>
	 */
	public $required_triggers = array(
		'invoice_sent',
		'invoice_paid',
		'proposal_converted_to_invoice',
	);
}
