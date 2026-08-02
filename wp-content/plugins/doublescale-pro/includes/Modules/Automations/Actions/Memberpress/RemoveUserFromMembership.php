<?php

/**
 * Class Remove User From MemberPress Membership
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Memberpress;

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
	public $slug = 'memberpress_remove_user_from_membership';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove a user from a MemberPress membership by expiring their active transactions.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'memberpress';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'memberpress';

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
				__( 'User not found for MemberPress membership removal', 'doublescale'),
				array(
					'code'          => 'memberpress_user_not_found',
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
				__( 'Membership ID not configured for MemberPress removal action', 'doublescale'),
				array(
					'code'          => 'memberpress_membership_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		if ( ! defined( 'MEPR_PLUGIN_NAME' ) || ! class_exists( 'MeprUser' ) ) {
			doublescale_get_logger()->error(
				__( 'MemberPress plugin is not active. Cannot remove user from membership.', 'doublescale'),
				array(
					'code'          => 'memberpress_plugin_inactive',
					'user_id'       => $user->ID,
					'membership_id' => $membership_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$mepr_user     = new \MeprUser( $user->ID );
		$active_subs   = $mepr_user->active_product_subscriptions( 'ids' );
		$was_active    = in_array( (int) $membership_id, array_map( 'intval', $active_subs ), true );

		if ( ! $was_active ) {
			doublescale_get_logger()->info(
				__( 'User does not have an active membership for the specified product.', 'doublescale'),
				array(
					'code'          => 'memberpress_not_active_member',
					'user_id'       => $user->ID,
					'membership_id' => $membership_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		global $wpdb;
		$mepr_transactions_table = $wpdb->prefix . 'mepr_transactions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$mepr_transactions_table,
			array(
				'status'     => 'complete',
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
			),
			array(
				'user_id'    => $user->ID,
				'product_id' => $membership_id,
				'status'     => 'complete',
			),
			array( '%s', '%s' ),
			array( '%d', '%d', '%s' )
		);

		// Also cancel any active recurring subscriptions for this membership
		if ( class_exists( 'MeprSubscription' ) ) {
			$mepr_subscriptions_table = $wpdb->prefix . 'mepr_subscriptions';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$active_subscription_ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$mepr_subscriptions_table} WHERE user_id = %d AND product_id = %d AND status = 'active'",
					$user->ID,
					$membership_id
				)
			);

			foreach ( $active_subscription_ids as $sub_id ) {
				$sub = new \MeprSubscription( $sub_id );
				if ( $sub->id ) {
					$sub->status = 'cancelled';
					$sub->store();
				}
			}
		}

		doublescale_get_logger()->info(
			__( 'User successfully removed from MemberPress membership', 'doublescale'),
			array(
				'code'          => 'memberpress_membership_removed',
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
		if ( ! defined( 'MEPR_PLUGIN_NAME' ) ) {
			return array();
		}

		$memberships = get_posts(
			array(
				'post_type'   => 'memberpressproduct',
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
				'tooltip' => __( 'Select the MemberPress membership to remove the user from. This will expire all active transactions and cancel any recurring subscriptions for this membership.', 'doublescale'),
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
