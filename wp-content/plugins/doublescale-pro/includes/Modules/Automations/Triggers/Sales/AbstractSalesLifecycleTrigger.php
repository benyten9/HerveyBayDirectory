<?php
/**
 * Base class for sales lifecycle automation triggers.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contracts\Models\ContractModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;

defined( 'ABSPATH' ) || exit;

abstract class AbstractSalesLifecycleTrigger extends Trigger {

	/**
	 * @var string
	 */
	public $source = 'sales';

	/**
	 * @var string
	 */
	public $group = 'sales';

	/**
	 * @param mixed $proposal {@see ProposalModel} instance.
	 * @param array $extra    Extra enrollment data.
	 * @return void
	 */
	protected function enroll_from_proposal( $proposal, array $extra = array() ): void {
		if ( ! $proposal instanceof ProposalModel ) {
			return;
		}
		$contact = $proposal->contact;
		if ( ! $contact instanceof ContactModel ) {
			return;
		}

		$this->process(
			array(
				'contact'  => $contact,
				'proposal' => $proposal,
				'data'     => array_merge(
					array( 'proposal_id' => (int) $proposal->id ),
					$extra
				),
			)
		);
	}

	/**
	 * @param mixed $invoice {@see InvoiceModel} instance.
	 * @param array $extra   Extra enrollment data.
	 * @return void
	 */
	/**
	 * @param mixed $contract {@see ContractModel} instance.
	 * @param array $extra    Extra enrollment data.
	 * @return void
	 */
	protected function enroll_from_contract( $contract, array $extra = array() ): void {
		if ( ! $contract instanceof ContractModel ) {
			return;
		}
		$contact = $contract->contact;
		if ( ! $contact instanceof ContactModel ) {
			return;
		}

		$this->process(
			array(
				'contact'  => $contact,
				'contract' => $contract,
				'data'     => array_merge(
					array( 'contract_id' => (int) $contract->id ),
					$extra
				),
			)
		);
	}

	/**
	 * @param mixed $invoice {@see InvoiceModel} instance.
	 * @param array $extra   Extra enrollment data.
	 * @return void
	 */
	protected function enroll_from_invoice( $invoice, array $extra = array() ): void {
		if ( ! $invoice instanceof InvoiceModel ) {
			return;
		}
		$contact = $invoice->contact;
		if ( ! $contact instanceof ContactModel ) {
			return;
		}

		$this->process(
			array(
				'contact' => $contact,
				'invoice' => $invoice,
				'data'    => array_merge(
					array( 'invoice_id' => (int) $invoice->id ),
					! empty( $invoice->proposal_id )
						? array( 'proposal_id' => (int) $invoice->proposal_id )
						: array(),
					$extra
				),
			)
		);
	}

	/**
	 * @param mixed $credit_note {@see CreditNoteModel} instance.
	 * @param array $extra       Extra enrollment data.
	 * @return void
	 */
	protected function enroll_from_credit_note( $credit_note, array $extra = array() ): void {
		if ( ! $credit_note instanceof CreditNoteModel ) {
			return;
		}
		$credit_note->loadMissing( 'contact' );
		$contact = $credit_note->contact;
		if ( ! $contact instanceof ContactModel ) {
			return;
		}

		$payload = array(
			'contact'     => $contact,
			'credit_note' => $credit_note,
			'data'        => array_merge(
				array( 'credit_note_id' => (int) $credit_note->id ),
				$extra
			),
		);

		$invoice_id = (int) ( $extra['invoice_id'] ?? 0 );
		if ( $invoice_id > 0 ) {
			$invoice = InvoiceModel::find( $invoice_id );
			if ( $invoice instanceof InvoiceModel ) {
				$payload['invoice'] = $invoice;
			}
		}

		$this->process( $payload );
	}
}
