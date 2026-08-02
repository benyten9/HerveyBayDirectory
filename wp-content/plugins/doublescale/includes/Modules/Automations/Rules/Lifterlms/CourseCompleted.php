<?php

/**
 * Class CourseCompleted
 *
 * This class is responsible for handling the course completed rule for LifterLMS
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Lifterlms;

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
	public $slug = 'lifterlms_course_completed';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'lifterlms';

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

		if ( ! defined( 'LLMS_PLUGIN_FILE' ) ) {
			return $options;
		}

		$courses = get_posts(
			array(
				'post_type'   => 'course',
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

		$completed_courses = array();

		if ( function_exists( 'llms_get_student' ) ) {
			$student = llms_get_student( $user->ID );
			if ( $student ) {
				$courses = $student->get_completed_courses( array( 'limit' => 10000 ) );
				if ( ! empty( $courses['results'] ) ) {
					$completed_courses = $courses['results'];
				}
			}
		}

		return array_map( 'intval', array_filter( $completed_courses ) );
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

		if ( ! is_array( $rule_courses ) ) {
			$rule_courses = array();
		}

		$rule_courses = array_map( 'intval', $rule_courses );

		switch ( $operator ) {
			case 'includes':
				return ! empty( array_intersect( $completed_courses, $rule_courses ) );

			case 'not_includes':
				return empty( array_intersect( $completed_courses, $rule_courses ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( \doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ) ) {
			RulesManager::instance()->register( new CourseCompleted() );
		}
	},
	99
);
