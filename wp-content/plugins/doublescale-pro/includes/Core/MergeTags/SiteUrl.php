<?php
/**
 * Class SiteUrl
 *
 * Merge tag for site url
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Core\MergeTags;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Site URL Merge Tag
 */
class SiteUrl extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Site URL';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'site_url';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Site URL';

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
		return get_site_url();
	}
}

MergeTagsManager::instance()->register( new SiteUrl() );
