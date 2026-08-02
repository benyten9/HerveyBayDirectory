<?php

/**
 * Class Remove User From Course
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Learndash;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

/**
 * Remove User From Course
 */
class RemoveUserFromCourse extends Action
{
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
	public $slug = 'learndash_remove_user_from_course';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove a user from a course.';

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
	public $group = 'learndash';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		$contact = $automation_contact->contact;
		$user    = get_user_by('email', $contact->email);
		if (! $user) {
			doublescale_get_logger()->info(
				__('User not found for LearnDash course removal', 'doublescale'),
				array(
					'code'          => 'learndash_user_not_found',
					'contact_email' => $contact->email,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$course_id = $step->get_setting('course_id');
		if (! $course_id) {
			doublescale_get_logger()->info(
				__('Course ID not configured for LearnDash removal action', 'doublescale'),
				array(
					'code'          => 'learndash_course_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Check if LearnDash function exists
		if (! function_exists('ld_update_course_access')) {
			doublescale_get_logger()->error(
				__('LearnDash plugin is not active. Cannot remove user from course.', 'doublescale'),
				array(
					'code'          => 'learndash_plugin_inactive',
					'user_id'       => $user->ID,
					'course_id'     => $course_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Execute the action
		ld_update_course_access($user->ID, $course_id, true);

		doublescale_get_logger()->info(
			__('User successfully removed from LearnDash course', 'doublescale'),
			array(
				'code'          => 'learndash_course_removed',
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
	 */
	public function get_courses()
	{
		if (! function_exists('learndash_get_courses')) {
			return array();
		}
		$courses = learndash_get_courses();

		$options = array();
		foreach ($courses as $course) {
			$options[$course->ID] = $course->post_title;
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
	public function get_fields()
	{
		return array(
			'course_id' => array(
				'type'    => 'select',
				'label'   => __('Course', 'doublescale'),
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
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'course_id' => array(
					'type'        => 'string',
					'label'       => 'Course ID',
					'description' => 'Course ID to remove user from.',
					'required'    => true,
				),
			),
		);
	}
}
