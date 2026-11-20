<?php
/**
 * FluentCart "Order Status Changed" trigger.
 *
 * Fires on FluentCart's `fluent_cart/order_status_changed` (the order status:
 * processing, completed, on hold, canceled, failed; not the payment status).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Fluentcart;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\FluentCartOrders;
use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * OrderStatusChanged trigger
 */
class OrderStatusChanged extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Status Changed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_status_changed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when the status of a FluentCart order changes.';

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
		add_action( 'fluent_cart/order_status_changed', array( $this, 'handle' ), 20, 1 );
	}

	/**
	 * Handle a status change.
	 *
	 * @param mixed $payload FluentCart payload: order, old_status, new_status.
	 * @return void
	 */
	public function handle( $payload ) {
		$order = FluentCartOrders::find_order( FluentCartOrders::order_id_from_payload( $payload ) );
		if ( ! $order || ! is_email( $order['email'] ) ) {
			return;
		}

		$this->process(
			FluentCartOrders::trigger_args(
				$order,
				array(
					'old_status' => is_array( $payload ) ? (string) ( $payload['old_status'] ?? '' ) : '',
					'new_status' => is_array( $payload ) ? (string) ( $payload['new_status'] ?? $order['status'] ) : $order['status'],
				)
			)
		);
	}

	/**
	 * From/to statuses, when chosen.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$from = (string) $automation->get_setting( 'from_status', 'any' );
		$to   = (string) $automation->get_setting( 'to_status', 'any' );

		if ( '' !== $from && 'any' !== $from && ( $args['data']['old_status'] ?? '' ) !== $from ) {
			return false;
		}
		if ( '' !== $to && 'any' !== $to && ( $args['data']['new_status'] ?? '' ) !== $to ) {
			return false;
		}

		return true;
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		$statuses = array_merge( array( 'any' => __( 'Any status', 'doublescale' ) ), FluentCartOrders::order_statuses() );

		return array(
			'from_status' => array(
				'label'   => __( 'From Status', 'doublescale' ),
				'type'    => 'select',
				'options' => $statuses,
				'default' => 'any',
			),
			'to_status'   => array(
				'label'   => __( 'To Status', 'doublescale' ),
				'type'    => 'select',
				'options' => $statuses,
				'default' => 'any',
			),
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
				'from_status' => array( 'type' => 'string' ),
				'to_status'   => array( 'type' => 'string' ),
			),
		);
	}
}
