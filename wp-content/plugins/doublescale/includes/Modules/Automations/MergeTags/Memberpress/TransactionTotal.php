<?php

/**
 * Class Transaction Total
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Memberpress;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Transaction Total Merge Tag
 */
class TransactionTotal extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Transaction Total';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'transaction_total';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'memberpress';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$total = $contact->get_data( 'total' );

		return $total ? (string) $total : '0';
	}
}

MergeTagsManager::instance()->register( new TransactionTotal() );
