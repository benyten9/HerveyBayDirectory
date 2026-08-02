<?php

/**
 * Class SendToChannel
 *
 * This class is responsible for sending a message to a channel in Slack
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Crm\Slack;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Send To Channel class
 */
class SendToChannel extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Send To Channel';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'slack_send_to_channel';

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
	public $group = 'slack';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will send a message to a channel in Slack.';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel        $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$message = $this->merge_tags_manager->process_merge_tags( $step->get_setting( 'message', '' ), $automation_contact );
		$channel = $step->get_setting( 'channel', '' );
		if ( empty( $message ) || empty( $channel ) ) {
			doublescale_get_logger()->error(
				__( 'Slack Send To Channel action is missing required fields.', 'doublescale' ),
				array(
					'code' => 'slack_send_to_channel',
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

		$integration = IntegrationsManager::instance()->get_integration( 'slack' );
		$api         = $integration->connect();

		if ( ! $api ) {
			doublescale_get_logger()->error(
				__( 'Slack Send To Channel action failed to connect to Slack.', 'doublescale' ),
				array(
					'code' => 'slack_send_to_channel',
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

		$result = $api->post_message( $channel, $message );
		if ( ! $result['success'] ) {
			doublescale_get_logger()->error(
				__( 'Slack Send To Channel action failed to send message.', 'doublescale' ),
				array(
					'code'     => 'slack_send_to_channel',
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
			__( 'Slack Send To Channel action sent message.', 'doublescale' ),
			array(
				'code'     => 'slack_send_to_channel',
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
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
				'channel' => array(
					'type'     => 'string',
					'required' => true,
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
			'channel' => array(
				'type'     => 'api_select',
				'label'    => __( 'Channel', 'doublescale' ),
				'endpoint' => 'slack/conversations',
			),
			'message' => array(
				'type'  => 'textarea',
				'label' => __( 'Message', 'doublescale' ),
			),
		);
	}
}
