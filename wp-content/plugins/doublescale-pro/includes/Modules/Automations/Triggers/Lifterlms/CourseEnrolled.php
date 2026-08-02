<?php

/**
 * LifterLMS Trigger for Course Enrolled
 * This trigger will be fired when a user enrolls in a course.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Lifterlms;

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
	public $slug = 'lifterlms_course_enrolled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user enrolls in a LifterLMS course.';

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
	public $group = 'lifterlms';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'llms_user_enrolled_in_course', array( $this, 'course_enrolled' ), 10, 2 );
	}

	/**
	 * Course Enrolled
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function course_enrolled( $user_id, $course_id ) {
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
}
