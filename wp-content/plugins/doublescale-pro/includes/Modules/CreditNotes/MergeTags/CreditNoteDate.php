<?php
/**
 * Credit note issue date merge tag.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * CreditNoteDate merge tag.
 */
class CreditNoteDate extends AbstractCreditNoteSalesMergeTag {

	public $name = 'Credit Note Date';

	public $slug = 'credit_note_date';

	public $description = 'Credit note issue date.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$credit_note = $this->resolve_credit_note( $contact );
		return $credit_note && $credit_note->credit_note_date ? (string) $credit_note->credit_note_date : '';
	}
}

MergeTagsManager::instance()->register( new CreditNoteDate() );
