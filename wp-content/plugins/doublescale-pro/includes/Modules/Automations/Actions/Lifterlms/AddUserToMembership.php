<?php

/**
 * Class Add User To Membership
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
 * Add User To Membership
 */
class AddUserToMembership extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add User To Membership';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'lifterlms_add_user_to_membership';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a user to a LifterLMS membership.';

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
				__( 'User not found for LifterLMS membership enrollment', 'doublescale'),
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
				__( 'Membership ID not configured for LifterLMS enrollment action', 'doublescale'),
				array(
					'code'          => 'lifterlms_membership_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Check if LifterLMS function exists.
		if ( ! function_exists( 'llms_enroll_student' ) ) {
			doublescale_get_logger()->error(
				__( 'LifterLMS plugin is not active. Cannot add user to membership.', 'doublescale'),
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

		// Execute the enrollment.
		$result = llms_enroll_student( $user->ID, $membership_id, 'doublescale_automation_' . $automation->id );

		if ( ! $result ) {
			doublescale_get_logger()->info(
				__( 'User could not be enrolled to the LifterLMS membership. Maybe membership is already enrolled or LifterLMS failed to enroll.', 'doublescale'),
				array(
					'code'          => 'lifterlms_membership_enrollment_failed',
					'user_id'       => $user->ID,
					'membership_id' => $membership_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'User successfully added to LifterLMS membership', 'doublescale'),
			array(
				'code'          => 'lifterlms_membership_enrolled',
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
