<?php

/**
 * Class Wishlist Items
 *
 * This class is responsible for handling the wishlist items rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\WoocommerceWhishlist;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Rules\Woocommerce\ProductPicker;
use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Wishlist Items class
 */
class Items extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Wishlist Items';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'wishlist_items';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'woocommerce_whishlist';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = ProductPicker::TYPE;

	/**
	 * Searched as the admin types (WooCommerce's REST API), never listed in full.
	 *
	 * @var string
	 */
	public $endpoint = ProductPicker::ENDPOINT;

	/**
	 * How the editor reads the endpoint.
	 *
	 * @var array
	 */
	public $settings = ProductPicker::SETTINGS;

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
			'matches_any_of'  => __( 'Matches any of', 'doublescale' ),
			'matches_none_of' => __( 'Matches none of', 'doublescale' ),
			'matches_all_of'  => __( 'Matches all of', 'doublescale' ),
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
		// The picker searches products on demand (endpoint above); listing
		// every product here ran on each DoubleScale admin page.
		return array();
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return array Array of product IDs from user's wishlist
	 */
	public function get_value( $automation_contact ) {
		$product_id = $automation_contact->get_data( 'product_id' );
		return array( $product_id );
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
		$wishlist_items = $this->get_value( $automation_contact );
		$operator       = $rule['operator'] ?? '';
		$rule_products  = $rule['value'] ?? array();

		if ( ! is_array( $rule_products ) ) {
			$rule_products = array( $rule_products );
		}

		if ( ! is_array( $wishlist_items ) ) {
			$wishlist_items = array();
		}

		$wishlist_items = array_map( 'intval', $wishlist_items );
		$rule_products  = array_map( 'intval', $rule_products );

		switch ( $operator ) {
			case 'matches_any_of':
				return ! empty( array_intersect( $wishlist_items, $rule_products ) );

			case 'matches_none_of':
				return empty( array_intersect( $wishlist_items, $rule_products ) );

			case 'matches_all_of':
				return empty( array_diff( $rule_products, $wishlist_items ) );

			default:
				return false;
		}
	}
}

\add_action(
	'init',
	function () {
		if ( \class_exists( 'WooCommerce' ) ) {
			RulesManager::instance()->register( new Items() );
		} else {
			add_action(
				'woocommerce_whishlist_loaded',
				function () {
					RulesManager::instance()->register( new Items() );
				}
			);
		}
	},
	99
);
