<?php
/**
 * Class AddContact
 *
 * This class is responsible for adding a contact to ActiveCampaign
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Activecampaign;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Add Contact class
 */
class AddContact extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add Contact';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'activecampaign_add_contact';

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
	public $group = 'activecampaign';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a contact to ActiveCampaign.';

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	public $is_integration = true;

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

		$email      = $this->merge_tags_manager->process_merge_tags( $mapped_fields['email'], $automation_contact );
		$first_name = $this->merge_tags_manager->process_merge_tags( $mapped_fields['first_name'], $automation_contact );
		$last_name  = $this->merge_tags_manager->process_merge_tags( $mapped_fields['last_name'], $automation_contact );

		if ( empty( $email ) ) {
			doublescale_get_logger()->error(
				__( 'Failed to add contact to ActiveCampaign. Email is required.', 'doublescale'),
				array(
					'code' => 'activecampaign_add_contact',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
						'data'       => array(
							'email'      => $email,
							'first_name' => $first_name,
							'last_name'  => $last_name,
						),
					),
				)
			);
			return false;
		}

		$data = array(
			'contact' => array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			),
		);

		$activecampaign = IntegrationsManager::instance()->get_integration( 'activecampaign' );
		$api            = $activecampaign->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Failed to connect to ActiveCampaign.', 'doublescale'),
				array(
					'code' => 'activecampaign_connect',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
						'data'       => array(
							'email'      => $email,
							'first_name' => $first_name,
							'last_name'  => $last_name,
						),
					),
				)
			);
			return false;
		}

		$result = $api->create_or_update( $data );
		if ( $result['success'] ) {
			doublescale_get_logger()->info(
				__( 'Contact added to ActiveCampaign.', 'doublescale'),
				array(
					'code'     => 'activecampaign_add_contact',
					'response' => $result,
					'data'     => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
						'data'       => array(
							'email'      => $email,
							'first_name' => $first_name,
							'last_name'  => $last_name,
						),
					),
				)
			);
			return true;
		}

		if ( 422 === $result['code'] ) {
			doublescale_get_logger()->error(
				__( 'Contact already exists.', 'doublescale'),
				array(
					'code'     => 'activecampaign_add_contact',
					'response' => $result,
					'data'     => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
						'data'       => array(
							'email'      => $email,
							'first_name' => $first_name,
							'last_name'  => $last_name,
						),
					),
				)
			);
			return true;
		}

		doublescale_get_logger()->error(
			__( 'Failed to add contact to ActiveCampaign.', 'doublescale'),
			array(
				'code'     => 'activecampaign_add_contact',
				'response' => $result,
				'data'     => array(
					'automation' => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'       => array(
						'id'   => $step->id,
						'type' => $step->type,
					),
					'data'       => array(
						'email'      => $email,
						'first_name' => $first_name,
						'last_name'  => $last_name,
					),
				),
			)
		);
		return false;
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
