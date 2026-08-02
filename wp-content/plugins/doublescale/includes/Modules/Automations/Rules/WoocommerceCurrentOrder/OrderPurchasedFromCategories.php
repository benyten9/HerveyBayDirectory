<?php

namespace DoubleScale\Modules\Automations\Rules\WoocommerceCurrentOrder;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Order Purchased From Categories class
 */
class OrderPurchasedFromCategories extends Rule {



	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Purchased From Categories';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'order_purchased_from_categories';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'woocommerce_current_order';

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
			'includes'         => __( 'includes', 'doublescale' ),
			'not_includes_in'  => __( 'Does not include (in any)', 'doublescale' ),
			'includes_all'     => __( 'includes all', 'doublescale' ),
			'not_includes_all' => __( 'includes none of (match all)', 'doublescale' ),
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
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$options = array();
		if ( ! is_wp_error( $categories ) ) {
			foreach ( $categories as $category ) {
				$options[ $category->term_id ] = $category->name;
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
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		// Check if WooCommerce functions are available
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order_id = $automation_contact->get_data( 'order_id' );
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$category_ids = array();
		$items        = $order->get_items();

		foreach ( $items as $item ) {
			$product_id = $item->get_product_id();
			$product    = wc_get_product( $product_id );

			if ( $product ) {
				$product_categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $product_categories ) ) {
					$category_ids = array_merge( $category_ids, $product_categories );
				}
			}
		}

		// Remove duplicates and return unique category IDs
		return array_unique( $category_ids );
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
		$value      = $this->get_value( $automation_contact ); // Array of category IDs
		$operator   = $rule['operator'];
		$rule_value = $rule['value'] ?? array();

		// Ensure rule_value is an array (for multi_select compatibility)
		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		// Ensure value is an array
		if ( ! is_array( $value ) ) {
			$value = array();
		}

		// Convert to integers for proper comparison
		$value      = array_map( 'intval', $value );
		$rule_value = array_map( 'intval', $rule_value );

		switch ( $operator ) {
			case 'includes':
				// Check if any of the rule categories are in the order categories
				return ! empty( array_intersect( $value, $rule_value ) );

			case 'not_includes_in':
				// Check if none of the rule categories are in the order categories
				return empty( array_intersect( $value, $rule_value ) );

			case 'includes_all':
				// Check if all rule categories are in the order categories
				return empty( array_diff( $rule_value, $value ) );

			case 'not_includes_all':
				// Check if not all rule categories are in the order categories (includes none)
				return empty( array_intersect( $value, $rule_value ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) && taxonomy_exists( 'product_cat' ) ) {
			RulesManager::instance()->register( new OrderPurchasedFromCategories() );
		} else {
			add_action(
				'woocommerce_after_register_taxonomy',
				function () {
					RulesManager::instance()->register( new OrderPurchasedFromCategories() );
				}
			);
		}
	},
	99
);
