<?php

/**
 * Class OrderStatus
 *
 * Rule to check the PMPro order status from trigger data.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Pmpro;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Order Status class
 */
class OrderStatus extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order Status';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_order_status';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'pmpro';

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'select';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array(
		'pmpro_checkout_completed',
		'pmpro_order_added',
		'pmpro_order_updated',
	);

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
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
		return array(
			'success'   => __( 'Success', 'doublescale' ),
			'cancelled' => __( 'Cancelled', 'doublescale' ),
			'pending'   => __( 'Pending', 'doublescale' ),
			'refunded'  => __( 'Refunded', 'doublescale' ),
			'error'     => __( 'Error', 'doublescale' ),
			'review'    => __( 'Review', 'doublescale' ),
			'token'     => __( 'Token', 'doublescale' ),
		);
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return string
	 */
	public function get_value( $automation_contact ) {
		return $automation_contact->get_data( 'status' ) ?? '';
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'PMPRO_VERSION' ) ) {
			RulesManager::instance()->register( new OrderStatus() );
		}
	},
	99
);
