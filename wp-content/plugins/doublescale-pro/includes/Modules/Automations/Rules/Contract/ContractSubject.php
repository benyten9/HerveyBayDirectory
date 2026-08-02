<?php
/**
 * Automation rule: contract subject.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Contract;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;

defined( 'ABSPATH' ) || exit;

class ContractSubject extends BaseContractRule {

	public $name = 'Contract Subject';

	public $slug = 'contract_subject';

	public $type = 'text';

	public function get_operators() {
		return array(
			'contains'     => __( 'Contains', 'doublescale' ),
			'not_contains' => __( 'Does not contain', 'doublescale' ),
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'starts_with'  => __( 'Starts with', 'doublescale' ),
			'ends_with'    => __( 'Ends with', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$contract = $this->resolve_contract( $automation_contact );
		return $contract ? (string) $contract->subject : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		return parent::is_met( $automation_contact, $rule );
	}
}

BaseContractRule::register( new ContractSubject() );
