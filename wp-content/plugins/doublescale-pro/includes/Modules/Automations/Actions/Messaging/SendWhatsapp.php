<?php

/**
 * Send WhatsApp Action
 * Uses pre-approved WhatsApp business templates (no auto-generation)
 * Consistent with WhatsApp campaigns and individual messaging
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Messaging;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Validators\PhoneValidator;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Campaigns\Campaign\WhatsappProcessing;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Campaigns\Services\WhatsappConversationWindow;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractSendMessage;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;

/**
 * Send WhatsApp Action
 */
class SendWhatsapp extends AbstractSendMessage
{

	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Send WhatsApp';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'send_whatsapp';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'whatsapp';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'message';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'Send a WhatsApp message using a pre-approved Meta template, or a free-form auto-reply when the trigger is WhatsApp Message Received.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Get channel type
	 *
	 * @return string
	 */
	protected function get_channel_type()
	{
		return \DoubleScale\Core\Constants\CampaignChannel::STR_WHATSAPP;
	}

	/**
	 * Get tracking mode
	 *
	 * @return int
	 */
	protected function get_tracking_mode()
	{
		return CommunicationTrackingModel::MODE_WHATSAPP;
	}

	/**
	 * Get recipient from contact
	 *
	 * Requires whatsapp_phone field for WhatsApp messaging.
	 * Contact must have a WhatsApp phone number set to receive WhatsApp messages.
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return string|null
	 */
	protected function get_recipient(ContactModel $contact)
	{
		// WhatsApp requires the whatsapp_phone field - no fallback to phone
		return $contact->whatsapp_phone;
	}

	/**
	 * Validate recipient
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return array|null
	 */
	protected function validate_recipient(ContactModel $contact)
	{
		$phone = $contact->whatsapp_phone;

		if ( empty( $phone ) ) {
			return array(
				'status'  => 'skipped',
				'message' => 'Contact has no WhatsApp phone number. Add a WhatsApp phone to the contact first.',
			);
		}

		// Validate phone number format using centralized utility
		if ( ! PhoneValidator::is_valid( $phone ) ) {
			return array(
				'status'  => 'skipped',
				'message' => 'Invalid WhatsApp phone number format. Use E.164 format: +1234567890',
			);
		}

		return null;
	}

	/**
	 * Get processing instance
	 *
	 * @return WhatsappProcessing
	 */
	protected function get_processing_instance()
	{
		return WhatsappProcessing::instance();
	}

	/**
	 * Get channel name for logging
	 *
	 * @return string
	 */
	protected function get_channel_name()
	{
		return 'WhatsApp';
	}

	/**
	 * Process Action - Override to handle whatsapp_template field format
	 *
	 * WhatsApp uses a different template selection format than Email/Sms:
	 * - Email/Sms: template_id (integer) or template_ids (array) - auto-generated templates
	 * - WhatsApp: whatsapp_template.template_sid (string) - user-selected Meta templates
	 *
	 * This override handles the template lookup before delegating to the processing instance.
	 *
	 * @param AutomationModel         $automation         Automation Model.
	 * @param AutomationStepModel    $step               Automation Step Model.
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 *
	 * @return bool|array True on success, array with status on failure.
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		try {
			$contact      = $automation_contact->contact;
			$channel_name = $this->get_channel_name();
			$channel_type = $this->get_channel_type();

			// Check if contact is subscribed to WhatsApp
			if ( ! $contact->is_subscribed_to_channel( $channel_type ) ) {
				doublescale_get_logger()->info(
					"Skipping {$channel_name} automation action - contact unsubscribed from {$channel_type}",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'code'          => "send_{$channel_type}_unsubscribed",
					)
				);
				return array(
					'success' => false,
					'message' => sprintf( __( 'Contact unsubscribed from %s', 'doublescale'), $channel_name ),
					'code'    => 'contact_unsubscribed',
				);
			}

			// Validate recipient (Whatsapp phone)
			$recipient_error = $this->validate_recipient( $contact );
			if ( $recipient_error ) {
				doublescale_get_logger()->info(
					"Send {$channel_name} action: {$recipient_error['message']}",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'code'          => "send_{$channel_type}_no_recipient",
					)
				);
				return $recipient_error;
			}

			$whatsapp_template = $step->get_setting( 'whatsapp_template', array() );
			$message_type      = $this->get_message_type( $whatsapp_template );

			if ( 'text' === $message_type ) {
				return $this->process_freeform_message( $automation, $step, $automation_contact, $contact, $whatsapp_template );
			}

			// Get template - WhatsApp uses whatsapp_template.template_sid format
			$template = $this->resolve_template_from_step( $step );

			if ( ! $template ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Template not found",
					array(
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
						'contact_id'    => $contact->id,
						'code'          => "send_{$channel_type}_template_not_found",
					)
				);
				throw new \Exception( __( 'Whatsapp template not found for automation action.', 'doublescale') );
			}

			// Create tracking record
			$tracking = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => $template->id,
					'mode'        => $this->get_tracking_mode(),
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => MessageSourceTypes::AUTOMATION,
					'source_id'   => $automation->id,
					'step_id'     => $step->id,
					'recipient'   => $this->get_recipient( $contact ),
					'hash_key'    => \DoubleScale\Pro\Utils::generate_hash_key(),
					'status'      => TrackingStatus::PENDING,
				)
			);

			// Prepare message data (stores template variables in tracking meta)
			$this->prepare_message_data( $step, $contact, $tracking );

			// Create dummy campaign for process_campaign_message() to work
			// This allows us to reuse 100% of existing campaign infrastructure
			$dummy_campaign         = new \DoubleScale\Modules\Campaigns\Models\CampaignModel(
				array(
					'id'       => $automation->id,
					'name'     => 'Automation: ' . $automation->name,
					'type'     => $channel_type,
					'settings' => array(),
				)
			);
			$dummy_campaign->exists = true; // Mark as existing to prevent save attempts

			// Process through WhatsappProcessing (reuses campaign infrastructure)
			$processing = $this->get_processing_instance();
			$processing->process_campaign_message( $dummy_campaign, $automation_contact, $tracking );

			// Refresh tracking to get updated status
			$tracking->refresh();

			// Check if message was sent successfully
			if ( in_array( $tracking->status, array( TrackingStatus::SENT, TrackingStatus::DELIVERED, TrackingStatus::READ ), true ) ) {
				doublescale_get_logger()->info(
					"Send {$channel_name} action: Message sent successfully",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'tracking_id'   => $tracking->id,
						'status'        => $tracking->status,
						'code'          => "send_{$channel_type}_success",
					)
				);
				return true;
			}

			// Check for failure
			if ( $tracking->status === TrackingStatus::FAILED ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Message failed to send",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'tracking_id'   => $tracking->id,
						'code'          => "send_{$channel_type}_failed",
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Whatsapp message failed to send', 'doublescale'),
					'code'    => 'send_failed',
				);
			}

			// Message is pending (normal for async sending)
			return true;

		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				"Send {$channel_name} action: Exception occurred",
				array(
					'automation_id' => $automation->id,
					'contact_id'    => $automation_contact->contact_id,
					'error'         => $e->getMessage(),
					'code'          => "send_{$channel_type}_exception",
				)
			);
			throw $e;
		}
	}

	/**
	 * Resolve message type from whatsapp_template settings.
	 *
	 * @param array $whatsapp_template Step whatsapp_template setting.
	 * @return string template|text
	 */
	private function get_message_type( array $whatsapp_template ): string {
		$message_type = isset( $whatsapp_template['message_type'] ) ? (string) $whatsapp_template['message_type'] : 'template';
		return 'text' === $message_type ? 'text' : 'template';
	}

	/**
	 * Send a free-form session message (24h window required).
	 *
	 * @param AutomationModel         $automation         Automation.
	 * @param AutomationStepModel    $step               Step.
	 * @param AutomationContactModel $automation_contact Enrollment.
	 * @param ContactModel           $contact            Contact.
	 * @param array                  $whatsapp_template  Step settings.
	 * @return bool|array
	 */
	private function process_freeform_message(
		AutomationModel $automation,
		AutomationStepModel $step,
		AutomationContactModel $automation_contact,
		ContactModel $contact,
		array $whatsapp_template
	) {
		$channel_name = $this->get_channel_name();
		$channel_type = $this->get_channel_type();

		if ( 'whatsapp_received' !== $automation->trigger ) {
			doublescale_get_logger()->info(
				"Send {$channel_name} action: free-form message not allowed for this trigger",
				array(
					'automation_id' => $automation->id,
					'trigger'       => $automation->trigger,
					'contact_id'    => $contact->id,
					'code'          => 'send_whatsapp_freeform_not_allowed',
				)
			);

			return array(
				'success' => false,
				'message' => __( 'Free-form WhatsApp messages are only available when the trigger is WhatsApp Message Received.', 'doublescale'),
				'code'    => 'freeform_not_allowed',
			);
		}

		$body = isset( $whatsapp_template['body'] ) ? trim( (string) $whatsapp_template['body'] ) : '';
		if ( '' === $body ) {
			throw new \Exception( __( 'WhatsApp message body is required.', 'doublescale') );
		}

		$window = WhatsappConversationWindow::check( $contact->id );
		if ( ! $window['active'] ) {
			doublescale_get_logger()->info(
				"Send {$channel_name} action: 24-hour session window closed",
				array(
					'automation_id' => $automation->id,
					'contact_id'    => $contact->id,
					'window'        => $window,
					'code'          => 'send_whatsapp_window_closed',
				)
			);

			return array(
				'status'  => 'skipped',
				'message' => __( '24-hour WhatsApp session window has closed. Free-form messages cannot be sent.', 'doublescale'),
				'code'    => 'window_closed',
			);
		}

		$processed_body = MergeTagsManager::instance()->process_merge_tags( $body, $automation_contact );
		$recipient      = $this->get_recipient( $contact );

		$tracking = CommunicationTrackingModel::create(
			array(
				'contact_id'  => $contact->id,
				'mode'        => $this->get_tracking_mode(),
				'direction'   => MessageDirection::OUTBOUND,
				'source_type' => MessageSourceTypes::AUTOMATION,
				'source_id'   => $automation->id,
				'step_id'     => $step->id,
				'recipient'   => $recipient,
				'hash_key'    => \DoubleScale\Pro\Utils::generate_hash_key(),
				'status'      => TrackingStatus::PENDING,
			)
		);

		CommunicationTrackingMetaModel::create(
			array(
				'communication_tracking_id' => $tracking->id,
				'meta_key'                  => 'message_body',
				'meta_value'                => $processed_body,
			)
		);

		$provider = MessageProviderRegistry::instance()->get_provider( $channel_type );
		if ( ! $provider ) {
			$tracking->update( array( 'status' => TrackingStatus::FAILED ) );
			throw new \Exception( __( 'WhatsApp provider not configured.', 'doublescale') );
		}

		$api_data = array(
			'To'                 => $recipient,
			'Body'               => $processed_body,
			'is_session_message' => true,
		);

		$webhook_url = $provider->get_webhook_url( CampaignChannel::STR_WHATSAPP );
		if ( $webhook_url ) {
			$api_data['StatusCallback'] = $webhook_url;
		}

		$result = $provider->send_message( CampaignChannel::STR_WHATSAPP, $api_data, $contact );

		if ( ! isset( $result['success'] ) || ! $result['success'] ) {
			$tracking->update( array( 'status' => TrackingStatus::FAILED ) );

			doublescale_get_logger()->error(
				"Send {$channel_name} action: Session message failed to send",
				array(
					'automation_id' => $automation->id,
					'contact_id'    => $contact->id,
					'tracking_id'   => $tracking->id,
					'error'         => $result['error'] ?? 'Unknown error',
					'code'          => 'send_whatsapp_failed',
				)
			);

			return array(
				'success' => false,
				'message' => $result['error'] ?? __( 'WhatsApp message failed to send', 'doublescale'),
				'code'    => 'send_failed',
			);
		}

		$tracking->update(
			array(
				'status'      => TrackingStatus::SENT,
				'sent_at'     => current_time( 'mysql', true ),
				'external_id' => $result['message_id'] ?? null,
			)
		);

		doublescale_get_logger()->info(
			"Send {$channel_name} action: Session message sent successfully",
			array(
				'automation_id' => $automation->id,
				'contact_id'    => $contact->id,
				'tracking_id'   => $tracking->id,
				'code'          => 'send_whatsapp_success',
			)
		);

		return true;
	}

	/**
	 * Resolve template from step settings
	 *
	 * Handles both new format (whatsapp_template.template_sid) and legacy format (template_id).
	 *
	 * @param AutomationStepModel $step Automation Step Model.
	 * @return TemplateModel|null
	 */
	private function resolve_template_from_step( AutomationStepModel $step ) {
		// Try new whatsapp_template format first
		$whatsapp_template = $step->get_setting( 'whatsapp_template', array() );

		if ( ! empty( $whatsapp_template ) && is_array( $whatsapp_template ) ) {
			$template_sid = isset( $whatsapp_template['template_sid'] ) ? $whatsapp_template['template_sid'] : null;

			if ( ! empty( $template_sid ) ) {
				return $this->find_or_create_template_by_sid( $template_sid );
			}
		}

		// Fall back to legacy template_id format
		$template_id = $step->get_setting( 'template_id' );
		if ( ! empty( $template_id ) ) {
			return TemplateModel::find( $template_id );
		}

		return null;
	}

	/**
	 * Get fields for UI
	 * WhatsApp requires pre-approved business templates (consistent with campaigns/individual messaging)
	 * Fetches templates directly from Meta Api like individual messaging does.
	 *
	 * @return array
	 */
	public function get_fields()
	{
		// Fetch templates directly from Meta Api (same as individual messaging)
		// This ensures we always show the latest approved templates
		try {
			$fetcher   = new \DoubleScale\Modules\Campaigns\Services\MetaTemplateFetcher();
			$templates = $fetcher->fetch_approved_templates();
		} catch ( \Exception $e ) {
			// If Meta WhatsApp is not configured or fetch fails, show error
			return array(
				'no_templates_notice' => array(
					'label' => __( 'Whatsapp Not Configured', 'doublescale'),
					'type'  => 'label',
					'value' => $e->getMessage(),
				),
			);
		}

		// Handle empty template list
		if ( empty( $templates ) ) {
			return array(
				'no_templates_notice' => array(
					'label' => __( 'No WhatsApp Templates Found', 'doublescale'),
					'type'  => 'label',
					'value' => __( 'No approved WhatsApp templates found in your Meta Business account. Create templates in Meta Business Suite first.', 'doublescale'),
				),
			);
		}

		// Build template options with additional data for variable display
		// Use 'sid' (external_id like "hello_world:en_US") as key since templates may not be in DB yet
		$template_options = array();
		$template_data    = array();

		foreach ( $templates as $template ) {
			$sid = $template['sid']; // e.g., "hello_world:en_US"

			$template_options[ $sid ] = $template['name'];

			// Include template details for frontend to display variables
			$template_data[ $sid ] = array(
				'sid'       => $sid,
				'name'      => $template['name'],
				'body'      => $template['body'] ?? '',
				'variables' => $template['variables'] ?? array(),
				'language'  => $template['settings']['language'] ?? 'en',
				'category'  => $template['settings']['category'] ?? 'UTILITY',
			);
		}

		return array(
			'whatsapp_template' => array(
				'label'       => __( 'Whatsapp Template', 'doublescale'),
				'type'        => 'whatsapp_template',
				'required'    => true,
				'options'     => $template_options,
				'settings'    => array(
					'templateData' => $template_data,
					'freeform'     => array(
						'trigger' => 'whatsapp_received',
					),
				),
				'description' => __( 'Select a pre-approved WhatsApp business template, or send a free-form auto-reply when the trigger is WhatsApp Message Received.', 'doublescale'),
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
			'type'       => 'object',
			'properties' => array(
				// New combined field structure (uses template_sid string)
				'whatsapp_template' => array(
					'type'       => 'object',
					'required'   => false,
					'properties' => array(
						'message_type' => array(
							'type' => 'string',
						),
						'body' => array(
							'type' => 'string',
						),
						'template_sid'       => array(
							'type' => 'string', // External ID like "hello_world:en_US"
						),
						'template_variables' => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type' => 'string',
							),
						),
					),
				),
				// Legacy fields for backward compatibility (uses database ID)
				'template_id' => array(
					'type'     => 'integer',
					'required' => false,
				),
				'template_variables' => array(
					'type'                 => 'object',
					'required'             => false,
					'additionalProperties' => array(
						'type' => 'string',
					),
				),
			),
		);
	}

	/**
	 * Prepare channel-specific message data for sending
	 *
	 * Stores template variables in tracking meta for WhatsappProcessing to retrieve
	 * during message preparation. Template resolution is handled by process_action().
	 *
	 * Supports:
	 * - New combined field (whatsapp_template with template_variables)
	 * - Legacy separate fields (template_variables directly)
	 *
	 * @param \DoubleScale\Modules\Automations\Models\AutomationStepModel        $step     Automation Step Model.
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel                $contact  Contact Model.
	 * @param \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel $tracking Communication Tracking Model.
	 * @return void
	 */
	protected function prepare_message_data(AutomationStepModel $step, ContactModel $contact, CommunicationTrackingModel $tracking)
	{
		// Get template variables from step settings
		$template_variables = array();

		// Try new whatsapp_template format first
		$whatsapp_template = $step->get_setting( 'whatsapp_template', array() );
		if ( ! empty( $whatsapp_template ) && is_array( $whatsapp_template ) ) {
			$template_variables = isset( $whatsapp_template['template_variables'] ) ? $whatsapp_template['template_variables'] : array();
		}

		// Fall back to legacy template_variables field
		if ( empty( $template_variables ) ) {
			$template_variables = $step->get_setting( 'template_variables', array() );
		}

		// Ensure template_variables is an array (handle legacy JSON string data)
		if ( is_string( $template_variables ) ) {
			$parsed = json_decode( $template_variables, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
				$template_variables = $parsed;
			} else {
				$template_variables = array();
			}
		}

		// Store template variables in tracking meta for WhatsappProcessing to pick up
		// WhatsappProcessing will merge these with template's default variables
		if ( ! empty( $template_variables ) && is_array( $template_variables ) ) {
			\DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::create(
				array(
					'communication_tracking_id' => $tracking->id,
					'meta_key'                  => 'automation_template_variables',
					'meta_value'                => $template_variables,
				)
			);
		}
	}

	/**
	 * Find template by SID in database, or fetch from Meta and save it
	 *
	 * @param string $template_sid External ID like "hello_world:en_US".
	 * @return \DoubleScale\Modules\Campaigns\Models\TemplateModel|null
	 */
	private function find_or_create_template_by_sid( string $template_sid )
	{
		if ( ! AutomationModuleStorage::is_ready( 'campaigns', TemplateModel::class ) ) {
			return null;
		}

		// First, try to find in database by external_id
		$templates = \DoubleScale\Modules\Campaigns\Models\TemplateModel::where( 'category', 'whatsapp_business' )
			->where( 'type', \DoubleScale\Core\Constants\CampaignChannel::CHANNEL_WHATSAPP )
			->get();

		foreach ( $templates as $template ) {
			$external_id = $template->get_setting( 'external_id' );
			if ( $external_id === $template_sid ) {
				return $template;
			}
		}

		// Not found in DB, fetch from Meta Api and save
		try {
			$fetcher = new \DoubleScale\Modules\Campaigns\Services\MetaTemplateFetcher();
			$meta_template = $fetcher->fetch_by_sid( $template_sid );

			if ( $meta_template ) {
				$saver = new \DoubleScale\Modules\Campaigns\Services\MetaTemplateSaver();
				return $saver->save_on_use( $meta_template );
			}
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				'Failed to fetch WhatsApp template from Meta',
				array(
					'template_sid' => $template_sid,
					'error'        => $e->getMessage(),
					'code'         => 'whatsapp_template_fetch_failed',
				)
			);
		}

		return null;
	}
}

// Registered via Pro main class
