<?php

/**
 * SureCart Order Success Trigger
 * This trigger will be fired when an order payment is successful.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Surecart\Order;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * Order Success Trigger
 */
class OrderSuccess extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Success';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'surecart_order_success';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a SureCart order payment is successful.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'surecart';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'surecart/purchase_created', array( $this, 'handle_purchase_created' ), 20, 1 );
	}

	/**
	 * Handle Purchase Created
	 *
	 * @since 1.0.0
	 *
	 * @param object $purchase Purchase object.
	 * @return void
	 */
	public function handle_purchase_created( $purchase ) {
		$order_data = $this->get_order_data( $purchase );
		if ( ! $order_data ) {
			return;
		}

		$this->process( $order_data );
	}

	/**
	 * Get Order Data from Purchase
	 *
	 * @since 1.0.0
	 *
	 * @param object $purchase Purchase object.
	 * @return array|null
	 */
	private function get_order_data( $purchase ) {
		$initial_order_id = $purchase->initial_order ?? null;

		if ( empty( $initial_order_id ) || ! is_string( $initial_order_id ) ) {
			return null;
		}

		if ( ! class_exists( '\Surecart\Models\Order' ) ) {
			return null;
		}

		$order = \Surecart\Models\Order::with( array( 'checkout', 'checkout.purchases' ) )->find( $initial_order_id );

		if ( ! $order || is_wp_error( $order ) ) {
			return null;
		}

		$product_ids = array();
		if ( isset( $order->checkout->purchases->data ) ) {
			foreach ( $order->checkout->purchases->data as $purchase_item ) {
				if ( isset( $purchase_item->product ) ) {
					$product_ids[] = $purchase_item->product;
				}
			}
		}

		return array(
			'order_id'    => $initial_order_id,
			'email'       => $order->checkout->email ?? '',
			'first_name'  => $order->checkout->first_name ?? '',
			'last_name'   => $order->checkout->last_name ?? '',
			'phone'       => $order->checkout->phone ?? '',
			'product_ids' => $product_ids,
			'total'       => isset( $order->checkout->total_amount ) ? ( $order->checkout->total_amount / 100 ) : 0,
			'currency'    => $order->checkout->currency ?? '',
		);
	}

	/**
	 * Check if trigger should be processed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model
	 * @param array            $args Arguments
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$product_ids = $automation->get_setting( 'product_ids', array() );

		// If no products specified, process for all orders
		if ( empty( $product_ids ) ) {
			return true;
		}

		// Check if any of the order's products match the filter
		$order_product_ids = $args['product_ids'] ?? array();
		return ! empty( array_intersect( $order_product_ids, $product_ids ) );
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'product_ids' => array(
				'label'   => __( 'Target Products', 'doublescale'),
				'type'    => 'multiselect',
				'options' => $this->get_product_options(),
				'help'    => __( 'Select products to filter this trigger. Leave empty to trigger for all products.', 'doublescale'),
			),
		);
	}

	/**
	 * Get SureCart product options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_product_options() {
		if ( ! class_exists( '\Surecart\Models\Product' ) ) {
			return array();
		}

		try {
			$products = \Surecart\Models\Product::where( array( 'archived' => false ) )->get();

			$options = array();
			if ( is_array( $products ) ) {
				foreach ( $products as $product ) {
					$options[ $product->id ?? '' ] = $product->name ?? '';
				}
			}

			return $options;
		} catch ( \Exception $e ) {
			return array();
		}
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}
}
