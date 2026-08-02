<?php

/**
 * Class Remove User From Course
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Learnpress;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

/**
 * Remove User From Course
 */
class RemoveUserFromCourse extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove User From Course';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'learnpress_remove_user_from_course';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove a user from a LearnPress course.';

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
	public $group = 'learnpress';

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
				__( 'User not found for LearnPress course removal', 'doublescale'),
				array(
					'code'          => 'learnpress_user_not_found',
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
				__( 'Course ID not configured for LearnPress removal action', 'doublescale'),
				array(
					'code'          => 'learnpress_course_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Check if LearnPress is active.
		if ( ! defined( 'LP_PLUGIN_FILE' ) ) {
			doublescale_get_logger()->error(
				__( 'LearnPress plugin is not active. Cannot remove user from course.', 'doublescale'),
				array(
					'code'          => 'learnpress_plugin_inactive',
					'user_id'       => $user->ID,
					'course_id'     => $course_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Remove enrollment from database.
		global $wpdb;
		$table_name = $wpdb->prefix . 'learnpress_user_items';

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			doublescale_get_logger()->error(
				__( 'LearnPress user items table not found', 'doublescale'),
				array(
					'code'          => 'learnpress_table_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$result = $wpdb->delete(
			$table_name,
			array(
				'user_id'   => $user->ID,
				'item_id'   => $course_id,
				'item_type' => 'lp_course',
			),
			array( '%d', '%d', '%s' )
		);

		if ( $result !== false ) {
			doublescale_get_logger()->info(
				__( 'User successfully removed from LearnPress course', 'doublescale'),
				array(
					'code'          => 'learnpress_course_unenrolled',
					'user_id'       => $user->ID,
					'course_id'     => $course_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return true;
		}

		doublescale_get_logger()->info(
			__( 'Failed to remove user from LearnPress course', 'doublescale'),
			array(
				'code'          => 'learnpress_unenrollment_failed',
				'user_id'       => $user->ID,
				'course_id'     => $course_id,
				'automation_id' => $automation->id,
				'step_id'       => $step->id,
			)
		);

		return false;
	}

	/**
	 * Get Courses
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_courses() {
		if ( ! defined( 'LP_PLUGIN_FILE' ) ) {
			return array();
		}

		$courses = get_posts(
			array(
				'post_type'   => 'lp_course',
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
