<?php

/**
 * Class CourseCompleted
 *
 * This class is responsible for handling the course completed rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Learndash;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Course Completed class
 */
class CourseCompleted extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Course Completed';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'learndash_course_completed';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'learndash';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'multiselect';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'includes'     => __( 'includes', 'doublescale' ),
			'not_includes' => __( 'not includes', 'doublescale' ),
		);
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		$options = array();

		if ( function_exists( 'learndash_get_courses' ) ) {
			$courses = learndash_get_courses();

			if ( is_object( $courses ) && isset( $courses->posts ) ) {
				$courses = $courses->posts;
			}
		} else {
			// Fallback if LearnDash is not available
			$courses = get_posts(
				array(
					'post_type'   => 'sfwd-courses',
					'numberposts' => -1,
					'post_status' => 'publish',
				)
			);
		}

		if ( empty( $courses ) || ! is_array( $courses ) ) {
			return $options;
		}

		foreach ( $courses as $course ) {
			if ( is_object( $course ) ) {
				$id    = isset( $course->ID ) ? (int) $course->ID : 0;
				$title = get_the_title( $id );
			} elseif ( is_array( $course ) ) {
				$id    = isset( $course['ID'] ) ? (int) $course['ID'] : 0;
				$title = get_post_field( 'post_title', $id );
			} else {
				continue;
			}

			if ( $id ) {
				$options[ $id ] = wp_kses_post( $title );
			}
		}

		return $options;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return array Array of completed course IDs
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return array();
		}

		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return array();
		}

		// Fetch course progress data (the fastest way)
		$user_course_data = get_user_meta( $user->ID, '_sfwd-course_progress', true );

		if ( empty( $user_course_data ) || ! is_array( $user_course_data ) ) {
			return array();
		}

		$completed_courses = array();

		foreach ( $user_course_data as $course_id => $progress ) {
			// LearnDash stores 'completed' as timestamp or 1 when completed
			if ( ! empty( $progress['completed'] ) ) {
				$completed_courses[] = (int) $course_id;
			}
		}

		return $completed_courses;
	}


	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                  $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$completed_courses = $this->get_value( $automation_contact );
		$operator          = $rule['operator'] ?? '';
		$rule_courses      = $rule['value'] ?? array();

		// Ensure rule_courses is an array
		if ( ! is_array( $rule_courses ) ) {
			$rule_courses = array();
		}

		// Convert to integers for comparison
		$rule_courses = array_map( 'intval', $rule_courses );

		switch ( $operator ) {
			case 'includes':
				// User has completed at least one of the specified courses
				return ! empty( array_intersect( $completed_courses, $rule_courses ) );

			case 'not_includes':
				// User has not completed any of the specified courses
				return empty( array_intersect( $completed_courses, $rule_courses ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'SFWD_LMS' ) ) {
			RulesManager::instance()->register( new CourseCompleted() );
		} else {
			add_action(
				'learndash_loaded',
				function () {
					RulesManager::instance()->register( new CourseCompleted() );
				}
			);
		}
	},
	99
);
