<?php

/**
 * LearnDash Trigger for Topic Completed
 * This trigger will be fired when a user completes a topic.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Learndash;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Topic Completed Trigger
 */
class TopicCompleted extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Topic Completed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'learndash_topic_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user completes a topic.';

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
		add_action('learndash_topic_completed', array($this, 'topic_completed'), 10, 1);
	}

	/**
	 * Topic Completed
	 *
	 * @since 1.0.0
	 *
	 * @param array $topic Topic.
	 * @return void
	 */
	public function topic_completed($topic)
	{
		$user_id   = $topic['user']->ID;
		$course_id = $topic['course']->ID;
		$lesson_id = $topic['lesson']->ID;
		$topic_id  = $topic['topic']->ID;
		$progress  = $topic['progress'];

		$user = get_user_by('ID', $user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'course_id' => $course_id,
				'lesson_id' => $lesson_id,
				'topic_id'  => $topic_id,
				'progress'  => $progress,
				'user_id'   => $user_id,
			),
		);

		$this->process($data);
	}
}
