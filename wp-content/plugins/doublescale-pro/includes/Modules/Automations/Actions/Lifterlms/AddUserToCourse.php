<?php

/**
 * Class Add User To Course
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Lifterlms;

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
	public $slug = 'lifterlms_add_user_to_course';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a user to a LifterLMS course.';

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
	public $group = 'lifterlms';

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
				__( 'User not found for LifterLMS course enrollment', 'doublescale'),
				array(
					'code'          => 'lifterlms_user_not_found',
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
				__( 'Course ID not configured for LifterLMS enrollment action', 'doublescale'),
				array(
					'code'          => 'lifterlms_course_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Check if LifterLMS function exists.
		if ( ! function_exists( 'llms_enroll_student' ) ) {
			doublescale_get_logger()->error(
				__( 'LifterLMS plugin is not active. Cannot add user to course.', 'doublescale'),
				array(
					'code'          => 'lifterlms_plugin_inactive',
					'user_id'       => $user->ID,
					'course_id'     => $course_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Execute the enrollment.
		$result = llms_enroll_student( $user->ID, $course_id, 'doublescale_automation_' . $automation->id );

		if ( ! $result ) {
			doublescale_get_logger()->info(
				__( 'User could not be enrolled to the LifterLMS course. Maybe course is already enrolled or LifterLMS failed to enroll.', 'doublescale'),
				array(
					'code'          => 'lifterlms_enrollment_failed',
					'user_id'       => $user->ID,
					'course_id'     => $course_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'User successfully added to LifterLMS course', 'doublescale'),
			array(
				'code'          => 'lifterlms_course_enrolled',
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
		if ( ! defined( 'LLMS_PLUGIN_FILE' ) ) {
			return array();
		}

		$courses = get_posts(
			array(
				'post_type'   => 'course',
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
