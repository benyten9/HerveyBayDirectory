<?php
/**
 * Class Add Tags
 *
 * This class is responsible for adding tags to a contact in Mailchimp
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
	public $slug = 'mailchimp_add_tags';

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
	public $description = 'This action will add tags to a contact in Mailchimp.';

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
				__( 'Mailchimp add tags action failed. List ID is required.', 'doublescale'),
				array(
					'code' => 'mailchimp_add_tags',
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
		$tags = $step->get_setting( 'tags', array() );
		if ( empty( $tags ) ) {
			doublescale_get_logger()->error(
				__( 'Mailchimp add tags action failed. Tags are required.', 'doublescale'),
				array(
					'code' => 'mailchimp_add_tags',
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
				__( 'Mailchimp add tags action failed. Mailchimp Api connection failed.', 'doublescale'),
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

		$data = array();

		foreach ( $tags as $tag ) {
			$data['tags'][] = array(
				'name'   => $tag,
				'status' => 'active',
			);
		}

		$email  = $automation_contact->contact->email;
		$result = $api->add_tags( $list, $email, $data );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Mailchimp add tags action failed. Failed to add tags.', 'doublescale'),
				array(
					'code'     => 'mailchimp_add_tags',
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
			__( 'Mailchimp add tags action completed successfully.', 'doublescale'),
			array(
				'code'     => 'mailchimp_add_tags',
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
			'list' => array(
				'label'    => __( 'List ID', 'doublescale'),
				'type'     => 'api_select',
				'endpoint' => 'mailchimp/lists',
			),
			'tags' => array(
				'type'     => 'api_select',
				'label'    => __( 'Tags', 'doublescale'),
				'endpoint' => 'mailchimp/tags',
				'multiple' => true,
			),
		);
	}
}
