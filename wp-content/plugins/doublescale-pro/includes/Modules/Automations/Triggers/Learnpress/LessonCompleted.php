<?php

/**
 * LearnPress Trigger for Lesson Completed
 * This trigger will be fired when a user completes a lesson.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Learnpress;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Lesson Completed Trigger
 */
class LessonCompleted extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Lesson Completed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'learnpress_lesson_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user completes a LearnPress lesson.';

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
		add_action( 'learn-press/user-completed-lesson', array( $this, 'lesson_completed' ), 10, 3 );
	}

	/**
	 * Lesson Completed
	 *
	 * The learn-press/user-completed-lesson hook passes ($lesson_id, $result, $user_id).
	 *
	 * @since 1.0.0
	 *
	 * @param int   $lesson_id Lesson ID.
	 * @param mixed $result Result data.
	 * @param int   $user_id User ID.
	 * @return void
	 */
	public function lesson_completed( $lesson_id, $result, $user_id ) {
		$user = get_user_by( 'ID', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		// Get the course ID from the lesson.
		$course_id = get_post_meta( $lesson_id, '_lp_course', true );

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'lesson_id' => $lesson_id,
				'course_id' => $course_id,
				'user_id'   => $user_id,
			),
		);

		$this->process( $data );
	}
}
