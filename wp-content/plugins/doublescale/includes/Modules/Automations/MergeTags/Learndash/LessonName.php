<?php

/**
 * Class for Lesson Name Merge Tag
 *
 * This class is responsible for handling the lesson name merge tag
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
 * Lesson Name Merge Tag
 */
class LessonName extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Lesson Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'lesson_name';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Lesson Name';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array( 'learndash_lesson_completed' );

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
		$lesson_id = $contact->get_data( 'lesson_id' );
		$lesson    = get_post( $lesson_id );

		if ( ! $lesson ) {
			return '';
		}

		return $lesson->post_title;
	}
}

MergeTagsManager::instance()->register( new LessonName() );
