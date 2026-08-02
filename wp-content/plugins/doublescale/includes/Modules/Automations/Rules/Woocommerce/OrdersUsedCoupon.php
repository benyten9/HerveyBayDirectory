<?php

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Orders Used Coupon class
 */
class OrdersUsedCoupon extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Orders Used Coupon';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'orders_used_coupon';

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
			'matches_any_of'  => __( 'Matches any of', 'doublescale' ),
			'matches_none_of' => __( 'Matches none of', 'doublescale' ),
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
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$coupons = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$options = array();
		foreach ( $coupons as $coupon ) {
			$options[ $coupon->ID ] = $coupon->post_title;
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

		$used_coupons = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$order_coupons = $order->get_coupon_codes();

			foreach ( $order_coupons as $coupon_code ) {
				$coupon_query = new \WP_Query(
					array(
						'post_type'              => 'shop_coupon',
						'title'                  => $coupon_code,
						'posts_per_page'         => 1,
						'no_found_rows'          => true,
						'ignore_sticky_posts'    => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$coupon_post  = ! empty( $coupon_query->posts ) ? $coupon_query->posts[0] : null;
				if ( $coupon_post ) {
					$used_coupons[] = $coupon_post->ID;
				}
			}
		}

		return array_values( array_unique( $used_coupons ) );
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
		$used_coupons = $this->get_value( $automation_contact );
		$operator     = $rule['operator'];
		$rule_value   = $rule['value'] ?? array();

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		$used_coupons = array_map( 'intval', $used_coupons );
		$rule_value   = array_map( 'intval', $rule_value );

		switch ( $operator ) {
			case 'matches_any_of':
				return ! empty( array_intersect( $used_coupons, $rule_value ) );
			case 'matches_none_of':
				return empty( array_intersect( $used_coupons, $rule_value ) );
			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			RulesManager::instance()->register( new OrdersUsedCoupon() );
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register( new OrdersUsedCoupon() );
				}
			);
		}
	},
	99
);
