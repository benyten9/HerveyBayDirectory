<?php

/**
 * Class Candidate Name Merge Tag
 *
 * This class is responsible for handling the candidate name merge tag
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
 * Candidate Name Merge Tag
 */
class CandidateName extends MergeTag {




	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Candidate Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'candidate_name';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Candidate Name';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array( 'learndash_course_completed', 'learndash_lesson_completed', 'learndash_topic_completed', 'learndash_user_added_group', 'learndash_user_enrolled_course', 'learndash_user_left_course' );

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
		$user_id = $contact->get_data( 'user_id' );
		$user    = get_user_by( 'ID', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		return $user->display_name ?? '';
	}
}

MergeTagsManager::instance()->register( new CandidateName() );
