<?php

/**
 * Class LastEnrollmentDate
 *
 * This class is responsible for handling the last enrollment date rule for LifterLMS
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
	public $slug = 'lifterlms_last_enrollment_date';

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

		$last_date = null;

		if ( function_exists( 'llms_get_student' ) ) {
			$student = llms_get_student( $user->ID );
			if ( $student ) {
				$courses = $student->get_courses(
					array(
						'limit'  => 10000,
						'status' => 'enrolled',
					)
				);
				if ( ! empty( $courses['results'] ) ) {
					foreach ( $courses['results'] as $course_id ) {
						$enrollment_date = $student->get_enrollment_date( $course_id, 'enrolled' );
						if ( $enrollment_date ) {
							$timestamp = strtotime( $enrollment_date );
							if ( $timestamp && ( $last_date === null || $timestamp > strtotime( $last_date ) ) ) {
								$last_date = gmdate( 'Y-m-d', $timestamp );
							}
						}
					}
				}
			}
		}

		return $last_date;
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

		if ( empty( $last_enrollment_date ) ) {
			return false;
		}

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
		if ( \doublescale_is_plugin_active( 'lifterlms/lifterlms.php' ) ) {
			RulesManager::instance()->register( new LastEnrollmentDate() );
		}
	},
	99
);
