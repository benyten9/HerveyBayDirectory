<?php
/**
 * Change Status Action
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

/**
 * Change Status Action
 */
class ChangeStatus extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Change Status';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'change_contact_status';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will change the status of the contact.';

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
		$new_status = $step->get_setting( 'new_status', 'unverified' );
		$contact    = $automation_contact->contact;
		if ( $contact->email_status === $new_status ) {
			return true;
		}
		$contact->email_status = $new_status;
		$contact->save();

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
			'new_status' => array(
				'type'     => 'select',
				'label'    => __( 'New Status', 'doublescale' ),
				'required' => true,
				'options'  => array(
					'unverified'   => __( 'Unverified', 'doublescale' ),
					'subscribed'   => __( 'Subscribed', 'doublescale' ),
					'unsubscribed' => __( 'Unsubscribed', 'doublescale' ),
					'bounced'      => __( 'Bounced', 'doublescale' ),
				),
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
				'new_status' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}

ChangeStatus::instance();
