<?php
/**
 * Class AddSubscriber
 *
 * This class is responsible for adding a subscriber to MailerLite
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
 * Add Subscriber class
 */
class AddSubscriber extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add Subscriber';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'mailerlite_add_subscriber';

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
	public $description = 'This action will add a subscriber to MailerLite';


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
		$mapped_fields = $step->get_setting(
			'mapped_fields',
			array(
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
			)
		);
		$email         = $this->merge_tags_manager->process_merge_tags( $mapped_fields['email'], $automation_contact );
		$first_name    = $this->merge_tags_manager->process_merge_tags( $mapped_fields['first_name'], $automation_contact );
		$last_name     = $this->merge_tags_manager->process_merge_tags( $mapped_fields['last_name'], $automation_contact );
		$data          = array(
			'email'  => $email,
			'fields' => array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
			),
		);

		$mailerlite = IntegrationsManager::instance()->get_integration( 'mailerlite' );
		$api        = $mailerlite->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'MailerLite Add Subscriber: Api is not connected.', 'doublescale'),
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

		$result = $api->add_subscriber( $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'MailerLite Add Subscriber: Failed to add subscriber.', 'doublescale'),
				array(
					'code'     => 'mailerlite_add_subscriber',
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
			__( 'MailerLite Add Subscriber: Subscriber added successfully.', 'doublescale'),
			array(
				'code'     => 'mailerlite_add_subscriber',
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
				'mapped_fields' => array(
					'type'       => 'object',
					'properties' => array(
						'email'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'first_name' => array(
							'type'     => 'string',
							'required' => false,
						),
						'last_name'  => array(
							'type'     => 'string',
							'required' => false,
						),
					),
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
			'mapped_fields' => array(
				'label'  => __( 'Mapped Fields', 'doublescale'),
				'type'   => 'mapped_fields',
				'fields' => array(
					'email'      => array(
						'label' => __( 'Email', 'doublescale'),
					),
					'first_name' => array(
						'label' => __( 'First Name', 'doublescale'),
					),
					'last_name'  => array(
						'label' => __( 'Last Name', 'doublescale'),
					),
				),
			),
		);
	}
}
