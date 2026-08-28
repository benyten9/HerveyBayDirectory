<?php
/**
 * Unsubscribe WhatsApp automation action.
 *
 * @since 1.3.0
 *
 * @package DoubleScale\Pro\Modules\Automations\Actions\Messaging
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Messaging;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

defined( 'ABSPATH' ) || exit;

/**
 * Unsubscribe WhatsApp Action
 */
class UnsubscribeWhatsapp extends Action {

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Unsubscribe WhatsApp';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'unsubscribe_whatsapp';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'Unsubscribe the contact from WhatsApp. Optionally match an incoming message keyword before unsubscribing.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'message';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'whatsapp';

	/**
	 * Process Action
	 *
	 * @param AutomationModel        $automation         Automation Model.
	 * @param AutomationStepModel    $step               Automation Step Model.
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 *
	 * @return bool|array
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact->is_subscribed_to_channel( CampaignChannel::STR_WHATSAPP ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'Contact is already unsubscribed from WhatsApp.', 'doublescale' ),
				'code'    => 'already_unsubscribed',
			);
		}

		$keyword = trim( (string) $step->get_setting( 'keyword', '' ) );
		if ( '' !== $keyword ) {
			$whatsapp_data = $automation_contact->get_data( 'whatsapp_data', array() );
			$message_body  = isset( $whatsapp_data['message_body'] ) ? (string) $whatsapp_data['message_body'] : '';

			if ( ! $this->message_matches_keyword( $message_body, $keyword ) ) {
				doublescale_get_logger()->info(
					'Unsubscribe WhatsApp action skipped - keyword mismatch',
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'keyword'       => $keyword,
						'code'          => 'unsubscribe_whatsapp_keyword_mismatch',
					)
				);

				return array(
					'status'  => 'skipped',
					'message' => __( 'Incoming message did not match the unsubscribe keyword.', 'doublescale' ),
					'code'    => 'keyword_mismatch',
				);
			}
		}

		$contact->unsubscribe_from_mode(
			CommunicationTrackingModel::MODE_WHATSAPP,
			'' !== $keyword ? 'automation_keyword' : 'automation',
			MessageSourceTypes::AUTOMATION,
			$automation->id
		);

		doublescale_get_logger()->info(
			'Contact unsubscribed from WhatsApp via automation action',
			array(
				'automation_id' => $automation->id,
				'contact_id'    => $contact->id,
				'keyword'       => $keyword,
				'code'          => 'unsubscribe_whatsapp_success',
			)
		);

		return true;
	}

	/**
	 * Normalize and compare message body to keyword (exact match).
	 *
	 * @param string $message_body Incoming message body.
	 * @param string $keyword      Configured keyword.
	 * @return bool
	 */
	private function message_matches_keyword( $message_body, $keyword ) {
		$normalized_message = strtoupper( trim( preg_replace( '/\s+/', ' ', $message_body ) ) );
		$normalized_keyword = strtoupper( trim( preg_replace( '/\s+/', ' ', $keyword ) ) );

		return $normalized_message === $normalized_keyword;
	}

	/**
	 * Get fields for UI
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'keyword' => array(
				'label'       => __( 'Unsubscribe keyword', 'doublescale' ),
				'type'        => 'text',
				'required'    => false,
				'placeholder' => 'STOP',
				'tooltip'     => __(
					'If set, the contact is unsubscribed only when the incoming WhatsApp message matches this keyword exactly (after trimming). Leave empty to always unsubscribe when this action runs.',
					'doublescale'
				),
			),
		);
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
				'keyword' => array(
					'type' => 'string',
				),
			),
		);
	}
}
