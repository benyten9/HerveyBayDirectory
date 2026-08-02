<?php

/**
 * Class Transaction Amount
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
 * Transaction Amount Merge Tag
 */
class TransactionAmount extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Transaction Amount';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'transaction_amount';

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
		$amount = $contact->get_data( 'amount' );

		return $amount ? (string) $amount : '0';
	}
}

MergeTagsManager::instance()->register( new TransactionAmount() );
