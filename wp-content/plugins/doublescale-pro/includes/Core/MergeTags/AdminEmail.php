<?php
/**
 * Class AdminEmail
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
 * AdminEmail class
 */
class AdminEmail extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Administration Email';

	/**
	 * Tag
	 *
	 * @var string
	 */
	public $slug = 'admin_email';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'Website administration email';

	/**
	 * Group
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
		return get_option( 'admin_email' );
	}
}

MergeTagsManager::instance()->register( new AdminEmail() );
