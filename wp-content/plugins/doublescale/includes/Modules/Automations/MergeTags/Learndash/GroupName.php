<?php

/**
 * Class Group Name Merge Tag
 *
 * This class is responsible for handling the group name merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Learndash;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Group Name Merge Tag
 */
class GroupName extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Group Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'group_name';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Group Name';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array( 'learndash_user_added_group' );

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'learndash';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$group_id = $contact->get_data( 'group_id' );

		$group = get_post( $group_id );
		if ( ! empty( $group ) ) {
			return $group->post_title;
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new GroupName() );
