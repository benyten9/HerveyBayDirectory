<?php
/**
 * SureCart Order Received goal — definition only in the free plugin. Runtime implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Goals\Surecart;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Automations\Abstracts\GoalPro;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\GoalsManager;

/**
 * SureCart order received goal stub.
 */
class OrderReceived extends GoalPro {

	public $name = 'Order Received';

	public $slug = 'surecart_order_received';

	public $description = 'This goal is achieved when a SureCart order is received.';

	public $source = 'surecart';

	public $group = 'order';

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
			'product_ids' => array(
				'label'   => __( 'Target Products', 'doublescale' ),
				'type'    => 'multiselect',
				'options' => array(),
				'help'    => __( 'Select products to filter this goal. Leave empty to complete for any product.', 'doublescale' ),
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
				'product_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}
}

GoalsManager::instance()->register( new OrderReceived() );
