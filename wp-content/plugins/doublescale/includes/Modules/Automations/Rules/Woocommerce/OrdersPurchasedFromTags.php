<?php

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Orders Purchased From Tags class
 */
class OrdersPurchasedFromTags extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Purchased Tags';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'orders_purchased_from_tags';

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
			'matches_any_of' => __( 'Matches any of', 'doublescale' ),
			'matches_all_of' => __( 'Matches all of', 'doublescale' ),
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
		$tags = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);

		$options = array();
		if ( ! is_wp_error( $tags ) ) {
			foreach ( $tags as $tag ) {
				$options[ $tag->term_id ] = $tag->name;
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

		$tag_ids = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$items = $order->get_items();

			foreach ( $items as $item ) {
				$product_id = $item->get_product_id();
				$product    = wc_get_product( $product_id );

				if ( $product ) {
					$product_tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $product_tags ) ) {
						$tag_ids = array_merge( $tag_ids, $product_tags );
					}

					// Also check variation parent tags if this is a variation
					if ( $product->is_type( 'variation' ) ) {
						$parent_id = $product->get_parent_id();
						if ( $parent_id ) {
							$parent_tags = wp_get_post_terms( $parent_id, 'product_tag', array( 'fields' => 'ids' ) );
							if ( ! is_wp_error( $parent_tags ) ) {
								$tag_ids = array_merge( $tag_ids, $parent_tags );
							}
						}
					}
				}
			}
		}

		return array_values( array_unique( $tag_ids ) );
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
		$customer_tags = $this->get_value( $automation_contact );
		$operator      = $rule['operator'];
		$rule_value    = $rule['value'] ?? array();

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		if ( ! is_array( $customer_tags ) ) {
			$customer_tags = array();
		}

		$customer_tags = array_map( 'intval', $customer_tags );
		$rule_value    = array_map( 'intval', $rule_value );

		switch ( $operator ) {
			case 'matches_any_of':
				return ! empty( array_intersect( $customer_tags, $rule_value ) );

			case 'matches_all_of':
				return empty( array_diff( $rule_value, $customer_tags ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) && taxonomy_exists( 'product_tag' ) ) {
			RulesManager::instance()->register( new OrdersPurchasedFromTags() );
		} else {
			add_action(
				'woocommerce_after_register_taxonomy',
				function () {
					RulesManager::instance()->register( new OrdersPurchasedFromTags() );
				}
			);
		}
	},
	99
);
