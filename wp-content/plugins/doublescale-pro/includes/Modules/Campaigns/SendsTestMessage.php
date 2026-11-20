<?php
/**
 * Test sends for Pro's SMS and WhatsApp engines.
 *
 * Moved from free's RestCampaignController::send_test_provider_message() with
 * the engines. Free's `/campaigns/send-test-message` route hands the request
 * to the engine that owns the channel when it brings this method, and keeps
 * its own copy for older Pro releases. Same payload, same answers.
 *
 * @package DoubleScale\Pro\Modules\Campaigns
 */

namespace DoubleScale\Pro\Modules\Campaigns;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Campaigns\Abstracts\AbstractCampaignProcessing;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;
use WP_Error;
use WP_REST_Response;

/**
 * SendsTestMessage.
 */
trait SendsTestMessage {

	/**
	 * Send a test message on this engine's channel.
	 *
	 * @param \WP_REST_Request $request Request with phone, and message (SMS) or template_id (WhatsApp).
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_message( $request ) {
		$channel = $this->channel;

		try {
			$phone   = $request->get_param( 'phone' );
			$message = $request->get_param( 'message' );

			if ( empty( $phone ) ) {
				return new WP_Error(
					'missing_phone',
					/* translators: %s: channel name (e.g., Sms, WhatsApp) */
					sprintf( __( 'Phone number is required for %s channel', 'doublescale' ), $channel ),
					array( 'status' => 400 )
				);
			}

			// Whatsapp is template-driven: Meta only accepts pre-approved
			// business templates for an outbound test, so there is no body for
			// the operator to type. Resolve the body from the selected template
			// and let the template's ContentSid reach the provider.
			$template          = null;
			$template_settings = array();

			if ( CampaignChannel::STR_WHATSAPP === $channel ) {
				$template_id = (int) $request->get_param( 'template_id' );

				if ( ! $template_id ) {
					return new WP_Error(
						'missing_template',
						__( 'Select an approved WhatsApp template before sending a test.', 'doublescale' ),
						array( 'status' => 400 )
					);
				}

				$template = TemplateModel::find( $template_id );

				if ( ! $template ) {
					return new WP_Error(
						'template_not_found',
						__( 'The selected WhatsApp template no longer exists.', 'doublescale' ),
						array( 'status' => 404 )
					);
				}

				$content_sid = $template->get_whatsapp_content_sid();

				if ( empty( $content_sid ) ) {
					return new WP_Error(
						'template_not_approved',
						__( 'This campaign lost its link to the approved Meta template. Go back to the Set-up info step, pick the template again, and save.', 'doublescale' ),
						array( 'status' => 422 )
					);
				}

				$message           = $template->body;
				$template_settings = $template->get_whatsapp_header_settings();
			}

			if ( empty( $message ) ) {
				return new WP_Error(
					'missing_message',
					__( 'Message content is required', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			$provider = MessageProviderRegistry::instance()->get_provider( $channel );

			if ( ! $provider ) {
				return new WP_Error(
					'provider_not_configured',
					/* translators: %s: channel name (e.g., Sms, WhatsApp) */
					sprintf( __( 'No provider configured for %s channel. Please configure it in Settings > Integrations.', 'doublescale' ), $channel ),
					array( 'status' => 422 )
				);
			}

			// Find contact for merge tag processing (try exact match, then sanitized E.164)
			$contact = ContactModel::where( 'phone', $phone )->first();
			if ( ! $contact ) {
				$sanitized = \DoubleScale\Core\Validators\PhoneValidator::sanitize( $phone );
				if ( $sanitized && $sanitized !== $phone ) {
					$contact = ContactModel::where( 'phone', $sanitized )->first();
				}
			}

			$processed_message = MergeTagsManager::instance()->process_merge_tags( $message, $contact );

			// Add opt-out footer for Sms test messages (match campaign behavior)
			if ( CampaignChannel::STR_SMS === $channel ) {
				$footer_text        = apply_filters( 'doublescale_message_opt_out_footer', __( 'Reply STOP to unsubscribe', 'doublescale' ), $channel );
				$processed_message .= "\n\n" . $footer_text;
			}

			$message_data = array(
				'To'   => $phone,
				'Body' => $processed_message,
			);

			// Whatsapp sends the approved template, not the body. Mirror the
			// campaign send path so a test exercises the same provider contract:
			// ContentSid plus the per-slot variables, and the header/button
			// definition for media and interactive templates.
			if ( CampaignChannel::STR_WHATSAPP === $channel && $template ) {
				$variable_mappings = $request->get_param( 'template_variables' );

				if ( ! is_array( $variable_mappings ) || empty( $variable_mappings ) ) {
					$variable_mappings = $template->get_whatsapp_variable_mappings();
				}

				$content_variables = array();
				foreach ( $variable_mappings as $slot => $value ) {
					$content_variables[ (string) $slot ] = MergeTagsManager::instance()->process_merge_tags( $value, $contact );
				}

				$message_data['ContentSid']       = $template->get_whatsapp_content_sid();
				$message_data['ContentVariables'] = $content_variables;

				if ( ! empty( $template_settings ) ) {
					$message_data['TemplateSettings'] = $template_settings;
				}
			}

			// Add webhook URL for tracking (skip for non-routable URLs like localhost)
			$webhooks_enabled = apply_filters( 'doublescale_provider_webhooks_enable', true );
			$webhook_url      = $provider->get_webhook_url( $channel );
			if ( $webhook_url && $webhooks_enabled && AbstractCampaignProcessing::is_publicly_reachable_url( $webhook_url ) ) {
				$message_data['StatusCallback'] = $webhook_url;
			}

			$result = $provider->send_message( $channel, $message_data, $contact ?? new ContactModel() );

			if ( ! isset( $result['success'] ) || ! $result['success'] ) {
				$error_message = $result['error'] ?? __( 'Failed to send test message', 'doublescale' );

				if ( isset( $result['metadata']['error_details'] ) ) {
					$error_details = $result['metadata']['error_details'];

					if ( is_string( $error_details ) ) {
						$error_message = $error_details;
					} elseif ( is_array( $error_details ) && isset( $error_details['message'] ) ) {
						$error_message = $error_details['message'];
					}
				}

				return new WP_Error( 'send_failed', $error_message, array( 'status' => 400 ) );
			}

			return new WP_REST_Response(
				/* translators: %s: channel name in uppercase (e.g., Sms, WHATSAPP) */
				array( 'message' => sprintf( __( 'Test %s sent successfully', 'doublescale' ), strtoupper( $channel ) ) ),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
