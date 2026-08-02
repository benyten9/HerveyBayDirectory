<?php

/**
 * Class ActiveMemberships
 *
 * Rule to check which MemberPress memberships a user has active.
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
 * Active Memberships class
 */
class ActiveMemberships extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Active Memberships';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_active_memberships';

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
	public $type = 'multiselect';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'includes'         => __( 'includes', 'doublescale' ),
			'not_includes_in'  => __( 'Does not include (in any)', 'doublescale' ),
			'includes_all'     => __( 'includes all', 'doublescale' ),
			'not_includes_all' => __( 'includes none of (match all)', 'doublescale' ),
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
	 * @return array Array of active membership IDs
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return array();
		}

		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return array();
		}

		if ( ! defined( 'MEPR_PLUGIN_NAME' ) || ! class_exists( 'MeprUser' ) ) {
			return array();
		}

		$mepr_user = new \MeprUser( $user->ID );
		$active    = $mepr_user->active_product_subscriptions( 'ids' );

		return array_map( 'intval', array_unique( array_filter( $active ) ) );
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
		$active_memberships = $this->get_value( $automation_contact );
		$operator           = $rule['operator'] ?? '';
		$rule_memberships   = $rule['value'] ?? array();

		if ( ! is_array( $rule_memberships ) ) {
			$rule_memberships = array();
		}

		$rule_memberships = array_map( 'intval', $rule_memberships );

		switch ( $operator ) {
			case 'includes':
				return ! empty( array_intersect( $active_memberships, $rule_memberships ) );

			case 'not_includes_in':
				return empty( array_intersect( $active_memberships, $rule_memberships ) );

			case 'includes_all':
				return empty( array_diff( $rule_memberships, $active_memberships ) );

			case 'not_includes_all':
				return ! empty( array_diff( $rule_memberships, $active_memberships ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'MEPR_PLUGIN_NAME' ) ) {
			RulesManager::instance()->register( new ActiveMemberships() );
		}
	},
	99
);
