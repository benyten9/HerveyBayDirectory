<?php
/**
 * Automation rule: contract status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Contract;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Contracts\Constants\ContractStatus as StatusConst;

defined( 'ABSPATH' ) || exit;

class ContractStatus extends BaseContractRule {

	public $name = 'Contract Status';

	public $slug = 'contract_status';

	public $type = 'select';

	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	public function get_options() {
		$options = array();
		foreach ( StatusConst::all() as $status ) {
			$options[ $status ] = StatusConst::get_label( $status );
		}
		return $options;
	}

	public function get_value( $automation_contact ) {
		$contract = $this->resolve_contract( $automation_contact );
		return $contract ? (string) $contract->status : '';
	}

	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value = $this->get_value( $automation_contact );
		switch ( $rule['operator'] ?? '' ) {
			case 'is':
				return $value == $rule['value']; // phpcs:ignore
			case 'is_not':
				return $value != $rule['value']; // phpcs:ignore
			default:
				return false;
		}
	}
}

BaseContractRule::register( new ContractStatus() );
