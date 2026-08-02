<?php
/**
 * Shared base for proposal automation rules (conditions).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Proposal;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;

defined( 'ABSPATH' ) || exit;

abstract class BaseProposalRule extends Rule {

	/**
	 * @var string
	 */
	public $group = 'proposal';

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'proposal_sent',
		'proposal_accepted',
		'proposal_declined',
		'proposal_converted_to_invoice',
		'invoice_sent',
		'invoice_paid',
	);

	/**
	 * @param object $automation_contact Automation contact model.
	 * @return ProposalModel|null
	 */
	protected function resolve_proposal( $automation_contact ): ?ProposalModel {
		if ( ! self::storage_ready() ) {
			return null;
		}

		$proposal_id = isset( $automation_contact->data['proposal_id'] )
			? (int) $automation_contact->data['proposal_id']
			: 0;

		if ( $proposal_id <= 0 ) {
			$invoice_id = isset( $automation_contact->data['invoice_id'] )
				? (int) $automation_contact->data['invoice_id']
				: 0;
			if ( $invoice_id > 0 && AutomationModuleStorage::is_ready( 'documents', InvoiceModel::class ) ) {
				$invoice = InvoiceModel::find( $invoice_id );
				if ( $invoice instanceof InvoiceModel && ! empty( $invoice->proposal_id ) ) {
					$proposal_id = (int) $invoice->proposal_id;
				}
			}
		}

		if ( $proposal_id <= 0 ) {
			return null;
		}

		$proposal = ProposalModel::find( $proposal_id );
		return $proposal instanceof ProposalModel ? $proposal : null;
	}

	/**
	 * @param Rule $rule Rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'documents', ProposalModel::class );
	}

	/**
	 * Whether sales proposal storage is safe to query.
	 */
	protected static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'documents', ProposalModel::class );
	}
}
