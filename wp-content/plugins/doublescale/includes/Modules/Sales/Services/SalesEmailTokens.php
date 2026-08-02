<?php
/**
 * Token replacement for sales email templates.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;

/**
 * SalesEmailTokens helper.
 */
final class SalesEmailTokens {

	/**
	 * @param string               $text Template text.
	 * @param array<string, string> $tokens Token map.
	 * @return string
	 */
	public static function replace( string $text, array $tokens ): string {
		foreach ( $tokens as $key => $value ) {
			$text = str_replace( '{' . $key . '}', $value, $text );
		}
		return $text;
	}

	/**
	 * @param ProposalModel $proposal Proposal.
	 * @param string        $url Public URL.
	 * @return array<string, string>
	 */
	public static function for_proposal( ProposalModel $proposal, string $url ): array {
		$name = $proposal->to_name ? (string) $proposal->to_name : __( 'there', 'doublescale' );
		return array(
			'contact_name'     => $name,
			'subject'          => (string) $proposal->subject,
			'proposal_number'  => (string) $proposal->proposal_number,
			'total'            => \number_format_i18n( (float) $proposal->total, 2 ) . ' ' . (string) $proposal->currency,
			'open_till'        => $proposal->open_till ? (string) $proposal->open_till : '',
			'proposal_link'    => $url,
			'company_name'     => (string) \get_bloginfo( 'name' ),
		);
	}

	/**
	 * @param InvoiceModel $invoice Invoice.
	 * @param string       $url Public URL.
	 * @return array<string, string>
	 */
	public static function for_invoice( InvoiceModel $invoice, string $url ): array {
		$balance = max( 0, round( (float) $invoice->total - (float) $invoice->amount_paid, 2 ) );
		$name    = '';

		$invoice->loadMissing( 'contact' );
		if ( $invoice->contact ) {
			$name = trim( (string) $invoice->contact->first_name . ' ' . (string) $invoice->contact->last_name );
		}

		return array(
			'contact_name'   => '' !== $name ? $name : __( 'there', 'doublescale' ),
			'invoice_number' => (string) $invoice->invoice_number,
			'total'          => \number_format_i18n( $balance, 2 ) . ' ' . (string) $invoice->currency,
			'due_date'       => $invoice->due_date ? (string) $invoice->due_date : '',
			'invoice_link'   => $url,
			'public_url'     => $url,
			'company_name'   => (string) \get_bloginfo( 'name' ),
		);
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @param string          $url Public URL.
	 * @return array<string, string>
	 */
	public static function for_credit_note( CreditNoteModel $credit_note, string $url ): array {
		$name = __( 'there', 'doublescale' );
		$credit_note->loadMissing( 'contact' );
		if ( $credit_note->contact ) {
			$full = trim( (string) $credit_note->contact->first_name . ' ' . (string) $credit_note->contact->last_name );
			if ( '' !== $full ) {
				$name = $full;
			}
		}

		$remaining = max( 0, round( (float) $credit_note->total - (float) $credit_note->amount_applied, 2 ) );

		return array(
			'contact_name'       => $name,
			'credit_note_number' => (string) $credit_note->credit_note_number,
			'total'              => \number_format_i18n( (float) $credit_note->total, 2 ) . ' ' . (string) $credit_note->currency,
			'remaining'          => \number_format_i18n( $remaining, 2 ) . ' ' . (string) $credit_note->currency,
			'credit_note_date'   => $credit_note->credit_note_date ? (string) $credit_note->credit_note_date : '',
			'credit_note_link'   => $url,
			'public_url'         => $url,
			'company_name'       => (string) \get_bloginfo( 'name' ),
		);
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @param string        $url Public URL.
	 * @return array<string, string>
	 */
	public static function for_contract( ContractModel $contract, string $url ): array {
		$name = __( 'there', 'doublescale' );
		$contract->loadMissing( 'contact' );
		if ( $contract->contact ) {
			$full = trim( (string) $contract->contact->first_name . ' ' . (string) $contract->contact->last_name );
			if ( '' !== $full ) {
				$name = $full;
			}
		}

		return array(
			'contact_name'      => $name,
			'subject'           => (string) $contract->subject,
			'contract_number'   => (string) $contract->contract_number,
			'contract_value'    => \number_format_i18n( (float) $contract->contract_value, 2 ) . ' ' . (string) $contract->currency,
			'end_date'          => $contract->end_date ? (string) $contract->end_date : '',
			'contract_link'     => $url,
			'public_url'        => $url,
			'company_name'      => (string) \get_bloginfo( 'name' ),
		);
	}
}
