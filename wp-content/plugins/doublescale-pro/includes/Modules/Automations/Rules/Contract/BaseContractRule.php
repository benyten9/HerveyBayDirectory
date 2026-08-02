<?php
/**
 * Shared base for contract automation rules (conditions).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Contract;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;

defined( 'ABSPATH' ) || exit;

abstract class BaseContractRule extends Rule {

	/**
	 * @var string
	 */
	public $group = 'contract';

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'contract_sent',
		'contract_signed',
	);

	/**
	 * @param object $automation_contact Automation contact model.
	 * @return ContractModel|null
	 */
	protected function resolve_contract( $automation_contact ): ?ContractModel {
		if ( ! self::storage_ready() ) {
			return null;
		}

		$contract_id = isset( $automation_contact->data['contract_id'] )
			? (int) $automation_contact->data['contract_id']
			: 0;

		if ( $contract_id <= 0 ) {
			return null;
		}

		$contract = ContractModel::find( $contract_id );
		return $contract instanceof ContractModel ? $contract : null;
	}

	/**
	 * @param Rule $rule Rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'contracts', ContractModel::class );
	}

	/**
	 * Whether contract storage is safe to query.
	 */
	protected static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'contracts', ContractModel::class );
	}
}
