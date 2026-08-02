<?php

/**
 * Class Subscription Coupon Text
 *
 * This class is responsible for handling the subscription coupon text rule
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
 * Subscription Coupon Text class
 */
class SubscriptionCouponText extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Subscription Coupon Text';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'subscription_coupon_text';

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
	public $type = 'text';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'starts_with'         => \__( 'Starts with', 'doublescale' ),
			'ends_with'           => \__( 'Ends with', 'doublescale' ),
			'any_contains'        => \__( 'Any contains', 'doublescale' ),
			'any_matches_exactly' => \__( 'Any matches exactly', 'doublescale' ),
		);
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
		if ( \method_exists( $subscription, 'get_used_coupons' ) ) {
			$used_coupons = $subscription->get_used_coupons();
		} elseif ( \method_exists( $subscription, 'get_coupon_codes' ) ) {
			$used_coupons = $subscription->get_coupon_codes();
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
		$coupons    = $this->get_value( $automation_contact );
		$operator   = $rule['operator'];
		$rule_value = $rule['value'] ?? '';

		if ( ! is_array( $coupons ) || empty( $coupons ) ) {
			return false;
		}

		// Convert rule value to lowercase for case-insensitive comparison
		$rule_value = \strtolower( $rule_value );

		switch ( $operator ) {
			case 'starts_with':
				foreach ( $coupons as $coupon ) {
					if ( \strpos( \strtolower( $coupon ), $rule_value ) === 0 ) {
						return true;
					}
				}
				return false;

			case 'ends_with':
				foreach ( $coupons as $coupon ) {
					$coupon_lower = \strtolower( $coupon );
					if ( \substr( $coupon_lower, -\strlen( $rule_value ) ) === $rule_value ) {
						return true;
					}
				}
				return false;

			case 'any_contains':
				foreach ( $coupons as $coupon ) {
					if ( \strpos( \strtolower( $coupon ), $rule_value ) !== false ) {
						return true;
					}
				}
				return false;

			case 'any_matches_exactly':
				foreach ( $coupons as $coupon ) {
					if ( \strtolower( $coupon ) === $rule_value ) {
						return true;
					}
				}
				return false;

			default:
				return false;
		}
	}
}

\add_action(
	'init',
	function () {
		if ( \class_exists( 'WC_Subscriptions' ) ) {
			RulesManager::instance()->register( new SubscriptionCouponText() );
		} else {
			\add_action(
				'woocommerce_subscriptions_loaded',
				function () {
					RulesManager::instance()->register( new SubscriptionCouponText() );
				}
			);
		}
	},
	99
);
