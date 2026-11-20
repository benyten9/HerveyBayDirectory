<?php
/**
 * FluentCart "Order Received" goal: reached when the contact pays an order
 * (optionally one with a chosen product).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Goals\Fluentcart;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\FluentCartOrders;
use DoubleScale\Modules\Automations\Abstracts\GoalPro;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Automations\Triggers\Fluentcart\ProductFilter;

/**
 * OrderReceived goal
 */
class OrderReceived extends GoalPro {

	/**
	 * Goal Name
	 *
	 * @var string
	 */
	public $name = 'Order Received';

	/**
	 * Goal Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_received';

	/**
	 * Goal Description
	 *
	 * @var string
	 */
	public $description = 'This goal is achieved when the contact pays a FluentCart order.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'fluentcart';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Load Hooks
	 *
	 * @return void
	 */
	public function load_hooks() {
		if ( ! class_exists( FluentCartOrders::class ) ) {
			return;
		}
		add_action( 'fluent_cart/order_paid_done', array( $this, 'handle' ), 25, 1 );
	}

	/**
	 * Handle a paid order.
	 *
	 * @param mixed $payload FluentCart payload.
	 * @return void
	 */
	public function handle( $payload ) {
		$order = FluentCartOrders::find_order( FluentCartOrders::order_id_from_payload( $payload ) );
		if ( ! $order || ! is_email( $order['email'] ) ) {
			return;
		}

		$contact = ContactModel::where( 'email', $order['email'] )->first();
		if ( ! $contact ) {
			return;
		}

		$this->process(
			$contact,
			array(
				'order_id'    => $order['id'],
				'product_ids' => $order['product_ids'],
			)
		);
	}

	/**
	 * Is completed
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param mixed                  $data               Order data.
	 * @return bool
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		$current_step = AutomationStepModel::find( $automation_contact->current_step );
		if ( ! $current_step ) {
			return false;
		}

		return ProductFilter::matches(
			$current_step->get_setting( 'product_ids', array() ),
			is_array( $data ) ? ( $data['product_ids'] ?? array() ) : array()
		);
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'product_ids' => ProductFilter::field( __( 'Select products to filter this goal. Leave empty to complete for any product.', 'doublescale' ) ),
		);
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
				'product_ids' => ProductFilter::schema(),
			),
		);
	}
}
