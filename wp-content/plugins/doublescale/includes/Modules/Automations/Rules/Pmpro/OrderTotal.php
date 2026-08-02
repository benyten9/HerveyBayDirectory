<?php

/**
 * Class OrderTotal
 *
 * Rule to check the PMPro order total from trigger data.
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
 * Order Total class
 */
class OrderTotal extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order Total';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_order_total';

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
	public $type = 'number';

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
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'lower_than'   => __( 'Lower than', 'doublescale' ),
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
		$total = $automation_contact->get_data( 'total' );
		return $total ? (string) $total : '0';
	}
}

add_action(
	'init',
	function () {
		if ( defined( 'PMPRO_VERSION' ) ) {
			RulesManager::instance()->register( new OrderTotal() );
		}
	},
	99
);
