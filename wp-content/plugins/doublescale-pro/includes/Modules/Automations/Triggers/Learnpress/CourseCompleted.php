<?php

/**
 * LearnPress Trigger for Course Completed
 * This trigger will be fired when a user completes a course.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Learnpress;

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
	public $slug = 'learnpress_course_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user completes a LearnPress course.';

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
		add_action( 'learn-press/user-course/finish', array( $this, 'course_completed' ), 10, 1 );
	}

	/**
	 * Course Completed
	 *
	 * The learn-press/user-course/finish hook passes a single object parameter
	 * containing item_id (course_id) and user_id properties.
	 *
	 * @since 1.0.0
	 *
	 * @param object $course_data Course data object with item_id and user_id properties.
	 * @return void
	 */
	public function course_completed( $course_data ) {
		// Extract course_id and user_id from the object.
		$course_id = isset( $course_data->item_id ) ? $course_data->item_id : null;
		$user_id   = isset( $course_data->user_id ) ? $course_data->user_id : null;

		if ( ! $course_id || ! $user_id ) {
			return;
		}

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
