<?php
/**
 * Class AddToSequence
 *
 * This class is responsible for adding a contact to a sequence in Convertkit
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Convertkit;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Add To Sequence class
 */
class AddToSequence extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add To Sequence';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'convertkit_add_to_sequence';

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
	public $group = 'convertkit';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a contact to a sequence in Convertkit';

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
		$sequence_id = $step->get_setting( 'sequence_id' );
		if ( empty( $sequence_id ) ) {
			doublescale_get_logger()->error(
				__( 'Convertkit Sequence ID is required.', 'doublescale'),
				array(
					'code' => 'convertkit_add_sequence',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
					),
				)
			);
			return false;
		}

		$convertkit = IntegrationsManager::instance()->get_integration( 'convertkit' );
		$api        = $convertkit->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Convertkit Add Tags: Api connection failed.', 'doublescale'),
				array(
					'code' => 'convertkit_connect',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
					),
				)
			);
			return false;
		}

		$email  = $automation_contact->contact->email;
		$result = $api->add_subscriber_to_sequence( $email, $sequence_id );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to add contact to Convertkit Sequence.', 'doublescale'),
				array(
					'code'     => 'convertkit_add_sequence',
					'data'     => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id'   => $step->id,
							'type' => $step->type,
						),
					),
					'response' => $result,
				)
			);
			return false;
		}

		doublescale_get_logger()->info(
			__( 'Contact added to Convertkit Sequence.', 'doublescale'),
			array(
				'code'     => 'convertkit_add_sequence',
				'data'     => array(
					'automation' => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'       => array(
						'id'   => $step->id,
						'type' => $step->type,
					),
				),
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
				'sequence_id' => array(
					'type' => array( 'string', 'integer' ),
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
			'sequence_id' => array(
				'type'     => 'api_select',
				'label'    => __( 'Sequence', 'doublescale'),
				'endpoint' => 'convertkit/sequence',
			),
		);
	}
}
