<?php

/**
 * Review Rate Merge Tag
 *
 * This class is responsible for handling the review rate merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Review;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Review Rate Merge Tag
 */
class ReviewRate extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Review Rate';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'review_rate';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Review Rate';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'review';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array( 'wc_review_received' );

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$review_rate = $contact->get_data( 'review_rating' );

		if ( ! $review_rate ) {
			return '';
		}

		return (string) $review_rate;
	}
}

MergeTagsManager::instance()->register( new ReviewRate() );
