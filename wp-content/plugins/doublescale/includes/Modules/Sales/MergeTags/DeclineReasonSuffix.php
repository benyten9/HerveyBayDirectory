<?php
/**
 * Proposal decline reason suffix for sales-rep notifications.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;

/**
 * DeclineReasonSuffix merge tag.
 */
class DeclineReasonSuffix extends AbstractSalesMergeTag {

	public $name = 'Decline Reason Suffix';

	public $slug = 'decline_reason_suffix';

	public $description = 'Appends the customer decline reason when a proposal was declined.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		unset( $merge_tag );
		$proposal = $this->resolve_proposal( $contact );
		if ( ! $proposal || ProposalStatus::DECLINED !== (string) $proposal->status || ! $proposal->decline_reason ) {
			return '';
		}

		return ' — ' . (string) $proposal->decline_reason;
	}
}

MergeTagsManager::instance()->register( new DeclineReasonSuffix() );
