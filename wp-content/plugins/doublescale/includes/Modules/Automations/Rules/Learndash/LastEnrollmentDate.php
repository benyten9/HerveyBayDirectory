<?php

/**
 * Class LastEnrollmentDate
 *
 * This class is responsible for handling the last enrollment date rule
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
 * Last Enrollment Date class
 */
class LastEnrollmentDate extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Last Enrollment Date';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'learndash_last_enrollment_date';

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
	public $type = 'date';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'before'  => __( 'Before', 'doublescale' ),
			'after'   => __( 'After', 'doublescale' ),
			'on'      => __( 'On', 'doublescale' ),
			'between' => __( 'Between', 'doublescale' ),
			'within'  => __( 'Within', 'doublescale' ),
		);
	}




	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return string|null Last enrollment date in Y-m-d format or null if no enrollments
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return null;
		}

		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return null;
		}

		$latest_timestamp = 0;

		if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) {
			$enrolled_courses = learndash_user_get_enrolled_courses( $user->ID );

			foreach ( $enrolled_courses as $course_id ) {
				$access_from = function_exists( 'learndash_get_course_access_from' )
					? learndash_get_course_access_from( $user->ID, $course_id )
					: get_user_meta( $user->ID, "course_{$course_id}_access_from", true );

				if ( $access_from ) {
					$timestamp = is_numeric( $access_from ) ? (int) $access_from : strtotime( $access_from );
					if ( $timestamp > $latest_timestamp ) {
						$latest_timestamp = $timestamp;
					}
				}
			}
		}

		if ( $latest_timestamp === 0 ) {
			$user_meta = get_user_meta( $user->ID );
			foreach ( $user_meta as $meta_key => $meta_value ) {
				if ( str_starts_with( $meta_key, 'course_' ) && str_ends_with( $meta_key, '_access_from' ) ) {
					$access_from = is_array( $meta_value ) ? $meta_value[0] : $meta_value;
					$timestamp   = is_numeric( $access_from ) ? (int) $access_from : strtotime( $access_from );
					if ( $timestamp > $latest_timestamp ) {
						$latest_timestamp = $timestamp;
					}
				}
			}
		}

		return $latest_timestamp ? gmdate( 'Y-m-d', $latest_timestamp ) : null;
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
		$last_enrollment_date = $this->get_value( $automation_contact );
		$operator             = $rule['operator'] ?? '';
		$rule_value           = $rule['value'] ?? '';

		// If no enrollment date found, rule cannot be met
		if ( empty( $last_enrollment_date ) ) {
			return false;
		}

		// Convert dates to timestamps for comparison
		$enrollment_timestamp = strtotime( $last_enrollment_date );

		switch ( $operator ) {
			case 'before':
				$rule_timestamp = strtotime( $rule_value );
				return $enrollment_timestamp < $rule_timestamp;

			case 'after':
				$rule_timestamp = strtotime( $rule_value );
				return $enrollment_timestamp > $rule_timestamp;

			case 'on':
				$rule_timestamp = strtotime( $rule_value );
				return gmdate( 'Y-m-d', $enrollment_timestamp ) === gmdate( 'Y-m-d', $rule_timestamp );

			case 'between':
				if ( ! is_array( $rule_value ) || count( $rule_value ) < 2 ) {
					return false;
				}
				$start_timestamp = strtotime( $rule_value[0] );
				$end_timestamp   = strtotime( $rule_value[1] );
				return $enrollment_timestamp >= $start_timestamp && $enrollment_timestamp <= $end_timestamp;

			case 'within':
				// Within X days from today
				if ( ! is_numeric( $rule_value ) ) {
					return false;
				}
				$days_ago         = (int) $rule_value;
				$cutoff_timestamp = strtotime( "-{$days_ago} days" );
				return $enrollment_timestamp >= $cutoff_timestamp;

			default:
				return false;
		}
	}
}



add_action(
	'init',
	function () {
		if ( class_exists( 'SFWD_LMS' ) ) {
			RulesManager::instance()->register( new LastEnrollmentDate() );
		} else {
			add_action(
				'learndash_loaded',
				function () {
					RulesManager::instance()->register( new LastEnrollmentDate() );
				}
			);
		}
	},
	99
);
