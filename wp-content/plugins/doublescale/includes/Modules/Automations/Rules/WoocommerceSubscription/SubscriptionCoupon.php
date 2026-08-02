<?php

/**
 * Class Subscription Coupon
 *
 * This class is responsible for handling the subscription coupon rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\WoocommerceSubscription;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Subscription Coupon class
 */
class SubscriptionCoupon extends Rule {




	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Subscription Coupon';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'subscription_coupon';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'woocommerce_subscription';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'multiselect';

	/**
	 * Has options
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_options() {
		return true;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'matches_any_of'  => \__( 'Matches any of', 'doublescale' ),
			'matches_none_of' => \__( 'Matches none of', 'doublescale' ),
			'matches_all_of'  => \__( 'Matches all of', 'doublescale' ),
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

		$coupons = \get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $coupons ) ) {
			foreach ( $coupons as $coupon_id ) {
				$coupon = new \WC_Coupon( $coupon_id );
				if ( $coupon && $coupon->get_code() ) {
					$options[ $coupon->get_code() ] = $coupon->get_code();
				}
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
	 * @return array
	 */
	public function get_value( $automation_contact ) {
		$subscription_id = $automation_contact->get_data( 'subscription_id' );

		if ( ! \function_exists( 'wcs_get_subscription' ) ) {
			return array();
		}

		$subscription = \wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return array();
		}

		// Get used coupons from subscription
		$used_coupons = array();
		if ( version_compare( WC()->version, 3.7, '>=' ) ) {
			$used_coupons = $subscription->get_coupon_codes();
		} else {
			$used_coupons = $subscription->get_used_coupons();
		}

		return is_array( $used_coupons ) ? $used_coupons : array();
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
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'];
		$rule_value = $rule['value'] ?? array();

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		if ( ! is_array( $value ) ) {
			$value = array();
		}

		switch ( $operator ) {
			case 'matches_any_of':
				return ! empty( array_intersect( $value, $rule_value ) );

			case 'matches_none_of':
				return empty( array_intersect( $value, $rule_value ) );

			case 'matches_all_of':
				return empty( array_diff( $rule_value, $value ) );

			default:
				return false;
		}
	}
}

\add_action(
	'init',
	function () {
		if ( \class_exists( 'WC_Subscriptions' ) ) {
			RulesManager::instance()->register( new SubscriptionCoupon() );
		} else {
			\add_action(
				'woocommerce_subscriptions_loaded',
				function () {
					RulesManager::instance()->register( new SubscriptionCoupon() );
				}
			);
		}
	},
	99
);
