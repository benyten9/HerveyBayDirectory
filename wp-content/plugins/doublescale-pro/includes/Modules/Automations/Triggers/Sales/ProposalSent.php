<?php
/**
 * Automation trigger: proposal sent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

use DoubleScale\Modules\Documents\Models\ProposalModel;

defined( 'ABSPATH' ) || exit;

final class ProposalSent extends AbstractSalesLifecycleTrigger {

	public $name = 'Proposal sent';

	public $slug = 'proposal_sent';

	public $description = 'Fires when a proposal is emailed to the customer.';

	public $attributes = array();

	public function load_hooks(): void {
		add_action( 'doublescale_sales_proposal_sent', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $proposal {@see ProposalModel} instance.
	 * @param mixed $message  Optional custom message.
	 */
	public function handle( $proposal, $message = '' ): void {
		$this->enroll_from_proposal(
			$proposal,
			array(
				'message' => is_string( $message ) ? $message : '',
			)
		);
	}
}
