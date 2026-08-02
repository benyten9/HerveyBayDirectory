<?php

/**
 * Class Wishlist Items Categories
 *
 * This class is responsible for handling the wishlist items categories rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\WoocommerceWhishlist;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Wishlist Items Categories class
 */
class ItemsCategories extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Wishlist Items Categories';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'wishlist_items_categories';

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
		$categories = \get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'all',
			)
		);

		$options = array();
		if ( ! empty( $categories ) && ! \is_wp_error( $categories ) ) {
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
	 * @return array Array of category IDs from user's wishlist items
	 */
	public function get_value( $automation_contact ) {
		$product_id = $automation_contact->get_data( 'product_id' );

		if ( empty( $product_id ) ) {
			return array();
		}

		// Get product categories
		$category_ids = \wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( \is_wp_error( $category_ids ) ) {
			return array();
		}

		return $category_ids;
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
		$wishlist_categories = $this->get_value( $automation_contact );
		$operator            = $rule['operator'] ?? '';
		$rule_categories     = $rule['value'] ?? array();

		if ( ! is_array( $rule_categories ) ) {
			$rule_categories = array( $rule_categories );
		}

		if ( ! is_array( $wishlist_categories ) ) {
			$wishlist_categories = array();
		}

		$wishlist_categories = array_map( 'intval', $wishlist_categories );
		$rule_categories     = array_map( 'intval', $rule_categories );

		switch ( $operator ) {
			case 'matches_any_of':
				return ! empty( array_intersect( $wishlist_categories, $rule_categories ) );

			case 'matches_none_of':
				return empty( array_intersect( $wishlist_categories, $rule_categories ) );

			case 'matches_all_of':
				return empty( array_diff( $rule_categories, $wishlist_categories ) );

			default:
				return false;
		}
	}
}

\add_action(
	'init',
	function () {
		if ( \class_exists( 'WooCommerce' ) ) {
			RulesManager::instance()->register( new ItemsCategories() );
		} else {
			add_action(
				'woocommerce_whishlist_loaded',
				function () {
					RulesManager::instance()->register( new ItemsCategories() );
				}
			);
		}
	},
	99
);
