<?php

/**
 * Class Subscription Has Status
 *
 * This class is responsible for handling the subscription has status rule
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
 * Subscription Has Status class
 */
class SubscriptionHasStatus extends Rule {



	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Subscription Has Status';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'subscription_has_status';

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

		if ( ! function_exists( 'wcs_get_subscription_statuses' ) ) {
			return array();
		}

		$options        = wcs_get_subscription_statuses();
		$formatted_opts = array();

		foreach ( $options as $key => $label ) {
			$formatted_opts[ $label ] = strtoupper( $label );
		}

		return $formatted_opts;
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
		$status = $automation_contact->get_data( 'subscription_id' );
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$subscription = wcs_get_subscription( $status );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		$subscription_status = $subscription instanceof WC_Subscription ? 'wc-' . $subscription->get_status() : '';

		return $subscription_status ? $subscription_status : '';
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
		$rule_value = $rule['value'];

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

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


add_action(
	'init',
	function () {
		if ( class_exists( 'WC_Subscriptions' ) ) {
			RulesManager::instance()->register( new SubscriptionHasStatus() );
		} else {
			add_action(
				'woocommerce_subscriptions_loaded',
				function () {
					RulesManager::instance()->register( new SubscriptionHasStatus() );
				}
			);
		}
	},
	99
);
