<?php
/**
 * Contract number merge tag.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\MergeTags\AbstractContractSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * ContractNumber merge tag.
 */
class ContractNumber extends AbstractContractSalesMergeTag {

	public $name = 'Contract Number';

	public $slug = 'contract_number';

	public $description = 'Contract reference number.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$contract = $this->resolve_contract( $contact );
		return $contract ? (string) $contract->contract_number : '';
	}
}

MergeTagsManager::instance()->register( new ContractNumber() );
