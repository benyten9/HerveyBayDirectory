<?php


namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Orders Country class
 */
class OrdersCountry extends Rule {





	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Orders Country';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'orders_country';

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
			'includes_in'     => __( 'includes in', 'doublescale' ),
			'not_includes_in' => __( 'not includes in', 'doublescale' ),
			'empty'           => __( 'empty', 'doublescale' ),
			'not_empty'       => __( 'not empty', 'doublescale' ),
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
		$countries = new \WC_Countries();
		return $countries->get_countries();
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
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return array();
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$query_args = array(
			'limit'  => -1,
			'status' => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
		);

		$user = get_user_by( 'email', $contact->email );

		if ( $user ) {
			$query_args['customer_id'] = $user->ID;
		} else {
			$query_args['billing_email'] = $contact->email;
		}

		$orders = wc_get_orders( $query_args );

		if ( empty( $orders ) ) {
			return array();
		}

		$countries = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$billing_country  = $order->get_billing_country();
			$shipping_country = $order->get_shipping_country();

			if ( ! empty( $billing_country ) ) {
				$countries[] = $billing_country;
			}

			if ( ! empty( $shipping_country ) && $shipping_country !== $billing_country ) {
				$countries[] = $shipping_country;
			}
		}

		return array_values( array_unique( $countries ) );
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
		$customer_countries = $this->get_value( $automation_contact );
		$operator           = $rule['operator'];
		$rule_value         = $rule['value'] ?? array();

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		switch ( $operator ) {
			case 'includes_in':
				return ! empty( array_intersect( $customer_countries, $rule_value ) );
			case 'not_includes_in':
				return empty( array_intersect( $customer_countries, $rule_value ) );
			case 'empty':
				return empty( $customer_countries );
			case 'not_empty':
				return ! empty( $customer_countries );
			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			RulesManager::instance()->register( new OrdersCountry() );
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register( new OrdersCountry() );
				}
			);
		}
	},
	99
);
