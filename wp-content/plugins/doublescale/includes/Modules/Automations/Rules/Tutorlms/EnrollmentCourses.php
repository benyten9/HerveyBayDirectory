<?php

/**
 * Class EnrollmentCourses
 *
 * This class is responsible for handling the enrollment courses rule for TutorLMS
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Tutorlms;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Enrollment Courses class
 */
class EnrollmentCourses extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Enrollment Courses';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'tutorlms_enrollment_courses';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'tutorlms';

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
			'includes'         => __( 'includes', 'doublescale' ),
			'not_includes_in'  => __( 'Does not include (in any)', 'doublescale' ),
			'includes_all'     => __( 'includes all', 'doublescale' ),
			'not_includes_all' => __( 'includes none of (match all)', 'doublescale' ),
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

		if ( ! function_exists( 'tutor' ) ) {
			return $options;
		}

		// Get all TutorLMS courses
		$courses = get_posts(
			array(
				'post_type'   => tutor()->course_post_type,
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

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
	 * @return array Array of enrolled course IDs
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return array();
		}

		// Get user by email
		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return array();
		}

		$enrolled_courses = array();

		// Get enrolled courses using TutorLMS function
		if ( function_exists( 'tutor_utils' ) ) {
			$enrolled_courses = tutor_utils()->get_enrolled_courses_ids_by_user( $user->ID );
		}

		// Ensure we return an array of integers
		if ( ! is_array( $enrolled_courses ) ) {
			$enrolled_courses = array();
		}

		return array_map( 'intval', array_filter( $enrolled_courses ) );
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
		$enrolled_courses = $this->get_value( $automation_contact );
		$operator         = $rule['operator'] ?? '';
		$rule_courses     = $rule['value'] ?? array();

		// Ensure rule_courses is an array
		if ( ! is_array( $rule_courses ) ) {
			$rule_courses = array();
		}

		// Convert to integers for comparison
		$rule_courses = array_map( 'intval', $rule_courses );

		switch ( $operator ) {
			case 'includes':
				// User is enrolled in at least one of the specified courses
				return ! empty( array_intersect( $enrolled_courses, $rule_courses ) );

			case 'not_includes_in':
				// User is not enrolled in any of the specified courses
				return empty( array_intersect( $enrolled_courses, $rule_courses ) );

			case 'includes_all':
				// User is enrolled in all of the specified courses
				return empty( array_diff( $rule_courses, $enrolled_courses ) );

			case 'not_includes_all':
				// User is not enrolled in all of the specified courses (missing at least one)
				return ! empty( array_diff( $rule_courses, $enrolled_courses ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( \doublescale_is_plugin_active( 'tutor/tutor.php' ) ) {
			RulesManager::instance()->register( new EnrollmentCourses() );
		}
	},
	99
);
