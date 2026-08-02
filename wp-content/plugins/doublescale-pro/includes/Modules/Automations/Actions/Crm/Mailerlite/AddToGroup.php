<?php
/**
 * Class AddToGroup
 *
 * This class is responsible for adding a contact to a group in MailerLite
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
 * Add To Group class
 */
class AddToGroup extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add To Group';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'mailerlite_add_to_group';

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
	public $description = 'This action will add a contact to a group in MailerLite';

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
				__( 'MailerLite Add To Group: Group ID is required.', 'doublescale'),
				array(
					'code' => 'mailerlite_add_to_group',
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
				__( 'MailerLite Add To Group: Api connection failed.', 'doublescale'),
				array(
					'code' => 'mailerlite_connect',
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

		$email      = $automation_contact->contact->email;
		$subscriber = array(
			'email'  => $email,
			'groups' => array(
				$group_id,
			),
		);

		$result = $api->add_subscriber( $subscriber );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to add contact to MailerLite group.', 'doublescale'),
				array(
					'code'     => 'mailerlite_add_to_group',
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
			__( 'Contact added to MailerLite group.', 'doublescale'),
			array(
				'code'     => 'mailerlite_add_to_group',
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
