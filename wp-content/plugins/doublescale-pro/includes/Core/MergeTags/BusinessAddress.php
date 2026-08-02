<?php
/**
 * Class BusinessAddress
 *
 * Merge tag for business address from settings
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Core\MergeTags;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Pro\Settings;

/**
 * Business Address Merge Tag
 */
class BusinessAddress extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Business Address';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'business_address';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 *
	 */
	public $description = 'Business Address from Settings';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'general';

	/**
	 * Is automation merge tag
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Get Merge Tag Value
	 *
	 * @param ContactModel $contact Contact Model.
	 * @param string        $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		// Get business settings
		$business_settings = Settings::get( 'business', array() );
		
		// Return business address or empty string if not set
		return isset( $business_settings['business_address'] ) ? $business_settings['business_address'] : '';
	}
}

MergeTagsManager::instance()->register( new BusinessAddress() );

