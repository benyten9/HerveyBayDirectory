<?php
/**
 * Credit note public URL merge tag.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Pro\Modules\CreditNotes\Services\CreditNoteUrl as CreditNoteUrlService;

/**
 * CreditNoteUrl merge tag.
 */
class CreditNoteUrl extends AbstractCreditNoteSalesMergeTag {

	public $name = 'Credit Note URL';

	public $slug = 'credit_note_url';

	public $description = 'Public link to view the credit note.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$credit_note = $this->resolve_credit_note( $contact );
		return $credit_note ? CreditNoteUrlService::get_public_url( $credit_note ) : '';
	}
}

MergeTagsManager::instance()->register( new CreditNoteUrl() );
