<?php

/**
 * Class Remove User From Membership
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
 * Remove User From Membership
 */
class RemoveUserFromMembership extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove User From Membership';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'lifterlms_remove_user_from_membership';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove a user from a LifterLMS membership.';

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
				__( 'User not found for LifterLMS membership removal', 'doublescale'),
				array(
					'code'          => 'lifterlms_user_not_found',
					'contact_email' => $contact->email,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$membership_id = $step->get_setting( 'membership_id' );

		if ( ! $membership_id ) {
			doublescale_get_logger()->info(
				__( 'Membership ID not configured for LifterLMS removal action', 'doublescale'),
				array(
					'code'          => 'lifterlms_membership_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Check if LifterLMS function exists.
		if ( ! function_exists( 'llms_get_student' ) ) {
			doublescale_get_logger()->error(
				__( 'LifterLMS plugin is not active. Cannot remove user from membership.', 'doublescale'),
				array(
					'code'          => 'lifterlms_plugin_inactive',
					'user_id'       => $user->ID,
					'membership_id' => $membership_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Get the student object.
		$student = llms_get_student( $user->ID );

		if ( ! $student ) {
			doublescale_get_logger()->info(
				__( 'Could not get LifterLMS student object', 'doublescale'),
				array(
					'code'          => 'lifterlms_student_not_found',
					'user_id'       => $user->ID,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Execute the unenrollment.
		$result = $student->unenroll( $membership_id, 'doublescale_automation_' . $automation->id, 'cancelled' );

		if ( ! $result ) {
			doublescale_get_logger()->info(
				__( 'User could not be removed from the LifterLMS membership. Maybe user is not enrolled.', 'doublescale'),
				array(
					'code'          => 'lifterlms_membership_unenrollment_failed',
					'user_id'       => $user->ID,
					'membership_id' => $membership_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'User successfully removed from LifterLMS membership', 'doublescale'),
			array(
				'code'          => 'lifterlms_membership_unenrolled',
				'user_id'       => $user->ID,
				'membership_id' => $membership_id,
				'automation_id' => $automation->id,
				'step_id'       => $step->id,
			)
		);

		return true;
	}

	/**
	 * Get Memberships
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_memberships() {
		if ( ! defined( 'LLMS_PLUGIN_FILE' ) ) {
			return array();
		}

		$memberships = get_posts(
			array(
				'post_type'   => 'llms_membership',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		$options = array();
		foreach ( $memberships as $membership ) {
			$options[ $membership->ID ] = $membership->post_title;
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
			'membership_id' => array(
				'type'    => 'select',
				'label'   => __( 'Membership', 'doublescale'),
				'options' => $this->get_memberships(),
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
				'membership_id' => array(
					'type' => 'string',
				),
			),
		);
	}
}
