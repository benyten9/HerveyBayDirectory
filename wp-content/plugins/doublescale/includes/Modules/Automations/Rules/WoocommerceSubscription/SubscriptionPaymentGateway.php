<?php

/**
 * Class Subscription Payment Gateway
 *
 * This class is responsible for handling the subscription payment gateway rule
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
 * Subscription Payment Gateway class
 */
class SubscriptionPaymentGateway extends Rule {





	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Subscription Payment Gateway';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'subscription_payment_gateway';

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
			'is'     => \__( 'Is', 'doublescale' ),
			'not_is' => \__( 'Is not', 'doublescale' ),
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
		$result           = array();
		$result['manual'] = __( 'Manual Renewal', 'doublescale' );
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled && in_array( 'subscriptions', $gateway->supports, true ) ) {
				$result[ $gateway->id ] = $gateway->get_title();
			}
		}

		return $result;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$subscription_id = $automation_contact->get_data( 'subscription_id' );

		if ( ! \function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$subscription = \wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		$payment = $subscription instanceof WC_Subscription ? $subscription->get_payment_method() : false;

		/** if empty then check for manual */
		if ( empty( $payment ) && $subscription->is_manual() ) {
			$payment = 'manual';
		}
		return $payment;
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

		switch ( $operator ) {
			case 'is':
				return in_array( $value, $rule_value, true );
			case 'is_not':
				return ! in_array( $value, $rule_value, true );
			default:
				return false;
		}
	}
}

\add_action(
	'init',
	function () {
		if ( \class_exists( 'WC_Subscriptions' ) ) {
			RulesManager::instance()->register( new SubscriptionPaymentGateway() );
		} else {
			\add_action(
				'woocommerce_subscriptions_loaded',
				function () {
					RulesManager::instance()->register( new SubscriptionPaymentGateway() );
				}
			);
		}
	},
	99
);
