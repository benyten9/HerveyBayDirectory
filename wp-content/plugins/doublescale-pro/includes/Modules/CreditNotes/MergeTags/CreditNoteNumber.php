<?php
/**
 * Credit note number merge tag.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * CreditNoteNumber merge tag.
 */
class CreditNoteNumber extends AbstractCreditNoteSalesMergeTag {

	public $name = 'Credit Note Number';

	public $slug = 'credit_note_number';

	public $description = 'Credit note reference number.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$credit_note = $this->resolve_credit_note( $contact );
		return $credit_note ? (string) $credit_note->credit_note_number : '';
	}
}

MergeTagsManager::instance()->register( new CreditNoteNumber() );
