<?php
/**
 * Class RemoveTags
 *
 * This class is responsible for removing tags to a contact in GetResponse
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Getresponse;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Remove Tags class
 */
class RemoveTags extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Remove Tags';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'getresponse_remove_tags';

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
	public $group = 'getresponse';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will remove tags to a contact in GetResponse';

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
		$tags    = $step->get_setting( 'tags', array() );
		$list_id = $this->merge_tags_manager->process_merge_tags( $step->get_setting( 'list_id' ), $automation_contact );

		if ( empty( $list_id ) ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Remove Tags: List ID is required.', 'doublescale'),
				array(
					'code' => 'getresponse_remove_tags',
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

		if ( empty( $tags ) ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Remove Tags: Tags are required.', 'doublescale'),
				array(
					'code' => 'getresponse_remove_tags',
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

		$getresponse = IntegrationsManager::instance()->get_integration( 'getresponse' );
		$api         = $getresponse->connect();
		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Api connection failed.', 'doublescale'),
				array(
					'code' => 'getresponse_connect',
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

		$email = $automation_contact->contact->email;
		$data  = array(
			'campaign' => array(
				'campaignId' => $list_id,
			),
		);

		$result  = $api->get_or_create_contact( $email );
		$contact = $result['data'];
		if ( empty( $contact ) ) {
			doublescale_get_logger()->error(
				__( 'GetResponse Remove Tags: Contact not found.', 'doublescale'),
				array(
					'code' => 'getresponse_remove_tags',
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
			return true;
		}

		$old_tags = array();
		if ( ! empty( $contact['tags'] ) ) {
			foreach ( $contact['tags'] as $tag ) {
				$old_tags[] = $tag['tagId'];
			}
		}

		$tags         = array_diff( $old_tags, $tags );
		$data['tags'] = array();
		foreach ( $tags as $tag ) {
			$data['tags'][] = array(
				'tagId' => $tag,
			);
		}
		$result = $api->create_or_update_contact( $email, $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'GetResponse failed to remove tags.', 'doublescale'),
				array(
					'code' => 'getresponse_remove_tags',
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

		doublescale_get_logger()->info(
			__( 'Tags removed from GetResponse.', 'doublescale'),
			array(
				'code'     => 'getresponse_remove_tags',
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

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tags'    => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
				'list_id' => array(
					'type' => 'string',
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
			'tags'    => array(
				'type'     => 'api_select',
				'label'    => __( 'Tags', 'doublescale'),
				'endpoint' => 'getresponse/tags',
				'multiple' => true,
			),
			'list_id' => array(
				'label'    => __( 'List ID', 'doublescale'),
				'type'     => 'api_select',
				'endpoint' => 'getresponse/lists',
			),
		);
	}
}
