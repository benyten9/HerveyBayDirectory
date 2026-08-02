<?php

/**
 * LifterLMS Trigger for Course Completed
 * This trigger will be fired when a user completes a course.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Lifterlms;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Course Completed Trigger
 */
class CourseCompleted extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Course Completed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'lifterlms_course_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user completes a LifterLMS course.';

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
		add_action( 'lifterlms_course_completed', array( $this, 'course_completed' ), 10, 2 );
	}

	/**
	 * Course Completed
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function course_completed( $user_id, $course_id ) {
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
