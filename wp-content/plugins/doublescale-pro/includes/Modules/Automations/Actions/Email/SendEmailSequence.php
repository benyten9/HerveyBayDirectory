<?php

/**
 * Send Email Sequence Action
 *
 * Enrolls a contact into an email sequence from an automation workflow.
 * Validates the sequence exists, the contact is subscribed, prevents
 * duplicate enrollment, and delegates to EmailSequencesManager.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Email;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Pro\Modules\Campaigns\Sequences\EmailSequencesManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Campaigns\Models\CampaignModel as Email_Sequence_Model;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;

class SendEmailSequence extends Action {

	/**
	 * @var string
	 */
	public $name = 'Send Email Sequence';

	/**
	 * @var string
	 */
	public $slug = 'send_email_sequence';

	/**
	 * @var string
	 */
	public $description = 'This action will send a email sequence to the contact.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * @var string
	 */
	public $source = 'email';

	/**
	 * @var string
	 */
	public $group = 'email';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation
	 * @param AutomationStepModel    $step
	 * @param AutomationContactModel $automation_contact
	 * @return bool|array
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		if ( ! AutomationModuleStorage::is_ready( 'campaigns', Email_Sequence_Model::class ) ) {
			return false;
		}

		$email_sequence_id = $step->get_setting( 'email_sequence_id' );

		$log_context = array(
			'automation_id'     => $automation->id,
			'step_id'           => $step->id,
			'contact_id'        => $automation_contact->contact_id,
			'email_sequence_id' => $email_sequence_id,
		);

		if ( empty( $email_sequence_id ) ) {
			doublescale_get_logger()->error(
				'Email sequence ID not provided in automation action',
				$log_context
			);
			return false;
		}

		$email_sequence = Email_Sequence_Model::find( $email_sequence_id );

		if ( ! $email_sequence || $email_sequence->get_type() !== CampaignChannel::CHANNEL_EMAIL_SEQUENCE ) {
			doublescale_get_logger()->error(
				'Email sequence not found or invalid type',
				$log_context
			);
			return false;
		}

		$contact = $automation_contact->contact;

		if ( empty( $contact->email ) || ! filter_var( $contact->email, FILTER_VALIDATE_EMAIL ) ) {
			doublescale_get_logger()->info(
				'Send Email Sequence action: Contact has no valid email',
				array_merge( $log_context, array( 'code' => 'send_email_sequence_invalid_email' ) )
			);
			return array(
				'status'  => 'skipped',
				'message' => 'Contact has no valid email address',
			);
		}

		if ( $contact->email_status !== 'subscribed' ) {
			doublescale_get_logger()->info(
				'Send Email Sequence action: Contact is not subscribed',
				array_merge( $log_context, array(
					'contact_status' => $contact->email_status,
					'code'           => 'send_email_sequence_not_subscribed',
				) )
			);
			return array(
				'status'  => 'skipped',
				'message' => "Contact is not subscribed (status: {$contact->email_status})",
			);
		}

		$manager = EmailSequencesManager::instance();
		$result  = $manager->start_sequence_for_contact(
			$email_sequence_id,
			$automation_contact->contact_id
		);

		if ( is_array( $result ) && ( $result['status'] ?? '' ) === 'skipped' ) {
			doublescale_get_logger()->info(
				'Email sequence enrollment skipped via automation',
				array_merge( $log_context, array( 'reason' => $result['message'] ?? 'unknown' ) )
			);
			return $result;
		}

		if ( $result === true ) {
			doublescale_get_logger()->info(
				'Email sequence started successfully via automation',
				$log_context
			);
			return true;
		}

		doublescale_get_logger()->error(
			'Failed to start email sequence via automation',
			$log_context
		);

		return false;
	}

	/**
	 * Get fields for UI
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'internal_label'       => array(
				'label' => __( 'Internal Label', 'doublescale'),
				'type'  => 'text',
			),
			'internal_description' => array(
				'label' => __( 'Internal Description', 'doublescale'),
				'type'  => 'text',
			),
			'email_sequence_id'    => array(
				'label'   => __( 'Email Sequence', 'doublescale'),
				'type'    => 'select',
				'options' => $this->get_email_sequence_options(),
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
				'internal_label'       => array(
					'type'     => 'string',
					'required' => false,
				),
				'internal_description' => array(
					'type'     => 'string',
					'required' => false,
				),
				'email_sequence_id'    => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}

	/**
	 * @return array
	 */
	private function get_email_sequence_options() {
		if ( ! AutomationModuleStorage::is_ready( 'campaigns', Email_Sequence_Model::class ) ) {
			return array();
		}

		$email_sequences = Email_Sequence_Model::where( 'type', CampaignChannel::CHANNEL_EMAIL_SEQUENCE )->get();
		return wp_list_pluck( $email_sequences->toArray(), 'name', 'id' );
	}
}

SendEmailSequence::instance();
