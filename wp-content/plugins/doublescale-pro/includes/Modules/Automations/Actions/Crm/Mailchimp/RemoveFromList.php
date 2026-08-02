<?php
/**
 * Class RemoveFromList
 *
 * This class is responsible for removing list from a contact in Mailchimp
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Mailchimp;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Remove From List class
 */
class RemoveFromList extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove From List';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'mailchimp_remove_from_list';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'send_data';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'mailchimp';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove list from a contact in Mailchimp.';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$list = $step->get_setting( 'list', '' );
		if ( empty( $list ) ) {
			doublescale_get_logger()->error(
				__( 'Mailchimp Remove From List: List ID is required.', 'doublescale'),
				array(
					'code' => 'mailchimp_remove_from_list',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
				)
			);
			return false;
		}

		$mailchimp = IntegrationsManager::instance()->get_integration( 'mailchimp' );
		$api       = $mailchimp->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Mailchimp Remove From List: Api connection failed.', 'doublescale'),
				array(
					'code' => 'mailchimp_connect',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
				)
			);
			return false;
		}

		$email  = $automation_contact->contact->email;
		$result = $api->remove_subscriber( $list, $email );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Mailchimp Remove From List: Failed to remove subscriber.', 'doublescale'),
				array(
					'code'     => 'mailchimp_remove_subscriber',
					'data'     => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
					'response' => $result,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'Mailchimp Remove From List: Subscriber removed successfully.', 'doublescale'),
			array(
				'code'     => 'mailchimp_remove_subscriber',
				'response' => $result,
			)
		);

		return true;
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
				'list' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'list' => array(
				'label'    => __( 'List ID', 'doublescale'),
				'type'     => 'api_select',
				'endpoint' => 'mailchimp/lists',
			),
		);
	}
}
