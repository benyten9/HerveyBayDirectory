<?php
/**
 * Automation trigger: contract sent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;

defined( 'ABSPATH' ) || exit;

final class ContractSent extends AbstractSalesLifecycleTrigger {

	public $name = 'Contract sent';

	public $slug = 'contract_sent';

	public $description = 'Fires when a contract is emailed to the customer.';

	public $attributes = array();

	public $group = 'contracts';

	public function load_hooks(): void {
		add_action( 'doublescale_sales_contract_sent', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $contract {@see ContractModel} instance.
	 * @param mixed $message  Optional custom message.
	 */
	public function handle( $contract, $message = '' ): void {
		$this->enroll_from_contract(
			$contract,
			array(
				'message' => is_string( $message ) ? $message : '',
			)
		);
	}
}
