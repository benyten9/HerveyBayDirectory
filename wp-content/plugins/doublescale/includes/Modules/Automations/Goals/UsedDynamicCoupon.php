<?php
/**
 * Used Dynamic Coupon goal — definition only in the free plugin. Runtime implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Goals;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Automations\Abstracts\GoalPro;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\GoalsManager;

/**
 * Used Dynamic Coupon goal stub.
 */
class UsedDynamicCoupon extends GoalPro {

	public $name = 'Used Dynamic Coupon';

	public $slug = 'used_dynamic_coupon';

	public $description = 'This goal is achieved when a contact uses a dynamic coupon.';

	public $source = 'woocommerce';

	public $group = 'coupon';

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
			'merge_tag' => array(
				'label'   => __( 'Merge Tag', 'doublescale' ),
				'type'    => 'text',
				'tooltip' => __( 'Copy the merge tag step that needs to track the coupon used, but make sure this step is ordered before the goal step.', 'doublescale' ),
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
				'merge_tag' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}

GoalsManager::instance()->register( new UsedDynamicCoupon() );
