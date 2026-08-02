<?php

/**
 * Class Add User To Course
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Tutorlms;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

/**
 * Add User To Course
 */
class AddUserToCourse extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add User To Course';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'tutorlms_add_user_to_course';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a user to a TutorLMS course.';

	/**
	 * Action Attributes
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
	public $group = 'tutorlms';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 *
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$contact = $automation_contact->contact;
		$user    = get_user_by( 'email', $contact->email );

		if ( ! $user ) {
			doublescale_get_logger()->info(
				__( 'User not found for TutorLMS course enrollment', 'doublescale'),
				array(
					'code'          => 'tutorlms_user_not_found',
					'contact_email' => $contact->email,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$course_id = $step->get_setting( 'course_id' );

		if ( ! $course_id ) {
			doublescale_get_logger()->info(
				__( 'Course ID not configured for TutorLMS enrollment action', 'doublescale'),
				array(
					'code'          => 'tutorlms_course_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Check if TutorLMS function exists.
		if ( ! function_exists( 'tutor_utils' ) ) {
			doublescale_get_logger()->error(
				__( 'TutorLMS plugin is not active. Cannot add user to course.', 'doublescale'),
				array(
					'code'          => 'tutorlms_plugin_inactive',
					'user_id'       => $user->ID,
					'course_id'     => $course_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Execute the enrollment.
		$result = tutor_utils()->do_enroll( $course_id, 0, $user->ID );

		if ( ! $result ) {
			doublescale_get_logger()->info(
				__( 'User could not be enrolled to the TutorLMS course. Maybe course is already enrolled or Tutor failed to enroll.', 'doublescale'),
				array(
					'code'          => 'tutorlms_enrollment_failed',
					'user_id'       => $user->ID,
					'course_id'     => $course_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'User successfully added to TutorLMS course', 'doublescale'),
			array(
				'code'          => 'tutorlms_course_enrolled',
				'user_id'       => $user->ID,
				'course_id'     => $course_id,
				'automation_id' => $automation->id,
				'step_id'       => $step->id,
			)
		);

		return true;
	}

	/**
	 * Get Courses
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_courses() {
		if ( ! function_exists( 'tutor' ) ) {
			return array();
		}

		$courses = get_posts(
			array(
				'post_type'   => tutor()->course_post_type,
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		$options = array();
		foreach ( $courses as $course ) {
			$options[ $course->ID ] = $course->post_title;
		}

		return $options;
	}

	/**
	 * Get fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'course_id' => array(
				'type'    => 'select',
				'label'   => __( 'Course', 'doublescale'),
				'options' => $this->get_courses(),
			),
		);
	}

	/**
	 * Get Attributes schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'course_id' => array(
					'type' => 'string',
				),
			),
		);
	}
}
