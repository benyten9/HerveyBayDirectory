<?php

/**
 * Abstract Send Message Action
 * Base class for all message sending actions (Email, Sms, WhatsApp)
 *
 * This class consolidates the common logic for sending messages across all channels:
 * - Template retrieval from step settings (templates created by model events)
 * - Tracking record creation
 * - Campaign infrastructure reuse
 * - Error handling and logging
 * - Validation patterns
 *
 * Child classes only need to implement channel-specific behavior.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Abstracts;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Pro\Utils;
use DoubleScale\Pro\Modules\SendQueue\SendQueue;

/**
 * AbstractSendMessage class
 */
abstract class AbstractSendMessage extends Action {




	/**
	 * Source - all message actions are of type 'message'
	 *
	 * @var string
	 */
	public $source = 'message';

	/**
	 * Get the channel type (email, sms, whatsapp)
	 * Used for template creation, tracking, and campaign type
	 *
	 * @return string
	 */
	abstract protected function get_channel_type();

	/**
	 * Get the tracking mode constant
	 *
	 * @return int CommunicationTrackingModel::MODE_EMAIL, MODE_SMS, or MODE_WHATSAPP
	 */
	abstract protected function get_tracking_mode();

	/**
	 * Get the recipient field from contact (email or phone)
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return string|null
	 */
	abstract protected function get_recipient( ContactModel $contact);

	/**
	 * Validate the recipient field
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return array|null Returns array with status/message if invalid, null if valid
	 */
	abstract protected function validate_recipient( ContactModel $contact);

	/**
	 * Get the processing instance for this channel
	 * Returns EmailProcessing, SmsProcessing, or WhatsappProcessing instance
	 *
	 * @return object
	 */
	abstract protected function get_processing_instance();

	/**
	 * Get the channel name for logging (Email, Sms, WhatsApp)
	 *
	 * @return string
	 */
	abstract protected function get_channel_name();

	/**
	 * Whether this is a cart reminder the shopper agreed to at checkout.
	 *
	 * With GDPR compliance on, a shopper's new contact is "unverified": they
	 * saw the cart-reminder notice but never opted into marketing email. The
	 * reminder about their own cart is still allowed, unless they chose
	 * "No thanks". Unsubscribed, bounced and blocked contacts never get it.
	 *
	 * @param ContactModel           $contact            Contact.
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @return bool
	 */
	protected function is_consented_cart_reminder( ContactModel $contact, AutomationContactModel $automation_contact ) {
		if ( 'email' !== $this->get_channel_type() || 'unverified' !== $contact->email_status ) {
			return false;
		}

		$cart_id = (int) $automation_contact->get_data( 'cart_id' );
		if ( ! $cart_id ) {
			return false;
		}

		$cart = AbandonedCartModel::find( $cart_id );

		return $cart && 'opt-out' !== $cart->status && strtolower( (string) $cart->email ) === strtolower( (string) $contact->email );
	}

	/**
	 * Process Action
	 * Consolidated logic for all message sending actions
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 *
	 * @return bool|array True on success, false or array with status on failure
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		try {
			$contact      = $automation_contact->contact;
			$channel_name = $this->get_channel_name();
			$channel_type = $this->get_channel_type();

			// Check if contact is subscribed to this channel
			if ( ! $contact->is_subscribed_to_channel( $channel_type ) && ! $this->is_consented_cart_reminder( $contact, $automation_contact ) ) {
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
					/* translators: %s: channel name (email, Sms, WhatsApp) */
					'message' => sprintf( __( 'Contact unsubscribed from %s', 'doublescale'), $channel_name ),
					'code'    => 'contact_unsubscribed',
				);
			}

			// 1. Validate recipient
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

			// 2. Channel subscription check already handled above by is_subscribed_to_channel()
			// No additional global status check needed since global status field was removed

			// 3. Get template from step settings
			// Two approaches: auto-generated templates (Sms/Email) vs pre-selected templates (Whatsapp)
			// - Sms/Email: template_ids array (auto-generated by model events)
			// - WhatsApp: template_id integer (user-selected, requires variables setup)
			$template_id = $step->get_setting( 'template_id' ); // Direct template selection (Whatsapp)
			if ( empty( $template_id ) ) {
				// Fallback to auto-generated templates (Sms/Email)
				$template_ids = $step->get_setting( 'template_ids', array() );
				if ( ! empty( $template_ids ) ) {
					$template_id = reset( $template_ids );
				}
			}

			if ( empty( $template_id ) ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: No template ID found in step settings",
					array(
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
						'contact_id'    => $contact->id,
						'code'          => "send_{$channel_type}_no_template_id",
					)
				);
				throw new \Exception( "No template ID found for automation step {$step->id}" );
			}

			$template = TemplateModel::find( $template_id );

			if ( ! $template ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Template not found in database",
					array(
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
						'template_id'   => $template_id,
						'contact_id'    => $contact->id,
						'code'          => "send_{$channel_type}_template_not_found",
					)
				);
				throw new \Exception( "Template {$template_id} not found" );
			}

			// 4. Create tracking record BEFORE sending (critical for analytics)
			try {
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
						'status'      => TrackingStatus::PENDING,
						'hash_key'    => Utils::generate_hash_key(),
					)
				);
			} catch ( \Exception $e ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Exception creating tracking record",
					array(
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
						'contact_id'    => $contact->id,
						'template_id'   => $template->id,
						'error'         => $e->getMessage(),
						'code'          => "send_{$channel_type}_tracking_exception",
					)
				);
				throw new \Exception( "Failed to create tracking record: {$e->getMessage()}" );
			}

			// Validate tracking was created successfully
			if ( ! $tracking || ! $tracking->id || $tracking->id === 0 ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Failed to create tracking record - invalid ID",
					array(
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
						'contact_id'    => $contact->id,
						'template_id'   => $template->id,
						'tracking_id'   => $tracking ? $tracking->id : 'null',
						'code'          => "send_{$channel_type}_tracking_creation_failed",
					)
				);
				throw new \Exception( 'Failed to create tracking record in database - ID is invalid' );
			}

			// 5. Prepare channel-specific message data (e.g., WhatsApp variables, Sms/Email content)
			// This allows child classes to perform pre-send setup (storing meta, validation, etc.)
			try {
				$message_data = $this->prepare_message_data( $step, $contact, $tracking );
			} catch ( \Exception $e ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Failed to prepare message data",
					array(
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
						'contact_id'    => $contact->id,
						'error'         => $e->getMessage(),
						'code'          => "send_{$channel_type}_prepare_data_failed",
					)
				);
				throw $e;
			}

			// 6. Bulk runs, a backlog, the daily cap or the WhatsApp tier: the
			// message waits in the send queue and this step waits with it.
			$queued = $this->maybe_queue( $automation, $step, $automation_contact, $tracking );
			if ( $queued ) {
				return $queued;
			}

			// 7. Otherwise send now, exactly as before.
			return $this->deliver( $automation, $step, $automation_contact, $tracking );
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				"Send {$this->get_channel_name()} action: Exception occurred",
				array(
					'automation_id' => $automation->id ?? 0,
					'contact_id'    => $automation_contact->contact->id ?? 0,
					'error'         => $e->getMessage(),
					'code'          => "send_{$this->get_channel_type()}_exception",
				)
			);
			return false;
		}
	}

	/**
	 * Send a prepared message (its tracking row exists) and report the result.
	 * Used for inline sends and by the send queue's dispatcher.
	 *
	 * @param AutomationModel            $automation         Workflow.
	 * @param AutomationStepModel        $step               Send step.
	 * @param AutomationContactModel     $automation_contact Contact's journey.
	 * @param CommunicationTrackingModel $tracking           Prepared tracking row.
	 * @return bool|array True when sent; ['success' => false, 'message'] on failure.
	 */
	public function deliver( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact, CommunicationTrackingModel $tracking ) {
		$contact      = $automation_contact->contact;
		$channel_name = $this->get_channel_name();
		$channel_type = $this->get_channel_type();
		$template     = (object) array( 'id' => $tracking->template_id );

		try {
			// 6. Create dummy campaign for process_campaign_message() to work
			// This allows us to reuse 100% of existing campaign infrastructure
			$dummy_campaign         = new CampaignModel(
				array(
					'id'       => $automation->id,
					'name'     => 'Automation: ' . $automation->name,
					'type'     => $channel_type,
					'settings' => array(),
				)
			);
			$dummy_campaign->exists = true; // Mark as existing to prevent save attempts

			// 7. REUSE EXISTING CAMPAIGN INFRASTRUCTURE
			// This gives us: merge tags, provider integration, tracking, etc.
			// Pass automation_contact instead of contact to support automation-specific merge tags
			$this->get_processing_instance()->process_campaign_message(
				$dummy_campaign,
				$automation_contact,
				$tracking
			);

			// 8. Check result and return
			// Validate tracking ID before refresh to avoid "No query results" error
			if ( ! $tracking->id || $tracking->id === 0 ) {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Tracking record was not created properly",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'tracking_id'   => $tracking->id,
						'code'          => "send_{$channel_type}_invalid_tracking_id",
					)
				);
				throw new \Exception( 'Failed to create tracking record' );
			}

			// Refresh tracking record to get updated status
			try {
				$tracking->refresh();
			} catch ( \Exception $e ) {
				$tracking_id = $tracking->id;
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Failed to refresh tracking record",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'tracking_id'   => $tracking_id,
						'error'         => $e->getMessage(),
						'code'          => "send_{$channel_type}_refresh_failed",
					)
				);
				// Try to fetch the tracking record manually as fallback
				$tracking = CommunicationTrackingModel::find( $tracking_id );
				if ( ! $tracking ) {
					throw new \Exception( "Tracking record {$tracking_id} not found after send" );
				}
			}

			if ( $tracking->status === TrackingStatus::SENT ) {
				doublescale_get_logger()->info(
					"Send {$channel_name} action: Message sent successfully",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'tracking_id'   => $tracking->id,
						'template_id'   => $template->id,
						'code'          => "send_{$channel_type}_success",
					)
				);
				return true;
			} else {
				doublescale_get_logger()->error(
					"Send {$channel_name} action: Message sending failed",
					array(
						'automation_id' => $automation->id,
						'contact_id'    => $contact->id,
						'tracking_id'   => $tracking->id,
						'status'        => $tracking->status,
						'code'          => "send_{$channel_type}_failed",
					)
				);
				$error = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::get_meta_value( $tracking->id, 'send_error' );
				return array(
					'success' => false,
					'message' => is_string( $error ) && '' !== trim( $error )
						/* translators: %1$s: channel name, %2$s: provider error. */
						? sprintf( __( '%1$s could not be sent: %2$s', 'doublescale' ), $channel_name, trim( $error ) )
						/* translators: %s: channel name. */
						: sprintf( __( '%s could not be sent.', 'doublescale' ), $channel_name ),
				);
			}
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
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Why a queued message may no longer go out ('' when it may): the contact
	 * unsubscribed from the channel or lost their address/number meanwhile.
	 *
	 * @param ContactModel           $contact            Contact.
	 * @param AutomationContactModel $automation_contact Contact's journey.
	 * @return string
	 */
	public function delivery_blocked( ContactModel $contact, AutomationContactModel $automation_contact ) {
		if ( ! $contact->is_subscribed_to_channel( $this->get_channel_type() ) && ! $this->is_consented_cart_reminder( $contact, $automation_contact ) ) {
			/* translators: %s: channel name (Email, SMS, WhatsApp) */
			return sprintf( __( 'Contact unsubscribed from %s', 'doublescale' ), $this->get_channel_name() );
		}
		$recipient_error = $this->validate_recipient( $contact );
		if ( $recipient_error ) {
			return (string) ( $recipient_error['message'] ?? __( 'The contact has no address to send to.', 'doublescale' ) );
		}
		return '';
	}

	/**
	 * Queue the prepared message instead of sending it now, when the send
	 * queue says it should wait.
	 *
	 * @param AutomationModel            $automation         Workflow.
	 * @param AutomationStepModel        $step               Send step.
	 * @param AutomationContactModel     $automation_contact Contact's journey.
	 * @param CommunicationTrackingModel $tracking           Prepared tracking row.
	 * @return array|null The 'queued' result, or null to send now.
	 */
	protected function maybe_queue( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact, CommunicationTrackingModel $tracking ) {
		if ( ! class_exists( SendQueue::class ) ) {
			return null;
		}
		$channel = (string) $this->get_channel_type();
		$reason  = SendQueue::hold_reason( $channel, (string) $tracking->recipient );
		if ( '' === $reason ) {
			return null;
		}
		if ( ! SendQueue::push( $channel, (int) $tracking->id, (int) $automation->id, (int) $step->id, (int) $automation_contact->id, (int) $automation_contact->contact_id ) ) {
			return null;
		}
		return array(
			'status'  => 'queued',
			'message' => __( 'Queued for sending', 'doublescale' ),
		);
	}

	/**
	 * Prepare channel-specific message data for sending
	 *
	 * This method should be implemented by child classes to extract relevant message
	 * content (e.g., body for Sms, template_id and variables for WhatsApp) from
	 * the automation step settings and prepare it for the processing instance.
	 *
	 * For WhatsApp: Should extract template_id and template_variables, store them
	 * in tracking meta, and return prepared data.
	 *
	 * For Sms/Email: Should extract body/subject and return prepared data.
	 *
	 * @param AutomationStepModel        $step     Automation Step Model.
	 * @param ContactModel                $contact  Contact Model.
	 * @param CommunicationTrackingModel $tracking Communication Tracking Model.
	 * @return array Prepared message data (structure varies by channel).
	 * @throws \Exception If required message data is missing or invalid.
	 */
	protected function prepare_message_data( AutomationStepModel $step, ContactModel $contact, CommunicationTrackingModel $tracking ) {
		// This method is implemented by child classes to prepare the message data for sending
		// For WhatsApp, it should extract template_id and template_variables, store them in tracking meta, and return prepared data.
		// For Sms/Email, it should extract body/subject and return prepared data.
		// By default, we return an empty array to avoid errors.
		return array();
	}
}
