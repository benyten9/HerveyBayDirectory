<?php

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Last Order class
 */
class LastOrder extends Rule {




	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Last Order';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'last_order';

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
	public $type = 'date';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'before'  => __( 'Before', 'doublescale' ),
			'after'   => __( 'After', 'doublescale' ),
			'on'      => __( 'On', 'doublescale' ),
			'between' => __( 'Between', 'doublescale' ),
		);
	}


	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return string|null
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return null;
		}

		// Check if WooCommerce functions are available
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$query_args = array(
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
		);

		// Get user by email
		$user = get_user_by( 'email', $contact->email );

		if ( $user ) {
			$query_args['customer_id'] = $user->ID;
		} else {
			$query_args['billing_email'] = $contact->email;
		}

		$orders = wc_get_orders( $query_args );

		if ( empty( $orders ) ) {
			return null;
		}

		$last_order = $orders[0];
		if ( ! $last_order instanceof \WC_Order ) {
			return null;
		}

		// Return the order date in Y-m-d format
		return $last_order->get_date_created()->date( 'Y-m-d' );
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
		$last_order_date = $this->get_value( $automation_contact );
		$operator        = $rule['operator'];
		$rule_value      = $rule['value'] ?? '';

		// If no last order found, rule cannot be met
		if ( empty( $last_order_date ) ) {
			return false;
		}

		// Convert dates to timestamps for comparison
		$last_order_timestamp = strtotime( $last_order_date );

		switch ( $operator ) {
			case 'before':
				$rule_timestamp = strtotime( $rule_value );
				return $last_order_timestamp < $rule_timestamp;

			case 'after':
				$rule_timestamp = strtotime( $rule_value );
				return $last_order_timestamp > $rule_timestamp;

			case 'on':
				$rule_timestamp = strtotime( $rule_value );
				return gmdate( 'Y-m-d', $last_order_timestamp ) === gmdate( 'Y-m-d', $rule_timestamp );

			case 'between':
				if ( ! is_array( $rule_value ) || count( $rule_value ) < 2 ) {
					return false;
				}
				$start_timestamp = strtotime( $rule_value[0] );
				$end_timestamp   = strtotime( $rule_value[1] );
				return $last_order_timestamp >= $start_timestamp && $last_order_timestamp <= $end_timestamp;

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			RulesManager::instance()->register( new LastOrder() );
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register( new LastOrder() );
				}
			);
		}
	},
	99
);
