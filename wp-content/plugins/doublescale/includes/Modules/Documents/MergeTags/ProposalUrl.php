<?php
/**
 * Proposal public URL merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\MergeTags\AbstractProposalSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * ProposalUrl merge tag.
 */
class ProposalUrl extends AbstractProposalSalesMergeTag {

	public $name = 'Proposal URL';

	public $slug = 'proposal_url';

	public $description = 'Public link for the customer to view the proposal.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$proposal = $this->resolve_proposal( $contact );
		return $proposal ? $this->proposal_public_url( $proposal ) : '';
	}
}

MergeTagsManager::instance()->register( new ProposalUrl() );
