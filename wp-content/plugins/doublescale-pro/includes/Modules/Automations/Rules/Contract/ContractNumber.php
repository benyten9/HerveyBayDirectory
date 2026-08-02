<?php
/**
 * Automation rule: contract number.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Contract;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

class ContractNumber extends BaseContractRule {

	public $name = 'Contract Number';

	public $slug = 'contract_number';

	public $type = 'text';

	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'contains'     => __( 'Contains', 'doublescale' ),
			'not_contains' => __( 'Does not contain', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$contract = $this->resolve_contract( $automation_contact );
		return $contract ? (string) $contract->contract_number : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		return parent::is_met( $automation_contact, $rule );
	}
}

BaseContractRule::register( new ContractNumber() );
