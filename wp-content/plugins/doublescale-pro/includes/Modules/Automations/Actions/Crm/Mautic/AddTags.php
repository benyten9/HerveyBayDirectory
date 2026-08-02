<?php
/**
 * Class AddTags
 *
 * This class is responsible for adding tags to a Mautic contact
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Mautic;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Add Tags class
 */
class AddTags extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add Tags';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'mautic_add_tags';

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
	public $group = 'mautic';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add tags to a Mautic contact.';

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
		$tags = $step->get_setting( 'tags', array() );
		if ( empty( $tags ) ) {
			doublescale_get_logger()->error(
				__( 'Mautic Add Tags action is missing tags.', 'doublescale'),
				array(
					'code' => 'mautic_add_tags',
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
		$data   = array(
			'email'     => $email,
			'firstname' => $automation_contact->contact->first_name,
			'lastname'  => $automation_contact->contact->last_name,
			'tags'      => $tags,
		);
		$mautic = IntegrationsManager::instance()->get_integration( 'mautic' );
		$api    = $mautic->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Mautic Add Tags: Could not connect to Mautic.', 'doublescale'),
				array(
					'code' => 'mautic_connect',
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

		$result = $api->create_or_update_contact( $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Mautic Add Tags: Failed to add tags.', 'doublescale'),
				array(
					'code'     => 'mautic_add_tags',
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
			__( 'Mautic Add Tags: Tags added successfully.', 'doublescale'),
			array(
				'code'     => 'mautic_add_tags',
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
				'tags' => array(
					'type'  => 'array',
					'items' => array(
						'type' => array( 'string', 'number' ),
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
			'tags' => array(
				'type'     => 'api_select',
				'label'    => __( 'Tags', 'doublescale'),
				'endpoint' => 'mautic/tags',
				'multiple' => true,
			),
		);
	}
}
