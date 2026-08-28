<?php

/**
 * WhatsApp Campaign Processing
 * This class is responsible for handling WhatsApp campaign processing
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Campaign;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Modules\Campaigns\Abstracts\AbstractCampaignProcessing;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Tracking\Whatsapp;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Validators\PhoneValidator;

/**
 * WhatsApp Campaign Processing class
 */
class WhatsappProcessing extends AbstractCampaignProcessing {


	/**
	 * Communication channel
	 *
	 * @var string
	 */
	protected $channel = CampaignChannel::STR_WHATSAPP;

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	public function add_hooks() {
		$this->register_campaign_processing_hooks();
	}

	/**
	 * Get campaign message mode
	 *
	 * @return int
	 */
	public function get_message_mode() {
		return CommunicationTrackingModel::MODE_WHATSAPP;
	}

	/**
	 * Get channel context for merge tags
	 *
	 * @return string
	 */
	public function get_channel_context() {
		return CampaignChannel::STR_WHATSAPP;
	}

	/**
	 * Prepare message content - WhatsApp templates via Meta WhatsApp Business Api
	 *
	 * WhatsApp messaging strategy:
	 * - Uses approved business templates from Meta WhatsApp
	 * - Template must have a valid external_id (template_name:language format)
	 *
	 * Note: Twilio WhatsApp support has been disabled. Only Meta WhatsApp is supported.
	 *
	 * @param TemplateModel                                                               $template                      Template model.
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model.
	 * @param CommunicationTrackingModel                                                  $campaign_message              Campaign tracking record.
	 * @return array Message data array with template name/language and ContentVariables.
	 * @throws \Exception If template is missing required data.
	 */
	protected function prepare_message_content( TemplateModel $template, $contact_or_automation_contact, CommunicationTrackingModel $campaign_message ) {
		$provider = $this->get_message_provider();

		// Providers that do not require templates can send free-form text.
		if ( $provider && method_exists( $provider, 'requires_template' ) && ! $provider->requires_template( $this->channel ) ) {
			add_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

			$contact = $contact_or_automation_contact instanceof ContactModel
				? $contact_or_automation_contact
				: $contact_or_automation_contact->contact;

			$message = $template->body ?? $this->get_default_campaign_content();
			$message = MergeTagsManager::instance()->process_merge_tags( $message, $contact_or_automation_contact );

			remove_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

			return array(
				'body'      => $message,
				'recipient' => $campaign_message->recipient,
				'hash_key'  => $campaign_message->hash_key,
			);
		}

		// Set channel context for merge tags
		add_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

		// Check if template has a ContentSid/external_id
		$content_sid = $template->get_whatsapp_content_sid();

		// For templates without ContentSid, require approved business template
		if ( empty( $content_sid ) && ! $template->is_whatsapp_business_template() ) {
			throw new \Exception( esc_html__( 'Whatsapp messages require an approved business template. Please create and approve a WhatsApp template in Meta Business Suite, then import it via Settings > WhatsApp Templates.', 'doublescale' ) );
		}

		// Prepare template message with ContentSid and variables
		$message_data = $this->prepare_template_message( $template, $contact_or_automation_contact, $campaign_message );

		// Remove filter to prevent pollution
		remove_filter( 'doublescale_active_channel_context', array( $this, 'get_channel_context' ), 10 );

		return $message_data;
	}

	/**
	 * Prepare WhatsApp template message with ContentSid and ContentVariables.
	 *
	 * Adds campaign-specific handling for automation variable overrides on top of
	 * the base prepare_whatsapp_template_data() flow.
	 *
	 * @param TemplateModel                                                               $template                      Template model.
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model.
	 * @param CommunicationTrackingModel                                                  $campaign_message              Campaign tracking record.
	 * @return array Message data with template fields.
	 */
	protected function prepare_template_message( TemplateModel $template, $contact_or_automation_contact, CommunicationTrackingModel $campaign_message ): array {
		// Get the actual contact model (AutomationContactModel has a contact property)
		$contact = $contact_or_automation_contact instanceof ContactModel
			? $contact_or_automation_contact
			: $contact_or_automation_contact->contact;

		$content_sid = $template->get_whatsapp_content_sid();

		if ( empty( $content_sid ) ) {
			throw new \Exception( esc_html__( 'Whatsapp Business template missing ContentSid', 'doublescale' ) );
		}

		// Get variable mappings from template settings
		// Format: {"1": "{{contact:first_name}}", "2": "Order #123"}
		$variable_mappings = $template->get_whatsapp_variable_mappings();

		// Campaign-specific: Allow override from automation template variables (if provided via meta)
		// Automations store template variables in tracking meta during prepare_message_data()
		$automation_template_variables = CommunicationTrackingMetaModel::get_meta_value(
			$campaign_message->id,
			'automation_template_variables'
		);

		if ( ! empty( $automation_template_variables ) && is_array( $automation_template_variables ) ) {
			// Merge automation variables with template defaults (automation takes precedence)
			$variable_mappings = array_merge( $variable_mappings, $automation_template_variables );
		}

		// Use trait methods for shared processing logic
		$content_variables = $this->process_template_variables( $variable_mappings, $contact );

		// Store template params in meta for historical record
		if ( ! empty( $content_variables ) ) {
			CommunicationTrackingMetaModel::store_whatsapp_template_params(
				$campaign_message->id,
				$content_variables
			);
		}

		// Capture merge tag values for auditing
		$this->capture_merge_tag_values( $variable_mappings, $campaign_message, $contact );

		doublescale_get_logger()->debug(
			'Prepared WhatsApp template message',
			array(
				'contact_id'        => $contact->id,
				'template_id'       => $template->id,
				'content_sid'       => $content_sid,
				'content_variables' => $content_variables,
				'code'              => 'whatsapp_template_prepared',
			)
		);

		return array(
			'ContentSid'       => $content_sid,
			'ContentVariables' => $content_variables,
			'recipient'        => $campaign_message->recipient,
			'hash_key'         => $campaign_message->hash_key,
			'body'             => $template->body, // For logging/display purposes
		);
	}

	/**
	 * Send message via provider - Uses Meta WhatsApp Business Api templates
	 *
	 * Note: Twilio WhatsApp/sandbox support has been disabled. Only Meta WhatsApp is supported.
	 *
	 * @param array                                                                       $message_data Prepared message data
	 * @param ContactModel|\DoubleScale\Modules\Automations\Models\AutomationContactModel $contact_or_automation_contact Contact or Automation Contact model
	 * @param CommunicationTrackingModel                                                  $campaign_message Campaign tracking record
	 * @return array Result array with 'success' boolean and optional data
	 */
	protected function send_via_provider( $message_data, $contact_or_automation_contact, CommunicationTrackingModel $campaign_message ) {
		// Get the actual contact model (AutomationContactModel has a contact property)
		$contact = $contact_or_automation_contact instanceof ContactModel
			? $contact_or_automation_contact
			: $contact_or_automation_contact->contact;
		try {
			// Get message provider
			$provider = $this->get_message_provider();
			if ( ! $provider ) {
				throw new \Exception( esc_html__( 'WhatsApp provider not configured. Please configure a WhatsApp provider in Settings > Integrations.', 'doublescale' ) );
			}

			// Validate provider is configured
			if ( ! $provider->is_configured() ) {
				throw new \Exception( esc_html__( 'WhatsApp provider is not configured. Please configure it in Settings > Integrations.', 'doublescale' ) );
			}

			$content_sid = $message_data['ContentSid'] ?? null;
			$body        = $message_data['body'] ?? '';

			// Free-form providers send rendered body text directly.
			if ( method_exists( $provider, 'requires_template' ) && ! $provider->requires_template( $this->channel ) ) {
				if ( empty( $body ) ) {
					throw new \Exception( esc_html__( 'WhatsApp message body is empty.', 'doublescale' ) );
				}

				$api_data = array(
					'To'   => $campaign_message->recipient,
					'Body' => $body,
				);

				if ( ! empty( $message_data['media_urls'] ) ) {
					$api_data['media_urls'] = (array) $message_data['media_urls'];
				}

				$webhook_url = $provider->get_webhook_url( $this->channel );
				if ( $webhook_url ) {
					$api_data['StatusCallback'] = $webhook_url;
				}

				$result = $provider->send_message( $this->channel, $api_data, $contact );
				return $this->handle_provider_response( $result, $campaign_message, $contact );
			}

			// Validate ContentSid is present for Meta template providers.
			if ( empty( $content_sid ) ) {
				throw new \Exception( esc_html__( 'Whatsapp message missing template ID. All WhatsApp messages must use approved Meta business templates.', 'doublescale' ) );
			}

			// Build Api data for Meta WhatsApp
			$api_data = array(
				'To'         => $campaign_message->recipient,
				'ContentSid' => $content_sid,
			);

			// Add template variables if provided
			if ( ! empty( $message_data['ContentVariables'] ) ) {
				$api_data['ContentVariables'] = $message_data['ContentVariables'];
			}

			// Add StatusCallback webhook
			$webhook_url = $provider->get_webhook_url( $this->channel );
			if ( $webhook_url ) {
				$api_data['StatusCallback'] = $webhook_url;
			}

			// Send via provider
			$result = $provider->send_message( $this->channel, $api_data, $contact );

			// Handle response
			return $this->handle_provider_response( $result, $campaign_message, $contact );

		} catch ( \Exception $e ) {
			return $this->handle_provider_error( $e );
		}
	}

	/**
	 * Get recipient field from contact
	 *
	 * Requires whatsapp_phone field for WhatsApp messaging.
	 * Contact must have a WhatsApp phone number set to receive WhatsApp messages.
	 *
	 * @param ContactModel $contact
	 * @return string|null
	 */
	protected function get_recipient( ContactModel $contact ) {
		// WhatsApp requires the whatsapp_phone field - no fallback to phone
		$phone = $contact->whatsapp_phone;

		if ( empty( $phone ) ) {
			doublescale_get_logger()->info(
				'Contact missing WhatsApp phone number for WhatsApp campaign',
				array(
					'code'       => 'missing_whatsapp_phone',
					'contact_id' => $contact->id,
				)
			);
			return null;
		}

		// Validate E.164 format using centralized utility
		if ( ! PhoneValidator::is_valid( $phone ) ) {
			doublescale_get_logger()->info(
				'Invalid WhatsApp phone number format for WhatsApp campaign',
				array(
					'code'       => 'invalid_phone_format',
					'contact_id' => $contact->id,
					'phone'      => $phone,
				)
			);
			return null;
		}

		return $phone;
	}

	/**
	 * Send message - calls send_via_provider
	 *
	 * @param array                      $message_data Prepared message data
	 * @param ContactModel               $contact Contact model
	 * @param CommunicationTrackingModel $campaign_message Campaign tracking record
	 * @return array Result array
	 */
	protected function send_message( $message_data, ContactModel $contact, CommunicationTrackingModel $campaign_message ) {
		return $this->send_via_provider( $message_data, $contact, $campaign_message );
	}

	/**
	 * Get tracking class
	 *
	 * @return string
	 */
	protected function get_tracking_class() {
		return WhatsApp::class;
	}

	/**
	 * Get default campaign content
	 *
	 * @return string
	 */
	protected function get_default_campaign_content() {
		return __( 'Hi {{contact:first_name}}, thank you for subscribing! Reply STOP to unsubscribe.', 'doublescale' );
	}

	/**
	 * Prepare WhatsApp template message data.
	 *
	 * Validates the template, processes variables through merge tags,
	 * and stores metadata for historical tracking.
	 *
	 * @param TemplateModel              $template           Template model with WhatsApp business template data.
	 * @param ContactModel               $contact            Contact model for merge tag processing.
	 * @param array                      $template_variables Template variable mappings (slot => value or merge tag).
	 * @param CommunicationTrackingModel $tracking_entry     Tracking record to store metadata.
	 * @param bool                       $encode_as_json     Whether to JSON-encode ContentVariables (default: false).
	 * @return array Message data with ContentSid and ContentVariables.
	 * @throws \Exception If template is missing ContentSid.
	 */
	protected function prepare_whatsapp_template_data(
		TemplateModel $template,
		ContactModel $contact,
		array $template_variables,
		CommunicationTrackingModel $tracking_entry,
		bool $encode_as_json = false
	): array {
		$content_sid = $template->get_whatsapp_content_sid();

		if ( empty( $content_sid ) ) {
			throw new \Exception( esc_html__( 'Whatsapp Business template missing ContentSid', 'doublescale' ) );
		}

		$content_variables = $this->process_template_variables( $template_variables, $contact );

		if ( ! empty( $content_variables ) ) {
			CommunicationTrackingMetaModel::store_whatsapp_template_params(
				$tracking_entry->id,
				$content_variables
			);
		}

		$this->capture_merge_tag_values( $template_variables, $tracking_entry, $contact );

		return array(
			'ContentSid'       => $content_sid,
			'ContentVariables' => $encode_as_json && ! empty( $content_variables )
				? wp_json_encode( $content_variables )
				: $content_variables,
		);
	}

	/**
	 * Process template variables through merge tags.
	 *
	 * @param array        $template_variables Variable mappings (slot => value/merge tag).
	 * @param ContactModel $contact            Contact for merge tag processing.
	 * @return array Processed variables with merge tags replaced.
	 */
	protected function process_template_variables( array $template_variables, ContactModel $contact ): array {
		$content_variables = array();

		foreach ( $template_variables as $slot => $value ) {
			$processed_value                     = MergeTagsManager::instance()
				->process_merge_tags( $value, $contact );
			$content_variables[ (string) $slot ] = $processed_value;
		}

		return $content_variables;
	}

	/**
	 * Capture merge tag values for historical tracking.
	 *
	 * @param array                      $template_variables Variable mappings.
	 * @param CommunicationTrackingModel $tracking_entry     Tracking record.
	 * @param ContactModel               $contact            Contact model.
	 * @return void
	 */
	protected function capture_merge_tag_values(
		array $template_variables,
		CommunicationTrackingModel $tracking_entry,
		ContactModel $contact
	): void {
		if ( empty( $template_variables ) ) {
			return;
		}

		$combined_values = implode( ' ', $template_variables );
		$merge_tag_keys  = MergeTagsManager::instance()->extract_merge_tag_keys( $combined_values );

		if ( ! empty( $merge_tag_keys ) ) {
			CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$tracking_entry->id,
				$merge_tag_keys,
				$contact
			);
		}
	}
}
