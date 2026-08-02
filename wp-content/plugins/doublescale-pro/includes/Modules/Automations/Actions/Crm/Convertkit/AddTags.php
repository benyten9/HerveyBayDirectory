<?php
/**
 * Class AddTags
 *
 * This class is responsible for adding tags to a contact in Convertkit
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
	public $slug = 'convertkit_add_tags';

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
	public $description = 'This action will add tags to a subscriber in Convertkit.';

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
				__( 'Convertkit Add Tags: Tags is empty.', 'doublescale'),
				array(
					'code' => 'convertkit_add_tags',
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

		$data = array(
			'email' => $automation_contact->contact->email,
		);

		foreach ( $tags as $tag ) {
			$result = $api->add_subscriber_tag( $tag, $data );
			if ( ! $result['success'] ) {
				doublescale_get_logger()->error(
					__( 'Failed to add tag to Convertkit.', 'doublescale'),
					array(
						'code'     => 'convertkit_add_tags',
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
					__( 'Tag added to Convertkit.', 'doublescale'),
					array(
						'code'     => 'convertkit_add_tags',
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
			'tags' => array(
				'type'     => 'api_select',
				'label'    => __( 'Tags', 'doublescale'),
				'endpoint' => 'convertkit/tags',
				'multiple' => true,
			),
		);
	}
}
