<?php

/**
 * Class Orders Total Count
 *
 * This class is responsible for handling the orders total count rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Orders Total Count class
 */
class OrdersTotalCount extends Rule {







	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Total Orders Count';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'orders_total_count';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'woocommerce';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'number';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'less_than'    => __( 'Less than', 'doublescale' ),
			'equal'        => __( 'equal', 'doublescale' ),
			'not_equal'    => __( 'does not equal', 'doublescale' ),
		);
	}


	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return int
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;
		if ( ! $contact || empty( $contact->email ) ) {
			return 0;
		}

		// Ensure WooCommerce is active
		if ( ! class_exists( '\WC_Customer' ) ) {
			return 0;
		}

		// Get user by email
		$user = get_user_by( 'email', $contact->email );
		if ( ! $user ) {
			return 0;
		}

		// Create WooCommerce customer object
		try {
			$customer = new \WC_Customer( $user->ID );
			return (int) $customer->get_order_count();
		} catch ( \Exception $e ) {
			return 0;
		}
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
		$value      = (float) $this->get_value( $automation_contact );
		$operator   = $rule['operator'];
		$rule_value = (float) $rule['value'];

		switch ( $operator ) {
			case 'equal':
				return $value === $rule_value;
			case 'not_equal':
				return $value !== $rule_value;
			case 'greater_than':
				return $value > $rule_value;
			case 'less_than':
				return $value < $rule_value;
			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			RulesManager::instance()->register( new OrdersTotalCount() );
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register( new OrdersTotalCount() );
				}
			);
		}
	},
	99
);
