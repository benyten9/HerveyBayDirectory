<?php

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Orders Purchased From Brands class
 *
 * Condition for whether a contact has purchased products from specific brands
 * (WooCommerce `product_brand` taxonomy).
 */
class OrdersPurchasedFromBrands extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Purchased Brands';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'orders_purchased_from_brands';

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
	 * Brand taxonomy slug used by WooCommerce Brands.
	 *
	 * @return string
	 */
	private function get_taxonomy(): string {
		return 'product_brand';
	}

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
		$brands = get_terms(
			array(
				'taxonomy'   => $this->get_taxonomy(),
				'hide_empty' => false,
			)
		);

		$options = array();
		if ( ! is_wp_error( $brands ) ) {
			foreach ( $brands as $brand ) {
				$options[ $brand->term_id ] = $brand->name;
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
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return array();
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$taxonomy = $this->get_taxonomy();
		if ( ! taxonomy_exists( $taxonomy ) ) {
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

		$brand_ids = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$items = $order->get_items();

			foreach ( $items as $item ) {
				$product_id = $item->get_product_id();
				$product    = wc_get_product( $product_id );

				if ( $product ) {
					$product_brands = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $product_brands ) ) {
						$brand_ids = array_merge( $brand_ids, $product_brands );
					}

					// Also check variation parent brands if this is a variation.
					if ( $product->is_type( 'variation' ) ) {
						$parent_id = $product->get_parent_id();
						if ( $parent_id ) {
							$parent_brands = wp_get_post_terms( $parent_id, $taxonomy, array( 'fields' => 'ids' ) );
							if ( ! is_wp_error( $parent_brands ) ) {
								$brand_ids = array_merge( $brand_ids, $parent_brands );
							}
						}
					}
				}
			}
		}

		return array_values( array_unique( $brand_ids ) );
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
		$customer_brands = $this->get_value( $automation_contact );
		$operator        = $rule['operator'];
		$rule_value      = $rule['value'] ?? array();

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		if ( ! is_array( $customer_brands ) ) {
			$customer_brands = array();
		}

		$customer_brands = array_map( 'intval', $customer_brands );
		$rule_value      = array_map( 'intval', $rule_value );

		switch ( $operator ) {
			case 'matches_any_of':
				return ! empty( array_intersect( $customer_brands, $rule_value ) );

			case 'matches_none_of':
				return empty( array_intersect( $customer_brands, $rule_value ) );

			case 'matches_all_of':
				return empty( array_diff( $rule_value, $customer_brands ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) && taxonomy_exists( 'product_brand' ) ) {
			RulesManager::instance()->register( new OrdersPurchasedFromBrands() );
		} else {
			add_action(
				'woocommerce_after_register_taxonomy',
				function () {
					if ( taxonomy_exists( 'product_brand' ) ) {
						RulesManager::instance()->register( new OrdersPurchasedFromBrands() );
					}
				}
			);
		}
	},
	99
);
