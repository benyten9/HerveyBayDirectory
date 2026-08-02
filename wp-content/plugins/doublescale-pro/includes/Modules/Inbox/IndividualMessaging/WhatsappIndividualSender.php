<?php
/**
 * WhatsApp Individual Message Sender
 * Handles sending individual WhatsApp messages to contacts via Meta WhatsApp Business Api
 *
 * WhatsApp Strategy: Templates Only
 * - All WhatsApp messages must use approved business templates
 * - Free-text messages are not supported (except within 24h conversation window)
 * - Requires template_id and optional template_variables in request
 *
 * Note: Twilio WhatsApp support has been disabled. Only Meta WhatsApp is supported.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\IndividualMessaging;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractIndividualMessageSender;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;
use DoubleScale\Modules\Tracking\Whatsapp;
use DoubleScale\Pro\Traits\WhatsappTemplatePreparation;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Validators\PhoneValidator;

/**
 * WhatsappIndividualSender class
 *
 * Concrete implementation for WhatsApp individual message sending.
 * Requires approved business templates - no free-text messages.
 *
 * @since 1.0.0
 */
class WhatsappIndividualSender extends AbstractIndividualMessageSender {

	use WhatsappTemplatePreparation;

	/**
	 * Get channel type
	 *
	 * @since 1.0.0
	 *
	 * @return string Channel type
	 */
	protected function get_channel_type() {
		return CampaignChannel::STR_WHATSAPP;
	}

	/**
	 * Get activity type
	 *
	 * @since 1.0.0
	 *
	 * @return string Activity type
	 */
	protected function get_activity_type() {
		return 'whatsapp_sent';
	}

	/**
	 * Get tracking mode
	 *
	 * @since 1.0.0
	 *
	 * @return int Tracking mode constant
	 */
	protected function get_tracking_mode() {
		return CommunicationTrackingModel::MODE_WHATSAPP;
	}

	/**
	 * Get tracking class
	 *
	 * @since 1.0.0
	 *
	 * @return string Tracking class name
	 */
	protected function get_tracking_class() {
		return WhatsApp::class;
	}

	/**
	 * Validate recipient phone number
	 *
	 * @since 1.0.0
	 *
	 * @param string $recipient Phone number to validate
	 * @return true|WP_Error True if valid, WP_Error if invalid
	 */
	protected function validate_recipient( $recipient ) {
		// Validate using centralized utility
		$validation = PhoneValidator::validate( $recipient, 'individual_whatsapp' );

		if ( ! $validation['valid'] ) {
			return new WP_Error(
				'invalid_phone',
				$validation['error'],
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate WhatsApp-specific requirements
	 *
	 * Similar to EmailIndividualSender::validate_email_requirements()
	 * Validates template requirement before delegating to parent
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object
	 * @return true|WP_Error|TemplateModel True if valid, WP_Error if invalid, TemplateModel if valid
	 */
	protected function validate_whatsapp_requirements( $request ) {
		$template_id = $request->get_param( 'template_id' );

		// WhatsApp requires template
		if ( empty( $template_id ) ) {
			return new WP_Error(
				'template_required',
				__( 'Whatsapp messages require an approved business template. Please select a template.', 'doublescale'),
				array( 'status' => 400 )
			);
		}

		// Get and validate template
		$template = TemplateModel::find( $template_id );
		if ( ! $template ) {
			return new WP_Error(
				'template_not_found',
				__( 'Template not found', 'doublescale'),
				array( 'status' => 404 )
			);
		}

		// Validate it's a WhatsApp business template
		if ( ! $template->is_whatsapp_business_template() ) {
			return new WP_Error(
				'invalid_template',
				__( 'Whatsapp requires an approved business template. The selected template is not a WhatsApp business template.', 'doublescale'),
				array( 'status' => 400 )
			);
		}

		return $template;
	}

	/**
	 * Send individual WhatsApp message
	 *
	 * Supports two modes:
	 * 1. Template message - requires template_id (business-initiated)
	 * 2. Session message - free-text within 24h conversation window
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( $request ) {
		$template_id = $request->get_param( 'template_id' );
		$message     = $request->get_param( 'message' );

		// Determine message type based on parameters
		if ( ! empty( $template_id ) ) {
			// Template message flow
			$validation = $this->validate_whatsapp_requirements( $request );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			return $this->send_template_message( $request, $validation );
		} elseif ( ! empty( $message ) ) {
			// Session message flow (free-text within 24h window)
			return $this->send_session_message( $request );
		} else {
			return new WP_Error(
				'missing_content',
				__( 'Either template_id or message is required', 'doublescale'),
				array( 'status' => 400 )
			);
		}
	}

	/**
	 * Send session message (free-text within 24h window)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	protected function send_session_message( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$to         = $request->get_param( 'to' );
			$message    = $request->get_param( 'message' );
			$deal_id    = $request->get_param( 'deal_id' ) ?? null;

			// Validate contact exists
			$contact = ContactModel::find( $contact_id );
			if ( ! $contact ) {
				return new WP_Error( 'not_found', __( 'Contact not found', 'doublescale'), array( 'status' => 404 ) );
			}

			// Validate recipient is provided
			if ( empty( $to ) ) {
				return new WP_Error(
					'whatsapp_phone_required',
					__( 'Whatsapp phone number is required.', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			// Validate recipient format
			$validation = $this->validate_recipient( $to );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			// Check 24h conversation window
			$window = \DoubleScale\Modules\Campaigns\Services\WhatsappConversationWindow::check( $contact_id );
			if ( ! $window['active'] ) {
				$reason = $window['reason'] === 'no_inbound_messages'
					? __( 'No incoming WhatsApp messages from this contact. Send a template message first to start the conversation.', 'doublescale')
					: __( 'The 24-hour conversation window has expired. Please use an approved template.', 'doublescale');

				return new WP_Error(
					'conversation_window_closed',
					$reason,
					array(
						'status'       => 400,
						'window'       => $window,
						'requires_template' => true,
					)
				);
			}

			// Get provider
			$provider = MessageProviderRegistry::instance()->get_provider( $this->get_channel_type() );
			if ( ! $provider ) {
				return new WP_Error(
					'provider_not_configured',
					__( 'Whatsapp provider not configured. Please configure Meta WhatsApp in settings.', 'doublescale'),
					array( 'status' => 500 )
				);
			}

			// Process merge tags in message
			$processed_message = MergeTagsManager::instance()->process_merge_tags( $message, $contact );

			// Create activity
			$activity = $this->create_activity( $contact, $to, null, $processed_message, $deal_id );

			// Create tracking entry
			$tracking_entry = $this->create_tracking_entry( $contact, $to, $activity->id );

			// Prepare session message data
			$api_data = array(
				'To'                 => $to,
				'Body'               => $processed_message,
				'is_session_message' => true, // Flag for provider
			);

			// Add webhook URL
			$webhook_url = $provider->get_webhook_url( CampaignChannel::STR_WHATSAPP );
			if ( $webhook_url ) {
				$api_data['StatusCallback'] = $webhook_url;
			}

			// Send via provider
			$result = $provider->send_message( CampaignChannel::STR_WHATSAPP, $api_data, $contact );

			// Handle result
			if ( ! isset( $result['success'] ) || ! $result['success'] ) {
				throw new \Exception( $result['error'] ?? __( 'Whatsapp session message sending failed', 'doublescale') );
			}

			// Update tracking status
			$tracking_entry->update(
				array(
					'status'      => TrackingStatus::SENT,
					'sent_at'     => current_time( 'mysql', true ),
					'external_id' => $result['message_id'] ?? null,
				)
			);

			// Log success
			doublescale_get_logger()->info(
				__( 'Individual WhatsApp session message sent successfully', 'doublescale'),
				array(
					'contact_id'   => $contact->id,
					'activity_id'  => $activity->id,
					'tracking_id'  => $tracking_entry->id,
					'window_left'  => $window['minutes_left'] . ' minutes',
				)
			);

			return new WP_REST_Response(
				array(
					'success'      => true,
					'message'      => __( 'Whatsapp message sent successfully', 'doublescale'),
					'activity_id'  => $activity->id,
					'tracking_id'  => $tracking_entry->id,
					'message_type' => 'session',
				),
				200
			);

		} catch ( \Exception $e ) {
			return $this->handle_error( $e, $tracking_entry ?? null, $activity ?? null, $contact_id ?? null );
		}
	}

	/**
	 * Send template-based WhatsApp message
	 *
	 * Extracted from send() to reduce duplication and improve clarity.
	 * Reuses parent's protected methods where possible.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request  Request object
	 * @param TemplateModel  $template Validated template
	 * @return WP_REST_Response|WP_Error
	 */
	protected function send_template_message( $request, $template ) {
		try {
			$contact_id         = $request->get_param( 'id' );
			$to                 = $request->get_param( 'to' );
			$template_variables = $request->get_param( 'template_variables' ) ?? array();
			$deal_id            = $request->get_param( 'deal_id' ) ?? null;

			// Validate contact exists
			$contact = ContactModel::find( $contact_id );
			if ( ! $contact ) {
				return new WP_Error( 'not_found', __( 'Contact not found', 'doublescale'), array( 'status' => 404 ) );
			}

			// Validate recipient is provided
			if ( empty( $to ) ) {
				return new WP_Error(
					'whatsapp_phone_required',
					__( 'Whatsapp phone number is required.', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			// Validate recipient format
			$validation = $this->validate_recipient( $to );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			// Get provider (same logic as parent, but explicit for templates)
			$provider = MessageProviderRegistry::instance()->get_provider( $this->get_channel_type() );
			if ( ! $provider ) {
				return new WP_Error(
					'provider_not_configured',
					__( 'Whatsapp provider not configured. Please configure Meta WhatsApp in settings.', 'doublescale'),
					array( 'status' => 500 )
				);
			}

			// REUSE: Parent's create_activity method
			$activity = $this->create_activity( $contact, $to, null, $template->body, $deal_id );

			// REUSE: Parent's create_tracking_entry method
			$tracking_entry = $this->create_tracking_entry( $contact, $to, $activity->id );

			// UNIQUE: WhatsApp template preparation
			$message_data = $this->prepare_template_message( $template, $contact, $template_variables, $tracking_entry );

			// UNIQUE: Send template via provider
			$result = $this->send_template_via_provider( $provider, $to, $message_data, $contact );

			// UNIQUE: Handle template result
			return $this->handle_template_result( $result, $tracking_entry, $activity, $provider, $contact, $to, $template );

		} catch ( \Exception $e ) {
			// REUSE: Parent's error handling
			return $this->handle_error( $e, $tracking_entry ?? null, $activity ?? null, $contact_id ?? null );
		}
	}

	/**
	 * Prepare WhatsApp template message
	 *
	 * Uses the WhatsappTemplatePreparation trait for shared logic.
	 *
	 * @since 1.0.0
	 *
	 * @param TemplateModel               $template           Template model.
	 * @param ContactModel                $contact            Contact model.
	 * @param array                        $template_variables Template variables from request.
	 * @param CommunicationTrackingModel $tracking_entry     Tracking entry.
	 * @return array Message data with ContentSid and ContentVariables (JSON-encoded).
	 */
	protected function prepare_template_message( $template, $contact, $template_variables, $tracking_entry ) {
		// Use trait method with JSON encoding for individual messages
		// (Provider expects JSON string for individual sending)
		return $this->prepare_whatsapp_template_data(
			$template,
			$contact,
			$template_variables,
			$tracking_entry,
			true // encode_as_json = true for individual sender
		);
	}

	/**
	 * Send template via provider
	 *
	 * @since 1.0.0
	 *
	 * @param \DoubleScale\Pro\Modules\Inbox\MessageProviderInterface $provider     Provider instance
	 * @param string                                          $to           Recipient
	 * @param array                                           $message_data Message data with ContentSid
	 * @param ContactModel                                   $contact      Contact model
	 * @return array Provider result
	 */
	protected function send_template_via_provider( $provider, $to, $message_data, $contact ) {
		$api_data = array(
			'To'         => $to,
			'ContentSid' => $message_data['ContentSid'],
		);

		if ( ! empty( $message_data['ContentVariables'] ) ) {
			$api_data['ContentVariables'] = $message_data['ContentVariables'];
		}

		// Add webhook URL for delivery status tracking
		$webhook_url = $provider->get_webhook_url( CampaignChannel::STR_WHATSAPP );
		if ( $webhook_url ) {
			$api_data['StatusCallback'] = $webhook_url;
		}

		return $provider->send_message( CampaignChannel::STR_WHATSAPP, $api_data, $contact );
	}

	/**
	 * Handle template send result
	 *
	 * @since 1.0.0
	 *
	 * @param array                                           $result         Provider result
	 * @param CommunicationTrackingModel                    $tracking_entry Tracking record
	 * @param \DoubleScale\Modules\Activities\Models\ActivityModel                 $activity       Activity record
	 * @param \DoubleScale\Pro\Modules\Inbox\MessageProviderInterface $provider       Provider instance
	 * @param ContactModel                                   $contact        Contact model
	 * @param string                                          $to             Recipient
	 * @param TemplateModel                                  $template       Template used
	 * @return WP_REST_Response|WP_Error
	 */
	protected function handle_template_result( $result, $tracking_entry, $activity, $provider, $contact, $to, $template ) {
		// Validate send result
		if ( ! isset( $result['success'] ) || ! $result['success'] ) {
			$error_message = $result['error'] ?? __( 'Whatsapp template sending failed', 'doublescale');
			throw new \Exception( $error_message );
		}

		// Update tracking status
		$tracking_entry->update(
			array(
				'status'      => TrackingStatus::SENT,
				'sent_at'     => current_time( 'mysql', true ),
				'external_id' => $result['message_id'] ?? null,
				'template_id' => $template->id,
			)
		);

		// Log success
		doublescale_get_logger()->info(
			__( 'Individual WhatsApp template sent successfully', 'doublescale'),
			array(
				'contact_id'  => $contact->id,
				'activity_id' => $activity->id,
				'tracking_id' => $tracking_entry->id,
				'template_id' => $template->id,
				'author_id'   => get_current_user_id(),
				'recipient'   => $to,
				'external_id' => $result['message_id'] ?? null,
			)
		);

		return new WP_REST_Response(
			array(
				'success'     => true,
				'message'     => __( 'Whatsapp template sent successfully', 'doublescale'),
				'activity_id' => $activity->id,
				'tracking_id' => $tracking_entry->id,
				'template_id' => $template->id,
			),
			200
		);
	}
}

