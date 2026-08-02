<?php
/**
 * Contract public URL merge tag.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\MergeTags\AbstractContractSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * ContractUrl merge tag.
 */
class ContractUrl extends AbstractContractSalesMergeTag {

	public $name = 'Contract URL';

	public $slug = 'contract_url';

	public $description = 'Public link for the customer to view the contract.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$contract = $this->resolve_contract( $contact );
		return $contract ? $this->contract_public_url( $contract ) : '';
	}
}

MergeTagsManager::instance()->register( new ContractUrl() );
