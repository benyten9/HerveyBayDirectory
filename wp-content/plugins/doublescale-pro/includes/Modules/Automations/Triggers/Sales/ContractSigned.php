<?php
/**
 * Automation trigger: contract signed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Sales;

use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;

defined( 'ABSPATH' ) || exit;

final class ContractSigned extends AbstractSalesLifecycleTrigger {

	public $name = 'Contract signed';

	public $slug = 'contract_signed';

	public $description = 'Fires when a customer signs a contract.';

	public $attributes = array();

	public $group = 'contracts';

	public function load_hooks(): void {
		add_action( 'doublescale_sales_contract_signed', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * @param mixed $contract {@see ContractModel} instance.
	 */
	public function handle( $contract ): void {
		$this->enroll_from_contract( $contract );
	}
}
