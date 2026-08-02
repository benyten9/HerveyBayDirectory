<?php
/**
 * Remove Lists Action
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ListModel;

/**
 * Remove Lists Action
 */
class RemoveLists extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove from Lists';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'remove_lists';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove the contact from a list.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel        $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$lists_ids = $step->get_setting( 'lists' );
		$lists     = ListModel::find( $lists_ids );

		if ( ! empty( $lists ) ) {
			$lists_ids = wp_list_pluck( $lists->toArray(), 'id' );
			$contact   = $automation_contact->contact;
			$contact->lists()->detach( $lists_ids );
		}

		return true;
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
			'lists' => array(
				'label'    => __( 'Lists', 'doublescale' ),
				'type'     => 'lists',
				'multiple' => true,
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
				'lists' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'integer',
					),
					'required' => true,
				),
			),
		);
	}
}

RemoveLists::instance();
