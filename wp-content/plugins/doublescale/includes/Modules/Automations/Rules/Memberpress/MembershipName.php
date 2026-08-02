<?php

/**
 * Class MembershipName
 *
 * Rule to check the MemberPress membership name from trigger data.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Memberpress;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Membership Name class
 */
class MembershipName extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Membership Name';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_membership_name';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'memberpress';

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'select';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array(
		'memberpress_membership_enrolled',
		'memberpress_membership_level_expiry',
		'memberpress_subscription_created',
		'memberpress_subscription_paused',
		'memberpress_subscription_resumed',
		'memberpress_subscription_cancelled',
		'memberpress_transaction_completed',
		'memberpress_transaction_refunded',
		'memberpress_transaction_failed',
	);

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		$options = array();

		if ( ! defined( 'MEPR_PLUGIN_NAME' ) ) {
			return $options;
		}

		$memberships = get_posts(
			array(
				'post_type'   => 'memberpressproduct',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		if ( empty( $memberships ) || ! is_array( $memberships ) ) {
			return $options;
		}

		foreach ( $memberships as $membership ) {
			if ( is_object( $membership ) && isset( $membership->ID ) ) {
				$options[ (int) $membership->ID ] = wp_kses_post( get_the_title( $membership->ID ) );
			}
		}

		return $options;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return string
	 */
	public function get_value( $automation_contact ) {
		$membership_id = $automation_contact->get_data( 'membership_id' );
		return $membership_id ? (string) $membership_id : '';
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'MEPR_PLUGIN_NAME' ) ) {
			RulesManager::instance()->register( new MembershipName() );
		}
	},
	99
);
