<?php
/**
 * Proposal valid-until date merge tag.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Sales\MergeTags\AbstractProposalSalesMergeTag;

/**
 * ProposalOpenTill merge tag.
 */
class ProposalOpenTill extends AbstractProposalSalesMergeTag {

	public $name = 'Proposal Valid Until';

	public $slug = 'proposal_open_till';

	public $description = 'Date until which the proposal remains valid.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$proposal = $this->resolve_proposal( $contact );
		return $proposal && $proposal->open_till ? (string) $proposal->open_till : '';
	}
}

MergeTagsManager::instance()->register( new ProposalOpenTill() );
