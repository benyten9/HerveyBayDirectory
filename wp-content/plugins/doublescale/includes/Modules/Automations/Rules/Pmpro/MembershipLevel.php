<?php

/**
 * Class MembershipLevel
 *
 * Rule to check the PMPro membership level from trigger data.
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
 * Membership Level class
 */
class MembershipLevel extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Membership Level';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_membership_level';

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
	public $type = 'select';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array(
		'pmpro_checkout_completed',
		'pmpro_membership_level_changed',
		'pmpro_membership_cancelled',
		'pmpro_membership_expired',
		'pmpro_membership_expiring_soon',
		'pmpro_order_added',
		'pmpro_order_updated',
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
		if ( defined( 'PMPRO_VERSION' ) ) {
			RulesManager::instance()->register( new MembershipLevel() );
		}
	},
	99
);
