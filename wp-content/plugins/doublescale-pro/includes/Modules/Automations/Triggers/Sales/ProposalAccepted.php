<?php
/**
 * Automation trigger: proposal accepted.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

defined( 'ABSPATH' ) || exit;

final class ProposalAccepted extends AbstractSalesLifecycleTrigger {

	public $name = 'Proposal accepted';

	public $slug = 'proposal_accepted';

	public $description = 'Fires when a customer accepts a proposal.';

	public $attributes = array();

	public function load_hooks(): void {
		add_action( 'doublescale_sales_proposal_accepted', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * @param mixed $proposal {@see \DoubleScale\Modules\Documents\Models\ProposalModel} instance.
	 */
	public function handle( $proposal ): void {
		$this->enroll_from_proposal( $proposal );
	}
}
