<?php

/**
 * Class TransactionAmount
 *
 * Rule to check the MemberPress transaction amount from trigger data.
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
 * Transaction Amount class
 */
class TransactionAmount extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Transaction Amount';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_transaction_amount';

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
	public $type = 'number';

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
		'memberpress_subscription_created',
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
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'lower_than'   => __( 'Lower than', 'doublescale' ),
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
		$amount = $automation_contact->get_data( 'amount' );
		return $amount ? (string) $amount : '0';
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'MEPR_PLUGIN_NAME' ) ) {
			RulesManager::instance()->register( new TransactionAmount() );
		}
	},
	99
);
