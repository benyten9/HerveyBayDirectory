<?php
/**
 * Contract subject merge tag.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\MergeTags\AbstractContractSalesMergeTag;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * ContractSubject merge tag.
 */
class ContractSubject extends AbstractContractSalesMergeTag {

	public $name = 'Contract Subject';

	public $slug = 'contract_subject';

	public $description = 'Contract subject line.';

	/**
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag Merge tag.
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$contract = $this->resolve_contract( $contact );
		return $contract ? (string) $contract->subject : '';
	}
}

MergeTagsManager::instance()->register( new ContractSubject() );
