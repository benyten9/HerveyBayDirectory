<?php

/**
 * SureCart Order Received Goal
 * This goal will be achieved when a SureCart order is received.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Goals\Surecart;

use DoubleScale\Modules\Automations\Abstracts\GoalPro;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

/**
 * Order Received Goal
 */
class OrderReceived extends GoalPro {

	/**
	 * Goal Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Order Received';

	/**
	 * Goal Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'surecart_order_received';

	/**
	 * Goal Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'This goal is achieved when a SureCart order is received.';

	/**
	 * Source
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $source = 'surecart';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
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
		add_action( 'surecart/purchase_created', array( $this, 'handle_purchase_created' ), 25, 1 );
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
		if ( ! $order_data || empty( $order_data['email'] ) ) {
			return;
		}

		$contact = ContactModel::where( 'email', $order_data['email'] )->first();
		if ( ! $contact ) {
			return;
		}

		$this->process( $contact, $order_data );
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
			'product_ids' => $product_ids,
			'total'       => isset( $order->checkout->total_amount ) ? ( $order->checkout->total_amount / 100 ) : 0,
			'currency'    => $order->checkout->currency ?? '',
		);
	}

	/**
	 * Check if the goal is completed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 * @param array                    $data Data.
	 *
	 * @return bool
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		$current_step = AutomationStepModel::find( $automation_contact->current_step );

		if ( ! $current_step ) {
			return false;
		}

		$product_ids = $current_step->get_setting( 'product_ids', array() );

		// If no products specified, any order completes the goal
		if ( empty( $product_ids ) ) {
			return true;
		}

		// Check if any of the order's products match the filter
		$order_product_ids = $data['product_ids'] ?? array();
		return ! empty( array_intersect( $product_ids, $order_product_ids ) );
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
				'help'    => __( 'Select products to filter this goal. Leave empty to complete for any product.', 'doublescale'),
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
