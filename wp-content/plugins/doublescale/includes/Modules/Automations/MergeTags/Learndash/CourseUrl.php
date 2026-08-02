<?php

/**
 * Class Course URL Merge Tag
 *
 * This class is responsible for handling the course URL merge tag
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
 * Course URL Merge Tag
 */
class CourseUrl extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Course URL';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'course_url';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Course URL';

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
		$course_id  = $contact->get_data( 'course_id' );
		$course_url = get_permalink( $course_id );

		return $course_url ?? '';
	}
}

MergeTagsManager::instance()->register( new CourseUrl() );
