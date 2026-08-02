<?php

/**
 * LearnDash Trigger for Course Completed
 * This trigger will be fired when a user completes a course.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Learndash;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Course Completed Trigger
 */
class CourseCompleted extends Trigger
{

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
	public $slug = 'learndash_course_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user completes a course.';

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
	public $group = 'learndash';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('learndash_course_completed', array($this, 'course_completed'), 10, 1);
	}

	/**
	 * Course Completed
	 *
	 * @since 1.0.0
	 *
	 * @param int $course Course.
	 * @return void
	 */
	public function course_completed($course)
	{
		$user_id   = $course['user']->ID;
		$course_id = $course['course']->ID;
		$progress  = $course['progress'];
		$user      = get_user_by('ID', $user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'course_id' => $course_id,
				'user_id'   => $user_id,
				'progress'  => $progress,
			),
		);

		$this->process($data);
		error_log('Course Completed Trigger: ' . json_encode($data));
	}
}
