<?php

namespace DoubleScale\Modules\Automations\Rules\WoocommerceCurrentOrder;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Order Purchased From Tags class
 *
 * Product tags of the order that started the run. The "WooCommerce" group's
 * "Purchased Tags" looks across every order the contact placed instead.
 */
class OrderPurchasedFromTags extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Purchased Tags';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'order_purchased_from_tags';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'woocommerce_current_order';

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'multiselect';

	/**
	 * Has options
	 *
	 * @return bool
	 */
	public function has_options() {
		return true;
	}

	/**
	 * Get operators
	 *
	 * Same set as "Purchased From Categories" in this group.
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
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return array Tag ids on the order's products.
	 */
	public function get_value( $automation_contact ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order = wc_get_order( $automation_contact->get_data( 'order_id' ) );

		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$tag_ids = array();

		foreach ( $order->get_items() as $item ) {
			// For a variation line this is the parent product, which carries the tags.
			$product_id = $item->get_product_id();
			if ( ! $product_id ) {
				continue;
			}

			$product_tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $product_tags ) ) {
				$tag_ids = array_merge( $tag_ids, $product_tags );
			}
		}

		return array_values( array_unique( array_map( 'intval', $tag_ids ) ) );
	}

	/**
	 * Is met
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                  $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'] ?? '';
		$rule_value = $rule['value'] ?? array();

		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}

		$rule_value = array_map( 'intval', $rule_value );

		switch ( $operator ) {
			case 'includes':
				return ! empty( array_intersect( $value, $rule_value ) );

			case 'not_includes_in':
			case 'not_includes_all':
				return empty( array_intersect( $value, $rule_value ) );

			case 'includes_all':
				return empty( array_diff( $rule_value, $value ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) && taxonomy_exists( 'product_tag' ) ) {
			RulesManager::instance()->register( new OrderPurchasedFromTags() );
		} else {
			add_action(
				'woocommerce_after_register_taxonomy',
				function () {
					RulesManager::instance()->register( new OrderPurchasedFromTags() );
				}
			);
		}
	},
	99
);
