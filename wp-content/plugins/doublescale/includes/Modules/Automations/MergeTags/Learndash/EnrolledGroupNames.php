<?php

/**
 * Class Enrolled Group Names Merge Tag
 *
 * This class is responsible for handling the enrolled group names merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Learndash;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Enrolled Group Names Merge Tag
 */
class EnrolledGroupNames extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Enrolled Group Names (Comma Separated)';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'enrolled_group_names';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Enrolled Group Names';

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
		$contact_id = $contact->contact_id;
		$contact    = ContactModel::find( $contact_id );

		if ( ! $contact ) {
			return '';
		}

		$user = get_user_by( 'email', $contact->email );

		if ( ! $user ) {
			return '';
		}

		$enrolled_groups = learndash_get_users_group_ids( $user->ID );
		$group_names     = array();

		foreach ( $enrolled_groups as $group_id ) {
			$group_names[] = get_the_title( $group_id );
		}

		return implode( ', ', $group_names );
	}
}

MergeTagsManager::instance()->register( new EnrolledGroupNames() );
