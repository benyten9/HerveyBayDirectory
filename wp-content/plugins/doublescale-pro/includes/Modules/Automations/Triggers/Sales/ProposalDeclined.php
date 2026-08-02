<?php
/**
 * Automation trigger: proposal declined.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

final class ProposalDeclined extends AbstractSalesLifecycleTrigger {

	public $name = 'Proposal declined';

	public $slug = 'proposal_declined';

	public $description = 'Fires when a customer declines a proposal.';

	public $attributes = array();

	public function load_hooks(): void {
		add_action( 'doublescale_sales_proposal_declined', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $proposal {@see \DoubleScale\Modules\Documents\Models\ProposalModel} instance.
	 * @param mixed $reason   Decline reason.
	 */
	public function handle( $proposal, $reason = '' ): void {
		$this->enroll_from_proposal(
			$proposal,
			array(
				'decline_reason' => is_string( $reason ) ? $reason : '',
			)
		);
	}
}
