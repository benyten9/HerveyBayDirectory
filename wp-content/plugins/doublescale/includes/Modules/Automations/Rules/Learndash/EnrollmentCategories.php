<?php

/**
 * Class EnrollmentCategories
 *
 * This class is responsible for handling the enrollment categories rule
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
 * Enrollment Categories class
 */
class EnrollmentCategories extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Enrollment Categories';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'learndash_enrollment_categories';

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
	 * Has options
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_options() {
		return true;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'matches_any_of'  => __( 'Matches any of', 'doublescale' ),
			'matches_none_of' => __( 'Matches none of', 'doublescale' ),
			'matches_all_of'  => __( 'Matches all of', 'doublescale' ),
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
		$categories = get_terms(
			array(
				'taxonomy'   => 'ld_course_category',
				'hide_empty' => false,
			)
		);

		$options = array();
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			foreach ( $categories as $category ) {
				if ( isset( $category->term_id ) && isset( $category->name ) ) {
					$options[ $category->term_id ] = wp_kses_post( $category->name );
				}
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
	 * @return array Array of category IDs from enrolled courses
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

		$enrolled_course_categories = array();

		// Get enrolled courses
		if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) {
			$enrolled_courses = learndash_user_get_enrolled_courses( $user->ID );
		} else {
			// Fallback: Get courses from user meta
			$enrolled_courses = get_user_meta( $user->ID, '_sfwd-courses', true );
			if ( ! is_array( $enrolled_courses ) ) {
				$enrolled_courses = array();
			}
		}

		if ( empty( $enrolled_courses ) ) {
			return array();
		}

		// Get categories for each enrolled course
		foreach ( $enrolled_courses as $course_id ) {
			$course_categories = get_the_terms( $course_id, 'ld_course_category' );

			if ( ! is_wp_error( $course_categories ) && ! empty( $course_categories ) ) {
				foreach ( $course_categories as $category ) {
					if ( isset( $category->term_id ) ) {
						$enrolled_course_categories[] = (int) $category->term_id;
					}
				}
			}
		}

		// Remove duplicates and ensure integers
		return array_unique( array_map( 'intval', $enrolled_course_categories ) );
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
		$enrolled_categories = $this->get_value( $automation_contact );
		$operator            = $rule['operator'] ?? '';
		$rule_categories     = $rule['value'] ?? array();

		// Ensure rule_categories is an array
		if ( ! is_array( $rule_categories ) ) {
			$rule_categories = array( $rule_categories );
		}

		// Ensure enrolled_categories is an array
		if ( ! is_array( $enrolled_categories ) ) {
			$enrolled_categories = array();
		}

		// Convert to integers for comparison
		$enrolled_categories = array_map( 'intval', $enrolled_categories );
		$rule_categories     = array_map( 'intval', $rule_categories );

		switch ( $operator ) {
			case 'matches_any_of':
				// User has courses in at least one of the specified categories
				return ! empty( array_intersect( $enrolled_categories, $rule_categories ) );

			case 'matches_none_of':
				// User has no courses in any of the specified categories
				return empty( array_intersect( $enrolled_categories, $rule_categories ) );

			case 'matches_all_of':
				// User has courses in all of the specified categories
				return empty( array_diff( $rule_categories, $enrolled_categories ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'SFWD_LMS' ) ) {
			RulesManager::instance()->register( new EnrollmentCategories() );
		} else {
			add_action(
				'learndash_loaded',
				function () {
					RulesManager::instance()->register( new EnrollmentCategories() );
				}
			);
		}
	},
	99
);
