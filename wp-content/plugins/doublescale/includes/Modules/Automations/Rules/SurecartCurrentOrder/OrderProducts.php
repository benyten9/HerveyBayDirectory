<?php

namespace DoubleScale\Modules\Automations\Rules\SurecartCurrentOrder;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Order Products class
 */
class OrderProducts extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Products in Order';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'surecart_order_products';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'surecart_current_order';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'multiselect';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'includes'         => __( 'Includes', 'doublescale' ),
			'not_includes_in'  => __( 'Does not include (in any)', 'doublescale' ),
			'includes_all'     => __( 'Includes all', 'doublescale' ),
			'not_includes_all' => __( 'Includes none of (match all)', 'doublescale' ),
		);
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
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		if ( ! class_exists( '\SureCart\Models\Product' ) ) {
			return array();
		}

		try {
			$products = \SureCart\Models\Product::where( array( 'archived' => false ) )->get();

			$options = array();
			if ( is_array( $products ) ) {
				foreach ( $products as $product ) {
					$options[ $product->id ] = $product->name ?? '';
				}
			}

			return $options;
		} catch ( \Exception $e ) {
			return array();
		}
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
		return $automation_contact->get_data( 'product_ids', array() );
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

		// Ensure both are arrays
		if ( ! is_array( $rule_value ) ) {
			$rule_value = array( $rule_value );
		}
		if ( ! is_array( $value ) ) {
			$value = array();
		}

		switch ( $operator ) {
			case 'includes':
				// Check if any of the rule products are in the order products
				return ! empty( array_intersect( $value, $rule_value ) );

			case 'not_includes_in':
				// Check if none of the rule products are in the order products
				return empty( array_intersect( $value, $rule_value ) );

			case 'includes_all':
				// Check if all rule products are in the order products
				return empty( array_diff( $rule_value, $value ) );

			case 'not_includes_all':
				// Check if not all rule products are in the order products (includes none)
				return empty( array_intersect( $value, $rule_value ) );

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'SURECART_PLUGIN_FILE' ) ) {
			RulesManager::instance()->register( new OrderProducts() );
		}
	},
	99
);
