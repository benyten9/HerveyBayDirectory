<?php

/**
 * Class Add User To MemberPress Membership
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
	public $slug = 'memberpress_add_user_to_membership';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a user to a MemberPress membership by creating a transaction.';

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
				__( 'User not found for MemberPress membership enrollment', 'doublescale'),
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
				__( 'Membership ID not configured for MemberPress enrollment action', 'doublescale'),
				array(
					'code'          => 'memberpress_membership_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		if ( ! defined( 'MEPR_PLUGIN_NAME' ) || ! class_exists( 'MeprTransaction' ) ) {
			doublescale_get_logger()->error(
				__( 'MemberPress plugin is not active. Cannot add user to membership.', 'doublescale'),
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

		$membership = new \MeprProduct( $membership_id );

		if ( ! $membership->ID ) {
			doublescale_get_logger()->info(
				__( 'MemberPress membership not found.', 'doublescale'),
				array(
					'code'          => 'memberpress_membership_not_found',
					'membership_id' => $membership_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$amount_type = $step->get_setting( 'amount_type', 'free' );

		if ( 'membership_price' === $amount_type ) {
			$price = (float) $membership->price;
		} elseif ( 'custom' === $amount_type ) {
			$price = (float) $step->get_setting( 'custom_amount', 0 );
		} else {
			$price = 0.00;
		}

		$txn             = new \MeprTransaction();
		$txn->user_id    = $user->ID;
		$txn->product_id = $membership_id;
		$txn->txn_type   = 'payment';
		$txn->status     = 'complete';
		$txn->gateway    = 'manual';
		$txn->created_at = gmdate( 'Y-m-d H:i:s' );
		$txn->amount     = $price;
		$txn->total      = $price;

		$expires_at_ts = $membership->get_expires_at( time() );
		if ( ! is_null( $expires_at_ts ) ) {
			$txn->expires_at = gmdate( 'Y-m-d 23:59:59', $expires_at_ts );
		}

		$result = $txn->store();

		if ( ! $result ) {
			doublescale_get_logger()->info(
				__( 'Could not create MemberPress transaction for user.', 'doublescale'),
				array(
					'code'          => 'memberpress_transaction_creation_failed',
					'user_id'       => $user->ID,
					'membership_id' => $membership_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'User successfully added to MemberPress membership', 'doublescale'),
			array(
				'code'           => 'memberpress_membership_enrolled',
				'user_id'        => $user->ID,
				'membership_id'  => $membership_id,
				'transaction_id' => $txn->id,
				'amount'         => $price,
				'automation_id'  => $automation->id,
				'step_id'        => $step->id,
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
				'tooltip' => __( 'Select the MemberPress membership to enroll the user in. The expiration will be calculated automatically based on the membership settings.', 'doublescale'),
				'options' => $this->get_memberships(),
			),
			'amount_type'   => array(
				'type'    => 'select',
				'label'   => __( 'Transaction Amount', 'doublescale'),
				'tooltip' => __( 'Choose how the transaction amount is recorded. "Free" creates a $0 transaction, "Use membership price" records the actual price, and "Custom" lets you set a specific amount.', 'doublescale'),
				'options' => array(
					'free'             => __( 'Free ($0.00)', 'doublescale'),
					'membership_price' => __( 'Use membership price', 'doublescale'),
					'custom'           => __( 'Custom amount', 'doublescale'),
				),
			),
			'custom_amount' => array(
				'type'      => 'number',
				'label'     => __( 'Custom Amount', 'doublescale'),
				'tooltip'   => __( 'Enter the amount to record for this transaction. This does not charge the user — it only sets the transaction amount for reporting purposes.', 'doublescale'),
				'condition' => array(
					'field' => 'amount_type',
					'value' => 'custom',
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
				'membership_id' => array(
					'type' => 'string',
				),
				'amount_type'   => array(
					'type' => 'string',
				),
				'custom_amount' => array(
					'type' => 'string',
				),
			),
		);
	}
}
