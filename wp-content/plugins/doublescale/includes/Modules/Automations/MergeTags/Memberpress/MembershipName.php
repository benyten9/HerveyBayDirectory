<?php

/**
 * Class Membership Name
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
 * Membership Name Merge Tag
 */
class MembershipName extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Membership Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'membership_name';

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
		$name = $contact->get_data( 'membership_name' );

		if ( $name ) {
			return $name;
		}

		$membership_id = $contact->get_data( 'membership_id' );
		if ( $membership_id ) {
			return get_the_title( $membership_id );
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new MembershipName() );
