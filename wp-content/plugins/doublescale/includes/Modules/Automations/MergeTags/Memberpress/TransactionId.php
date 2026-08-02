<?php

/**
 * Class Transaction ID
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
 * Transaction ID Merge Tag
 */
class TransactionId extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Transaction ID';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'transaction_id';

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
		$transaction_id = $contact->get_data( 'transaction_id' );

		return $transaction_id ? (string) $transaction_id : '';
	}
}

MergeTagsManager::instance()->register( new TransactionId() );
