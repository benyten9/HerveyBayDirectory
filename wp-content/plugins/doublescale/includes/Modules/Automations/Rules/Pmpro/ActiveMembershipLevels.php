<?php

/**
 * Class ActiveMembershipLevels
 *
 * Rule to check which PMPro membership levels a user currently has active.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Pmpro;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Active Membership Levels class
 */
class ActiveMembershipLevels extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Active Membership Levels';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_active_membership_levels';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'pmpro';

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
		if ( ! defined( 'PMPRO_VERSION' ) || ! function_exists( 'pmpro_getAllLevels' ) ) {
			return array();
		}

		$levels  = pmpro_getAllLevels( true, true );
		$options = array();

		foreach ( $levels as $level ) {
			$options[ (int) $level->id ] = esc_html( $level->name );
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
	 * @return array Array of active membership level IDs
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

		if ( ! defined( 'PMPRO_VERSION' ) || ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
			return array();
		}

		$levels = pmpro_getMembershipLevelsForUser( $user->ID );

		if ( empty( $levels ) || ! is_array( $levels ) ) {
			return array();
		}

		return array_map( 'intval', array_unique( array_filter( wp_list_pluck( $levels, 'id' ) ) ) );
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
		$active_levels = $this->get_value( $automation_contact );
		$operator      = $rule['operator'] ?? '';
		$rule_levels   = $rule['value'] ?? array();

		if ( ! is_array( $rule_levels ) ) {
			$rule_levels = array();
		}

		$rule_levels = array_map( 'intval', $rule_levels );

		switch ( $operator ) {
			case 'includes':
				return ! empty( array_intersect( $active_levels, $rule_levels ) );

			case 'not_includes_in':
				return empty( array_intersect( $active_levels, $rule_levels ) );

			case 'includes_all':
				return empty( array_diff( $rule_levels, $active_levels ) );

			case 'not_includes_all':
				return ! empty( array_diff( $rule_levels, $active_levels ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'PMPRO_VERSION' ) ) {
			RulesManager::instance()->register( new ActiveMembershipLevels() );
		}
	},
	99
);
