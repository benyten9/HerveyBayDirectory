<?php
/**
 * Base for credit note automation merge tags.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Sales\MergeTags\AbstractSalesMergeTag;
use DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel;

/**
 * AbstractCreditNoteSalesMergeTag class.
 */
abstract class AbstractCreditNoteSalesMergeTag extends AbstractSalesMergeTag {

	/**
	 * @var array<int, string>
	 */
	public $required_triggers = array(
		'credit_note_sent',
		'credit_note_applied',
	);

	/**
	 * @param AutomationContactModel|null $contact Contact.
	 * @return CreditNoteModel|null
	 */
	protected function resolve_credit_note( $contact ): ?CreditNoteModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'credit_notes', CreditNoteModel::class ) ) {
			return null;
		}
		$credit_note_id = (int) ( $contact->data['credit_note_id'] ?? 0 );
		if ( $credit_note_id <= 0 ) {
			return null;
		}
		$credit_note = CreditNoteModel::find( $credit_note_id );
		return $credit_note instanceof CreditNoteModel ? $credit_note : null;
	}

	/**
	 * @param CreditNoteModel $credit_note Credit note.
	 * @return string
	 */
	protected function format_credit_note_money( CreditNoteModel $credit_note, string $field = 'total' ): string {
		$amount = 'remaining' === $field
			? max( 0, (float) $credit_note->total - (float) $credit_note->amount_applied )
			: (float) $credit_note->total;
		$total  = number_format( $amount, 2, '.', '' );
		$currency = \DoubleScale\Pro\Compat\SettingsCurrency::document_currency( $credit_note->currency, $credit_note->sent_at );
		return trim( $currency . ' ' . $total );
	}
}
