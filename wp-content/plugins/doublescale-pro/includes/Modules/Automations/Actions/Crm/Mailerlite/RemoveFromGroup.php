<?php
/**
 * Class MailerLite Remove From Group
 *
 * This class is responsible for removing a contact from a group in MailerLite
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Mailerlite;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Remove From Group class
 */
class RemoveFromGroup extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove From Group';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'mailerlite_remove_from_group';

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
	public $group = 'mailerlite';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove a contact from a group in MailerLite';

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
		$group_id = $step->get_setting( 'group_id' );

		if ( empty( $group_id ) ) {
			doublescale_get_logger()->error(
				__( 'MailerLite Remove From Group: Group ID is required', 'doublescale'),
				array(
					'code' => 'mailerlite_remove_from_group',
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

		$mailerlite = IntegrationsManager::instance()->get_integration( 'mailerlite' );
		$api        = $mailerlite->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'MailerLite Remove From Group: Api connection failed', 'doublescale'),
				array(
					'code' => 'mailerlite_remove_from_group',
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
		$result = $api->get_subscriber( $email );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'MailerLite Remove From Group: Subscriber not found', 'doublescale'),
				array(
					'code'     => 'mailerlite_remove_from_group',
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
			return true;
		}

		$subscriber    = $result['data'];
		$subscriber_id = $subscriber['id'];
		$result        = $api->delete_subscriber_from_group( $group_id, $subscriber_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'MailerLite Remove From Group: Failed to remove subscriber from group', 'doublescale'),
				array(
					'code'     => 'mailerlite_remove_from_group',
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
			__( 'MailerLite Remove From Group: Subscriber removed from group', 'doublescale'),
			array(
				'code'     => 'mailerlite_remove_from_group',
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
				'group_id' => array(
					'description' => __( 'Group ID', 'doublescale'),
					'type'        => array( 'string', 'integer' ),
					'required'    => true,
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
			'group_id' => array(
				'label'    => __( 'Group ID', 'doublescale'),
				'type'     => 'api_select',
				'endpoint' => 'mailerlite/groups',
			),
		);
	}
}
