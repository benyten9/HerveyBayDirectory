<?php
/**
 * Proposal subject merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\MergeTags\AbstractProposalSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * ProposalSubject merge tag.
 */
class ProposalSubject extends AbstractProposalSalesMergeTag {

	public $name = 'Proposal Subject';

	public $slug = 'proposal_subject';

	public $description = 'Proposal subject line.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$proposal = $this->resolve_proposal( $contact );
		return $proposal ? (string) $proposal->subject : '';
	}
}

MergeTagsManager::instance()->register( new ProposalSubject() );
