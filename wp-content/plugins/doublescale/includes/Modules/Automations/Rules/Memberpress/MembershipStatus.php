<?php

/**
 * Class MembershipStatus
 *
 * Rule to check the current MemberPress trigger's membership status.
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
 * Membership Status class
 */
class MembershipStatus extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Transaction Status';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_membership_status';

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
		return array(
			'pending'  => __( 'Pending', 'doublescale' ),
			'complete' => __( 'Complete', 'doublescale' ),
			'failed'   => __( 'Failed', 'doublescale' ),
			'refunded' => __( 'Refunded', 'doublescale' ),
		);
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
		return $automation_contact->get_data( 'status' ) ?? '';
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'MEPR_PLUGIN_NAME' ) ) {
			RulesManager::instance()->register( new MembershipStatus() );
		}
	},
	99
);
