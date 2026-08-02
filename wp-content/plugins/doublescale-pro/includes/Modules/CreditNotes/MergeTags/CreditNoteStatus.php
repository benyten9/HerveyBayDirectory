<?php
/**
 * Credit note status merge tag.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus as StatusConst;

/**
 * CreditNoteStatus merge tag.
 */
class CreditNoteStatus extends AbstractCreditNoteSalesMergeTag {

	public $name = 'Credit Note Status';

	public $slug = 'credit_note_status';

	public $description = 'Current status of the credit note.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$credit_note = $this->resolve_credit_note( $contact );
		return $credit_note ? StatusConst::get_label( (string) $credit_note->status ) : '';
	}
}

MergeTagsManager::instance()->register( new CreditNoteStatus() );
