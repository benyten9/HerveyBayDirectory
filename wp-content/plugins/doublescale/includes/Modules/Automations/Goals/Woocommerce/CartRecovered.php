<?php
/**
 * WooCommerce Cart Recovered goal — definition only in the free plugin. Runtime implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Goals\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\GoalPro;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\GoalsManager;

/**
 * WooCommerce cart recovered goal stub.
 */
class CartRecovered extends GoalPro {

	public $name = 'Cart Recovered';

	public $slug = 'wc_cart_recovered_goal';

	public $description = 'This goal is achieved when a contact recovers an abandoned cart by completing their purchase.';

	public $source = 'woocommerce';

	public $group = 'cart';

	/**
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $data               Payload.
	 * @return bool
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		return false;
	}

	/**
	 * @return array
	 */
	public function get_fields() {
		return array(
			'match_specific_cart' => array(
				'label'   => __( 'Match Specific Cart', 'doublescale' ),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'When enabled, the goal only completes if the specific cart that triggered this automation is recovered. Disable to complete when any cart is recovered.', 'doublescale' ),
			),
			'min_cart_value'      => array(
				'label'       => __( 'Minimum Cart Value', 'doublescale' ),
				'type'        => 'number',
				'placeholder' => '0.00',
				'help'        => __( 'Optional: Only complete goal if recovered cart value is at least this amount. Leave at 0 for any cart value.', 'doublescale' ),
			),
		);
	}

	/**
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'match_specific_cart' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'min_cart_value'      => array(
					'type'    => 'number',
					'minimum' => 0,
				),
			),
		);
	}
}

GoalsManager::instance()->register( new CartRecovered() );
