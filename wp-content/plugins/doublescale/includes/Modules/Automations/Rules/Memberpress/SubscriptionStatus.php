<?php

/**
 * Class SubscriptionStatus
 *
 * Rule to check the MemberPress subscription status from trigger data.
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
 * Subscription Status class
 */
class SubscriptionStatus extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Status';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_subscription_status';

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
		'memberpress_subscription_created',
		'memberpress_subscription_paused',
		'memberpress_subscription_resumed',
		'memberpress_subscription_cancelled',
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
			'active'    => __( 'Active', 'doublescale' ),
			'suspended' => __( 'Suspended', 'doublescale' ),
			'cancelled' => __( 'Cancelled', 'doublescale' ),
			'pending'   => __( 'Pending', 'doublescale' ),
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
			RulesManager::instance()->register( new SubscriptionStatus() );
		}
	},
	99
);
