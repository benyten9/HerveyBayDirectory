<?php
/**
 * Remaining credit merge tag.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * CreditNoteRemaining merge tag.
 */
class CreditNoteRemaining extends AbstractCreditNoteSalesMergeTag {

	public $name = 'Credit Note Remaining';

	public $slug = 'credit_note_remaining';

	public $description = 'Remaining credit available on the credit note.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$credit_note = $this->resolve_credit_note( $contact );
		return $credit_note ? $this->format_credit_note_money( $credit_note, 'remaining' ) : '';
	}
}

MergeTagsManager::instance()->register( new CreditNoteRemaining() );
