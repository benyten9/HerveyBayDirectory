<?php

/**
 * WooCommerce Order Completed Trigger
 * This trigger will be fired when an order is completed.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Order;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use WC_Order;

/**
 * Order Completed Trigger
 */
class OrderCompleted extends Trigger {

	use OrderTriggerProductFilter;
	use OrderTriggerPhone;
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Completed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_order_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when an order is completed.';

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
	public $source = 'woocommerce';

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
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_completed' ) );
	}

	/**
	 * Order Completed
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return void
	 */
	public function order_completed( $order_id ) {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$data = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'phone'      => $this->resolve_order_phone( $order ),
			'country'    => $this->resolve_order_country( $order ),
			'data'       => array(
				'order_id' => $order->get_id(),
			),
		);

		$this->process( $data );
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger payload.
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		return $this->order_matches_product_filter( $automation, $args );
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
				'type'       => 'infinite_scroll_multiselect',
				'label'      => __( 'Products', 'doublescale' ),
				'endpoint'   => '/wc/v3/products',
				'helperText' => __( 'Optional: only when the order contains at least one of these products. Leave empty for any product.', 'doublescale' ),
				'settings'   => array(
					'rootArrayResponse' => true,
					'perPage'           => 20,
					'searchParamName'   => 'search',
					'apiParams'         => array(
						'status' => 'publish',
					),
				),
			),
		);
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
					'items' => array(
						'type' => 'integer',
					),
				),
			),
		);
	}
}
