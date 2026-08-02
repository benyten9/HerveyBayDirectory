<?php

/**
 * Class Membership Has Active Plans
 *
 * This class is responsible for handling the membership has active plans rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\WoocommerceMembership;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Membership Has Active Plans class
 */
class MembershipHasActivePlans extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Membership Has Active Plans';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'membership_has_active_plans';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'woocommerce_membership';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'multiselect';


	/**
	 * Has options
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_options() {
		return true;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => \__( 'Is', 'doublescale' ),
			'is_not' => \__( 'Is not', 'doublescale' ),
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

		if ( ! \function_exists( 'wc_memberships_get_membership_plans' ) ) {
			return array();
		}

		$options        = \wc_memberships_get_membership_plans();
		$formatted_opts = array();

		foreach ( $options as $option ) {
			$formatted_opts[ $option->get_id() ] = $option->get_name();
		}

		return $formatted_opts;
	}


	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return array
	 */
	public function get_value( $automation_contact ) {
		$user_id = $automation_contact->get_data( 'user_id' );

		if ( ! $user_id || ! \function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
			return array();
		}

		$active_memberships = \wc_memberships_get_user_active_memberships( $user_id );
		$active_plan_ids    = array();

		if ( ! empty( $active_memberships ) ) {
			foreach ( $active_memberships as $membership ) {
				$active_plan_ids[] = $membership->get_plan_id();
			}
		}

		return $active_plan_ids;
	}

	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                  $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$active_plan_ids = $this->get_value( $automation_contact );
		$operator        = $rule['operator'];
		$rule_value      = $rule['value'];

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		$rule_value = array_map( 'intval', $rule_value );

		switch ( $operator ) {
			case 'is':
				return ! empty( array_intersect( $active_plan_ids, $rule_value ) );
			case 'is_not':
				return empty( array_intersect( $active_plan_ids, $rule_value ) );
			default:
				return false;
		}
	}
}


\add_action(
	'init',
	function () {
		if ( \class_exists( 'WC_Memberships' ) ) {
			RulesManager::instance()->register( new MembershipHasActivePlans() );
		} else {
			\add_action(
				'woocommerce_memberships_loaded',
				function () {
					RulesManager::instance()->register( new MembershipHasActivePlans() );
				}
			);
		}
	},
	99
);
