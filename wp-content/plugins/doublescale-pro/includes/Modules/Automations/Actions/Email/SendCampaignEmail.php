<?php


/**
 * Send Campaign Email
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Email;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Pro\Utils;
use DoubleScale\Modules\Campaigns\Campaign\EmailProcessing;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;


class SendCampaignEmail extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Send Campaign Email';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'send_campaign_email';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will send a campaign email to the contact.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'email';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'email';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		if ( ! AutomationModuleStorage::is_ready( 'campaigns', CampaignModel::class ) ) {
			return false;
		}

		try {
			$campaign_id = $step->get_setting('campaign_id');
			$campaign    = CampaignModel::find($campaign_id);

			if (! $campaign) {
				doublescale_get_logger()->warning(
					'Send Campaign Email: linked campaign not found',
					array(
						'code'           => 'send_campaign_email_campaign_missing',
						'campaign_id'    => $campaign_id,
						'automation_id'  => $automation->id,
						'step_id'        => $step->id,
						'automation_contact_id' => $automation_contact->id ?? null,
					)
				);
				return false;
			}

			$contact = $automation_contact->contact;

			// Validate contact has email
			if (empty($contact->email)) {
				doublescale_get_logger()->warning(
					'Send Campaign Email: contact has no email address',
					array(
						'code'           => 'send_campaign_email_no_recipient',
						'campaign_id'    => $campaign->id,
						'contact_id'     => $contact->id ?? null,
						'automation_id'  => $automation->id,
						'step_id'        => $step->id,
					)
				);
				return false;
			}

			// Only send to subscribed contacts
			if ($contact->email_status !== 'subscribed') {
				doublescale_get_logger()->info(
					'Send Campaign Email action: Contact is not subscribed',
					array(
						'automation_id'  => $automation->id,
						'contact_id'     => $contact->id,
						'contact_status' => $contact->email_status,
						'code'           => 'send_campaign_email_not_subscribed',
					)
				);
				return array(
					'status'  => 'skipped',
					'message' => "Contact is not subscribed (status: {$contact->email_status})",
				);
			}

			// Use process_campaign_message for the actual sending
			$email_processor = EmailProcessing::instance();
			// Get template for campaign (with A/B testing support)
			$template_id = $email_processor->get_template_for_contact($campaign, $contact);
			if (! $template_id) {
				doublescale_get_logger()->warning(
					'Send Campaign Email: no template resolved for contact (A/B or campaign templates)',
					array(
						'code'          => 'send_campaign_email_no_template',
						'campaign_id'   => $campaign->id,
						'contact_id'    => $contact->id,
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
					)
				);
				return false;
			}

			// Check for existing message to prevent duplicates
			$existing_message = $this->check_existing_campaign_message($contact, $template_id);
			if ($existing_message) {
				doublescale_get_logger()->info(
					'Send Campaign Email: skipped duplicate (tracking record already exists)',
					array(
						'code'            => 'send_campaign_email_duplicate_skip',
						'campaign_id'     => $campaign->id,
						'contact_id'      => $contact->id,
						'template_id'     => $template_id,
						'existing_tracking_id' => $existing_message->id ?? null,
						'automation_id'   => $automation->id,
						'step_id'         => $step->id,
					)
				);
				return true; // Already sent, skip
			}

			// Create campaign message tracking record
			$campaign_message_data = array(
				'contact_id'  => $contact->id,
				'template_id' => $template_id,
				'mode'        => CommunicationTrackingModel::MODE_EMAIL,
				'source_type' => MessageSourceTypes::AUTOMATION,
				'source_id'   => $automation->id,
				'step_id'     => $step->id,
				'recipient'   => $contact->email,
				'status'      => TrackingStatus::PENDING,
				'hash_key'    => Utils::generate_hash_key(),
			);

			$campaign_message = CommunicationTrackingModel::create($campaign_message_data);

			// Pass automation_contact instead of contact to support automation-specific merge tags
			$email_processor->process_campaign_message($campaign, $automation_contact, $campaign_message);
			return true;
		} catch (\Exception $e) {
			doublescale_get_logger()->error(
				'Send Campaign Email action failed',
				array(
					'code'          => 'send_campaign_email_action_failed',
					'error'         => $e->getMessage(),
					'campaign_id'   => $campaign_id ?? null,
					'contact_id'    => $contact->id ?? null,
					'automation_id' => $automation->id ?? null,
					'step_id'       => $step->id ?? null,
					'trace'         => $e->getTraceAsString(),
				)
			);
			return false;
		}
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'internal_label'       => array(
				'label' => __('Internal Label', 'doublescale'),
				'type'  => 'text',
			),
			'internal_description' => array(
				'label' => __('Internal Description', 'doublescale'),
				'type'  => 'text',
			),
			'campaign_id'          => array(
				'label'   => __('Campaign', 'doublescale'),
				'type'    => 'select',
				'options' => $this->get_campaign_options(),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'        => 'object',
			'properties'  => array(
				'internal_label'       => array(
					'type'     => 'string',
					'required' => false,
				),
				'internal_description' => array(
					'type'     => 'string',
					'required' => false,
				),
			),
			'campaign_id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
	}

	/**
	 * Get options for campaign
	 *
	 * @return array
	 */
	private function get_campaign_options()
	{
		if ( ! AutomationModuleStorage::is_ready( 'campaigns', CampaignModel::class ) ) {
			return array();
		}

		$campaigns = CampaignModel::all();
		return wp_list_pluck($campaigns->toArray(), 'name', 'id');
	}

	/**
	 * Check for existing campaign message for a contact.
	 *
	 * @param CampaignModel           $campaign The campaign model.
	 * @param AutomationContactModel $contact The contact model.
	 * @return CommunicationTrackingModel|false The existing message or false if none.
	 */
	private function check_existing_campaign_message($contact, $template_id)
	{
		return CommunicationTrackingModel::where('contact_id', $contact->id)
			->where('mode', CommunicationTrackingModel::MODE_EMAIL)
			->where('template_id', $template_id)
			->first();
	}
}
