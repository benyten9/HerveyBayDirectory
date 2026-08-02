<?php
/**
 * Base for contract automation merge tags.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Sales\MergeTags\AbstractSalesMergeTag;
use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Contracts\Services\ContractUrl;

/**
 * AbstractContractSalesMergeTag class.
 */
abstract class AbstractContractSalesMergeTag extends AbstractSalesMergeTag {

	/**
	 * @var array<int, string>
	 */
	public $required_triggers = array(
		'contract_sent',
		'contract_signed',
	);

	/**
	 * @param AutomationContactModel|null $contact Contact.
	 * @return ContractModel|null
	 */
	protected function resolve_contract( $contact ): ?ContractModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}
		if ( $contact->relationLoaded( 'contract' ) ) {
			$related = $contact->getRelation( 'contract' );
			if ( $related instanceof ContractModel ) {
				return $related;
			}
		}
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'contracts', ContractModel::class ) ) {
			return null;
		}
		$contract_id = (int) ( $contact->data['contract_id'] ?? 0 );
		if ( $contract_id <= 0 ) {
			return null;
		}
		$contract = ContractModel::find( $contract_id );
		return $contract instanceof ContractModel ? $contract : null;
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return string
	 */
	protected function format_contract_money( ContractModel $contract ): string {
		$total    = number_format( (float) $contract->contract_value, 2, '.', '' );
		$currency = \DoubleScale\Core\Settings\Settings::document_currency( $contract->currency, $contract->sent_at );
		return trim( $currency . ' ' . $total );
	}

	/**
	 * @param ContractModel $contract Contract.
	 * @return string
	 */
	protected function contract_public_url( ContractModel $contract ): string {
		return ContractUrl::get_public_url( $contract );
	}
}
