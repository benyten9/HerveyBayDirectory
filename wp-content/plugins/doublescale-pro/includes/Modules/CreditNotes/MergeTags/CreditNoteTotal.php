<?php
/**
 * Credit note total merge tag.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * CreditNoteTotal merge tag.
 */
class CreditNoteTotal extends AbstractCreditNoteSalesMergeTag {

	public $name = 'Credit Note Total';

	public $slug = 'credit_note_total';

	public $description = 'Credit note total amount.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$credit_note = $this->resolve_credit_note( $contact );
		return $credit_note ? $this->format_credit_note_money( $credit_note ) : '';
	}
}

MergeTagsManager::instance()->register( new CreditNoteTotal() );
