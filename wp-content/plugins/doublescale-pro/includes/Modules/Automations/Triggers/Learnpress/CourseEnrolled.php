<?php

/**
 * LearnPress Trigger for Course Enrolled
 * This trigger will be fired when a user enrolls in a course.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Learnpress;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Course Enrolled Trigger
 */
class CourseEnrolled extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Course Enrolled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'learnpress_course_enrolled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user enrolls in a LearnPress course.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'lms';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'learnpress';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		// Primary hook for course enrollment
		add_action( 'learn-press/user-enrolled-course', array( $this, 'course_enrolled' ), 10, 2 );
		// Alternative hook (some versions use this)
		add_action( 'learnpress/user/course-enrolled', array( $this, 'course_enrolled_alt' ), 10, 3 );
	}

	/**
	 * Course Enrolled (primary hook)
	 *
	 * @since 1.0.0
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function course_enrolled( $course_id, $user_id ) {
		$user = get_user_by( 'ID', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'course_id' => $course_id,
				'user_id'   => $user_id,
			),
		);

		$this->process( $data );
	}

	/**
	 * Course Enrolled (alternative hook)
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $ref Reference.
	 * @param int   $course_id Course ID.
	 * @param int   $user_id User ID.
	 * @return void
	 */
	public function course_enrolled_alt( $ref, $course_id, $user_id ) {
		$this->course_enrolled( $course_id, $user_id );
	}
}
