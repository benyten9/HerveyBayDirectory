<?php

/**
 * Class Add User To PMPro Membership Level
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Pmpro;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

/**
 * Add User To Membership Level
 */
class AddUserToMembershipLevel extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add User To Membership Level';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_add_user_to_membership_level';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a user to a Paid Memberships Pro membership level.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'pmpro';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'pmpro';

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
				__( 'User not found for PMPro membership assignment', 'doublescale'),
				array(
					'code'          => 'pmpro_user_not_found',
					'contact_email' => $contact->email,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$level_id = $step->get_setting( 'membership_level_id' );

		if ( ! $level_id ) {
			doublescale_get_logger()->info(
				__( 'Membership level ID not configured for PMPro action', 'doublescale'),
				array(
					'code'          => 'pmpro_level_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		if ( ! defined( 'PMPRO_VERSION' ) || ! function_exists( 'pmpro_changeMembershipLevel' ) ) {
			doublescale_get_logger()->error(
				__( 'Paid Memberships Pro plugin is not active.', 'doublescale'),
				array(
					'code'          => 'pmpro_plugin_inactive',
					'user_id'       => $user->ID,
					'level_id'      => $level_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$result = pmpro_changeMembershipLevel( $level_id, $user->ID );

		if ( ! $result ) {
			doublescale_get_logger()->info(
				__( 'Could not assign PMPro membership level to user.', 'doublescale'),
				array(
					'code'          => 'pmpro_level_assignment_failed',
					'user_id'       => $user->ID,
					'level_id'      => $level_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'User successfully assigned to PMPro membership level', 'doublescale'),
			array(
				'code'          => 'pmpro_level_assigned',
				'user_id'       => $user->ID,
				'level_id'      => $level_id,
				'automation_id' => $automation->id,
				'step_id'       => $step->id,
			)
		);

		return true;
	}

	/**
	 * Get Membership Levels
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_membership_levels() {
		if ( ! defined( 'PMPRO_VERSION' ) || ! function_exists( 'pmpro_getAllLevels' ) ) {
			return array();
		}

		$levels  = pmpro_getAllLevels( true, true );
		$options = array();

		foreach ( $levels as $level ) {
			$options[ $level->id ] = $level->name;
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
			'membership_level_id' => array(
				'type'    => 'select',
				'label'   => __( 'Membership Level', 'doublescale'),
				'tooltip' => __( 'Select the PMPro membership level to assign to the user.', 'doublescale'),
				'options' => $this->get_membership_levels(),
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
				'membership_level_id' => array(
					'type' => 'string',
				),
			),
		);
	}
}
