<?php

/**
 * Class Member Name
 *
 * This class is responsible for handling the member name merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Membership;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Member Name Merge Tag
 */
class MemberName extends MergeTag {





	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Member Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'wcm_member_name';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Member Name';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'membership';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$member_name = $contact->get_data( 'member_name' );

		return $member_name;
	}
}

MergeTagsManager::instance()->register( new MemberName() );
