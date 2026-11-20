<?php
/**
 * FluentCart "Order Refunded" trigger.
 *
 * Fires on FluentCart's `fluent_cart/order_refunded`, for a full or a partial
 * refund; the automation can be limited to one of the two.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Fluentcart;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\FluentCartOrders;
use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * OrderRefunded trigger
 */
class OrderRefunded extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Refunded';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_refunded';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a FluentCart order is refunded, fully or partly.';

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
		// FluentCartOrders ships with the free plugin; an older free has none.
		if ( ! class_exists( FluentCartOrders::class ) ) {
			return;
		}
		add_action( 'fluent_cart/order_refunded', array( $this, 'handle' ), 20, 1 );
	}

	/**
	 * Handle a refund.
	 *
	 * @param mixed $payload FluentCart payload: order, refunded_amount (cents), type (full|partial).
	 * @return void
	 */
	public function handle( $payload ) {
		$order = FluentCartOrders::find_order( FluentCartOrders::order_id_from_payload( $payload ) );
		if ( ! $order || ! is_email( $order['email'] ) ) {
			return;
		}

		$type = is_array( $payload ) && 'partial' === ( $payload['type'] ?? '' ) ? 'partial' : 'full';

		$this->process(
			FluentCartOrders::trigger_args(
				$order,
				array(
					'refund_type'     => $type,
					'refunded_amount' => FluentCartOrders::to_amount( is_array( $payload ) ? ( $payload['refunded_amount'] ?? 0 ) : 0 ),
				)
			)
		);
	}

	/**
	 * Refund type and products, when chosen.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$refund_type = (string) $automation->get_setting( 'refund_type', 'any' );
		if ( in_array( $refund_type, array( 'full', 'partial' ), true ) && ( $args['data']['refund_type'] ?? '' ) !== $refund_type ) {
			return false;
		}

		return ProductFilter::matches( $automation->get_setting( 'product_ids', array() ), $args['data']['product_ids'] ?? array() );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'refund_type' => array(
				'label'   => __( 'Refund Type', 'doublescale' ),
				'type'    => 'select',
				'options' => array(
					'any'     => __( 'Any refund', 'doublescale' ),
					'full'    => __( 'Full refund', 'doublescale' ),
					'partial' => __( 'Partial refund', 'doublescale' ),
				),
				'default' => 'any',
			),
			'product_ids' => ProductFilter::field( __( 'Select products to filter this trigger. Leave empty to trigger for all products.', 'doublescale' ) ),
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
				'refund_type' => array(
					'type' => 'string',
					'enum' => array( 'any', 'full', 'partial' ),
				),
				'product_ids' => ProductFilter::schema(),
			),
		);
	}
}
