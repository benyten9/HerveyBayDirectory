<?php

/**
 * Class Remove User From PMPro Membership Level
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
 * Remove User From Membership Level
 */
class RemoveUserFromMembershipLevel extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove User From Membership Level';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_remove_user_from_membership_level';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will cancel a user\'s membership by setting their level to 0 in Paid Memberships Pro.';

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
				__( 'User not found for PMPro membership removal', 'doublescale'),
				array(
					'code'          => 'pmpro_user_not_found',
					'contact_email' => $contact->email,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		if ( ! defined( 'PMPRO_VERSION' ) ) {
			doublescale_get_logger()->error(
				__( 'Paid Memberships Pro plugin is not active.', 'doublescale'),
				array(
					'code'          => 'pmpro_plugin_inactive',
					'user_id'       => $user->ID,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$cancel_mode = $step->get_setting( 'cancel_mode', 'all' );

		if ( 'specific' === $cancel_mode ) {
			$level_id = $step->get_setting( 'membership_level_id' );
			if ( ! $level_id ) {
				doublescale_get_logger()->info(
					__( 'Membership level ID not configured for PMPro removal action', 'doublescale'),
					array(
						'code'          => 'pmpro_level_id_missing',
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
					)
				);
				return false;
			}

			if ( ! function_exists( 'pmpro_hasMembershipLevel' ) || ! pmpro_hasMembershipLevel( $level_id, $user->ID ) ) {
				doublescale_get_logger()->info(
					__( 'User does not have the specified PMPro membership level.', 'doublescale'),
					array(
						'code'          => 'pmpro_not_active_member',
						'user_id'       => $user->ID,
						'level_id'      => $level_id,
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
					)
				);
				return false;
			}

			$result = function_exists( 'pmpro_cancelMembershipLevel' )
				? pmpro_cancelMembershipLevel( $level_id, $user->ID )
				: pmpro_changeMembershipLevel( 0, $user->ID );
		} else {
			if ( function_exists( 'pmpro_cancelMembershipLevel' ) && function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
				$levels = pmpro_getMembershipLevelsForUser( $user->ID );
				$result = true;
				foreach ( $levels as $level ) {
					if ( ! pmpro_cancelMembershipLevel( $level->id, $user->ID ) ) {
						$result = false;
					}
				}
				if ( empty( $levels ) ) {
					$result = pmpro_changeMembershipLevel( 0, $user->ID );
				}
			} else {
				$result = pmpro_changeMembershipLevel( 0, $user->ID );
			}
		}

		if ( ! $result ) {
			doublescale_get_logger()->info(
				__( 'Could not cancel PMPro membership for user.', 'doublescale'),
				array(
					'code'          => 'pmpro_cancellation_failed',
					'user_id'       => $user->ID,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'User successfully removed from PMPro membership', 'doublescale'),
			array(
				'code'          => 'pmpro_membership_cancelled',
				'user_id'       => $user->ID,
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
			'cancel_mode'         => array(
				'type'    => 'select',
				'label'   => __( 'Cancel Mode', 'doublescale'),
				'tooltip' => __( 'Choose whether to cancel all membership levels or a specific one.', 'doublescale'),
				'options' => array(
					'all'      => __( 'Cancel all membership levels', 'doublescale'),
					'specific' => __( 'Cancel specific membership level', 'doublescale'),
				),
			),
			'membership_level_id' => array(
				'type'      => 'select',
				'label'     => __( 'Membership Level', 'doublescale'),
				'tooltip'   => __( 'Select the specific PMPro membership level to cancel.', 'doublescale'),
				'options'   => $this->get_membership_levels(),
				'condition' => array(
					'field' => 'cancel_mode',
					'value' => 'specific',
				),
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
				'cancel_mode'         => array(
					'type' => 'string',
				),
				'membership_level_id' => array(
					'type' => 'string',
				),
			),
		);
	}
}
