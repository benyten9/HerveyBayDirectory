<?php
/**
 * Proposal number merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\MergeTags\AbstractProposalSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * ProposalNumber merge tag.
 */
class ProposalNumber extends AbstractProposalSalesMergeTag {

	public $name = 'Proposal Number';

	public $slug = 'proposal_number';

	public $description = 'Proposal reference number.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$proposal = $this->resolve_proposal( $contact );
		return $proposal ? (string) $proposal->proposal_number : '';
	}
}

MergeTagsManager::instance()->register( new ProposalNumber() );
