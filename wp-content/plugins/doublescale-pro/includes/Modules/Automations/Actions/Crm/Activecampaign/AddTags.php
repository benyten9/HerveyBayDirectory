<?php
/**
 * Class AddTags
 *
 * This class is responsible for adding tags to a contact in ActiveCampaign
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
	public $slug = 'activecampaign_add_tags';

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
	public $description = 'This action will add tags to a contact in ActiveCampaign.';

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
		$tags = $step->get_setting( 'tags', array() );
		if ( empty( $tags ) ) {
			doublescale_get_logger()->error(
				__( 'ActiveCampaign Add Tags: Tags is empty.', 'doublescale'),
				array(
					'code' => 'activecampaign_add_tags',
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
					),
				)
			);
			return false;
		}

		$result = $api->get_contact( $automation_contact->contact->email );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Failed to get contact from ActiveCampaign.', 'doublescale'),
				array(
					'code'     => 'activecampaign_get_contact',
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

		$contact_id = $result['data']['contacts'][0]['id'] ?? null;
		if ( ! $contact_id ) {
			doublescale_get_logger()->error(
				__( 'Failed to get contact ID from ActiveCampaign.', 'doublescale'),
				array(
					'code'     => 'activecampaign_get_contact_id',
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

		foreach ( $tags as $tag ) {
			$data = array(
				'contactTag' => array(
					'contact' => $contact_id,
					'tag'     => $tag,
				),
			);

			$result = $api->add_contact_tag( $data );
			if ( ! $result['success'] ) {
				doublescale_get_logger()->error(
					__( 'Failed to add tag to contact in ActiveCampaign.', 'doublescale'),
					array(
						'code'     => 'activecampaign_add_tag',
						'data'     => array(
							'automation' => array(
								'id'   => $automation->id,
								'name' => $automation->name,
							),
							'step'       => array(
								'id'   => $step->id,
								'type' => $step->type,
							),
							'tag'        => $tag,
						),
						'response' => $result,
					)
				);
				continue;
			} else {
				doublescale_get_logger()->info(
					__( 'Tag added to contact in ActiveCampaign.', 'doublescale'),
					array(
						'code'     => 'activecampaign_add_tag',
						'data'     => array(
							'automation' => array(
								'id'   => $automation->id,
								'name' => $automation->name,
							),
							'step'       => array(
								'id'   => $step->id,
								'type' => $step->type,
							),
							'tag'        => $tag,
						),
						'response' => $result,
					)
				);
			}
		}

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
						'type' => 'string',
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
			'tag' => array(
				'type'     => 'api_select',
				'label'    => __( 'Tags', 'doublescale'),
				'endpoint' => 'activecampaign/tags',
				'multiple' => true,
			),
		);
	}
}
