<?php

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\WooPurchaseHistory;
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
	 * "Within" / "Not within" take a day count (client request 129): an
	 * automation can skip promotions for whoever bought recently.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'before'     => __( 'Before', 'doublescale' ),
			'after'      => __( 'After', 'doublescale' ),
			'on'         => __( 'On', 'doublescale' ),
			'between'    => __( 'Between', 'doublescale' ),
			'within'     => __( 'Within', 'doublescale' ),
			'not_within' => __( 'Not within', 'doublescale' ),
		);
	}

	/**
	 * Unix time of the contact's latest purchase, or null.
	 *
	 * Orders billed to the contact's email and orders on a site account with
	 * that email both count — reading only the account missed guest checkouts.
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return int|null
	 */
	private function get_last_order_timestamp( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return null;
		}

		return WooPurchaseHistory::latest_order_timestamp( (string) $contact->email );
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return string|null Order date in Y-m-d (site timezone).
	 */
	public function get_value( $automation_contact ) {
		$timestamp = $this->get_last_order_timestamp( $automation_contact );

		return null === $timestamp ? null : wp_date( 'Y-m-d', $timestamp );
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
		$operator   = $rule['operator'] ?? '';
		$rule_value = $rule['value'] ?? '';

		if ( 'within' === $operator || 'not_within' === $operator ) {
			// A day count, or a date ("since that day") saved by an older screen.
			$start = WooPurchaseHistory::window_start( $rule_value );
			if ( null === $start ) {
				return false;
			}

			$timestamp = $this->get_last_order_timestamp( $automation_contact );
			$within    = null !== $timestamp && $timestamp >= $start;

			// Never bought counts as "not within": nothing was bought in the window.
			return 'within' === $operator ? $within : ! $within;
		}

		$last_order_date = $this->get_value( $automation_contact );

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
