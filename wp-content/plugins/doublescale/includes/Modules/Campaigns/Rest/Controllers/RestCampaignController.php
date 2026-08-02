<?php
/**
 * Unified Campaign Controller
 * Handles all campaign types (email, sms, whatsapp) through a single unified endpoint
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Modules\Campaigns\Abstracts\AbstractCampaignController;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Campaigns\Services\CampaignStatusManager;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
// use DoubleScale\Pro\Traits\MessageProviderValidation; // Moved to Pro
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Modules\Contacts\Models\ContactUnsubscribeModel;
use DoubleScale\Modules\Campaigns\Campaign\EmailProcessing;
// use DoubleScale\Modules\Campaigns\Campaign\SmsProcessing; // Moved to Pro
// use DoubleScale\Modules\Campaigns\Campaign\WhatsappProcessing; // Moved to Pro
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Emails\EmailRenderer;
use DoubleScale\Core\MergeTags\MergeTagsManager;


/**
 * RestCampaignController class
 *
 * Unified controller handling all campaign operations across email, sms, and whatsapp channels.
 * Routes: /doublescale/v1/campaigns with type/channel parameter
 */
class RestCampaignController extends AbstractCampaignController {

	// use MessageProviderValidation; // Moved to Pro

	/**
	 * REST Base
	 *
	 * @var string
	 */
	protected $rest_base = 'campaigns';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Register the routes for the controller
	 */
	public function register_routes() {
		// Register all standard CRUD routes from parent.
		$this->register_common_routes();

		// Campaign messages route.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/messages',
			array(
				'args' => array(
					'id'       => array(
						'description' => __( 'Campaign ID', 'doublescale' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'per_page' => array(
						'description' => __( 'Items per page', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 10,
					),
					'page'     => array(
						'description' => __( 'Page number', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 1,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaign_messages' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'status' => array(
							'description' => __( 'Message status filter', 'doublescale' ),
							'type'        => 'string',
							'enum'        => array( 'all', 'sent', 'opened', 'clicked', 'failed', 'pending', 'delivered', 'scheduled' ),
							'required'    => false,
						),
					),
				),
			)
		);

		// Campaign unsubscribes route.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/unsubscribes',
			array(
				'args' => array(
					'id'       => array(
						'description' => __( 'Campaign ID', 'doublescale' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'per_page' => array(
						'description' => __( 'Items per page', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 10,
					),
					'page'     => array(
						'description' => __( 'Page number', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 1,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaign_unsubscribes' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);

		// Campaign execution runs route (for automated campaigns).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/runs',
			array(
				'args' => array(
					'id'       => array(
						'description' => __( 'Campaign ID', 'doublescale' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'per_page' => array(
						'description' => __( 'Items per page', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 10,
					),
					'page'     => array(
						'description' => __( 'Page number', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 1,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaign_runs' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);

		// Campaign run messages route (for automated campaigns).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/runs/messages',
			array(
				'args' => array(
					'id'        => array(
						'description' => __( 'Campaign ID', 'doublescale' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'run_batch' => array(
						'description' => __( 'Run batch identifier (YYYY-MM-DD HH:MM)', 'doublescale' ),
						'type'        => 'string',
						'required'    => true,
					),
					'per_page'  => array(
						'description' => __( 'Items per page', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 10,
					),
					'page'      => array(
						'description' => __( 'Page number', 'doublescale' ),
						'type'        => 'integer',
						'default'     => 1,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaign_run_messages' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'status' => array(
							'description' => __( 'Message status filter', 'doublescale' ),
							'type'        => 'string',
							'enum'        => array( 'all', 'sent', 'opened', 'clicked', 'failed', 'pending', 'delivered', 'scheduled' ),
							'required'    => false,
						),
					),
				),
			)
		);

		// Resend single message endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/messages/(?P<message_id>[\d]+)/resend',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resend_message' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id'         => array(
							'description' => __( 'Campaign ID', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'message_id' => array(
							'description' => __( 'Message/Tracking ID', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Send test message endpoint (unified for all channels).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/send-test-message',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_test_message' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'channel'    => array(
							'description' => __( 'Channel type', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => CampaignChannel::get_core_channel_strings(),
						),
						// Email parameters.
						'email'      => array(
							'description' => __( 'Email address (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'subject'    => array(
							'description' => __( 'Email subject (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'from_name'  => array(
							'description' => __( 'From name (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'from_email' => array(
							'description' => __( 'From email (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'reply_to'   => array(
							'description' => __( 'Reply-to email (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						// Sms/Whatsapp parameters.
						'phone'      => array(
							'description' => __( 'Phone number (for Sms/Whatsapp channels)', 'doublescale' ),
							'type'        => 'string',
						),
						// Common parameter.
						'message'    => array(
							'description' => __( 'Message content (body for email, message for Sms/Whatsapp)', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Send campaign test email endpoint (for email campaigns with templates).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/send-test-email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_campaign_test_email' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id'     => array(
							'description' => __( 'Campaign ID', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'emails' => array(
							'description' => __( 'Array of email addresses to send test emails to', 'doublescale' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// Bulk delete endpoint (cross-type).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-delete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_delete' ),
					'permission_callback' => array( $this, 'bulk_delete_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Array of campaign IDs to delete', 'doublescale' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Get all campaigns - override to set channel from request
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		// Set channel from query parameter for filtering
		$channel = $request->get_param( 'channel' );

		if ( ! empty( $channel ) ) {
			// Validate it's a valid channel string
			$valid_channels = CampaignChannel::get_core_channel_strings();
			if ( in_array( $channel, $valid_channels, true ) ) {
				$this->channel = $channel;
			}
		}

		return parent::get_items( $request );
	}

	/**
	 * Create campaign - override to set channel from request
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		// Set channel from type parameter (string: 'email', 'sms', 'whatsapp').
		$type = $request->get_param( 'type' );

		// WhatsApp campaigns are disabled.
		// WhatsApp messaging is still available for automations and individual contact messaging,
		// but bulk campaigns are not supported due to Meta's WhatsApp Business Api limitations:
		// - Template-only messaging outside 24-hour conversation window
		// - Strict approval process for templates
		// - Rate limits and quality scoring that make bulk campaigns impractical
		// See: includes/services/class-whatsapp-conversation-window.php for conversation window logic
		if ( $type === CampaignChannel::STR_WHATSAPP ) {
			return new WP_Error(
				'whatsapp_campaigns_disabled',
				__( 'Whatsapp campaigns are not available. WhatsApp messaging is supported for automations and individual contact messaging only.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Validate it's a valid channel string (email or sms only for campaigns).
		$valid_campaign_channels = array( CampaignChannel::STR_EMAIL );
		if ( class_exists( \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::class ) ) {
			$valid_campaign_channels[] = CampaignChannel::STR_SMS;
		}
		if ( ! in_array( $type, $valid_campaign_channels, true ) ) {
			return new WP_Error(
				'invalid_type',
				__( 'Invalid campaign type. Must be "email" or "sms".', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$this->channel = $type;

		// Automated campaigns require Pro.
		$request_settings = $request->get_param( 'settings' );
		if ( is_array( $request_settings ) && ! empty( $request_settings['automated'] ) ) {
			if ( ! class_exists( \DoubleScale\Pro\Modules\Campaigns\Automated\AutomatedCampaignsFeature::class ) ) {
				return new WP_Error(
					'pro_feature_required',
					__( 'Automated campaigns require DoubleScale Pro', 'doublescale' ),
					array( 'status' => 403 )
				);
			}
		}

		$response = parent::create_item( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Newly created campaigns that are already "active" (e.g. one-step create in the app)
		// never pass through update_item(), so schedule-based runs would not be registered
		// without this. schedule_campaign_cron() no-ops for event-based / non-schedule triggers.
		$data = $response->get_data();
		if ( $data instanceof CampaignModel ) {
			$settings = is_array( $data->settings ) ? $data->settings : array();
			if ( ! empty( $settings['automated'] ) && 'active' === $data->status
				&& class_exists( \DoubleScale\Pro\Modules\Campaigns\Automated\AutomatedCampaignsFeature::class )
			) {
				\DoubleScale\Pro\Modules\Campaigns\Automated\AutomatedCampaignHandler::instance()->schedule_campaign_cron( $data );
			}
		}

		return $response;
	}

	/**
	 * Update campaign - override to set channel from existing campaign
	 *
	 * The parent's update_item() uses $this->channel for get_contact_count()
	 * when filters are updated, so we need it set before delegating.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$campaign_id = $request->get_param( 'id' );
		$campaign    = CampaignModel::find( $campaign_id );

		if ( $campaign ) {
			$this->channel = $campaign->type;
		}

		$new_status = $request->get_param( 'status' );
		$settings   = is_array( $campaign->settings ) ? $campaign->settings : array();

		// Handle automated campaign activation/deactivation (Pro only).
		if ( ! empty( $settings['automated'] ) ) {
			if ( ! class_exists( \DoubleScale\Pro\Modules\Campaigns\Automated\AutomatedCampaignsFeature::class ) ) {
				return new WP_Error(
					'pro_feature_required',
					__( 'Automated campaigns require DoubleScale Pro', 'doublescale' ),
					array( 'status' => 403 )
				);
			}

			$handler = \DoubleScale\Pro\Modules\Campaigns\Automated\AutomatedCampaignHandler::instance();

			if ( $new_status && 'draft' === $new_status && 'active' === $campaign->status ) {
				$handler->unschedule_campaign_cron( $campaign->id );
				return parent::update_item( $request );
			}

			$old_trigger = isset( $settings['trigger'] ) ? $settings['trigger'] : null;
			$result      = parent::update_item( $request );

			$updated_campaign = CampaignModel::find( $campaign_id );
			if ( ! $updated_campaign || 'active' !== $updated_campaign->status ) {
				return $result;
			}

			$new_trigger = isset( $updated_campaign->settings['trigger'] ) ? $updated_campaign->settings['trigger'] : null;

			$needs_reschedule = false;

			if ( $new_status && 'active' === $new_status && 'active' !== $campaign->status ) {
				$needs_reschedule = true;
			} elseif ( 'active' === $campaign->status && $new_trigger !== $old_trigger ) {
				$needs_reschedule = true;
			}

			if ( $needs_reschedule && $new_trigger && 'schedule' === ( $new_trigger['trigger_type'] ?? '' ) ) {
				$handler->schedule_campaign_cron( $updated_campaign );
			} elseif ( $needs_reschedule ) {
				$handler->unschedule_campaign_cron( $campaign->id );
			}

			return $result;
		}

		return parent::update_item( $request );
	}

	/**
	 * Get campaign query - override to skip type filter for ID-based lookups
	 *
	 * In the unified controller, $this->channel is null for single-item operations
	 * (get, update, delete, duplicate, messages). The parent's get_campaign_query()
	 * already handles this: when channel is null, it returns an unfiltered query.
	 * IDs are unique so no type filter is needed.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	protected function get_campaign_query() {
		if ( $this->channel ) {
			return parent::get_campaign_query();
		}
		// No channel filter for unified controller — ID-based lookups don't need it
		return CampaignModel::query();
	}

	/**
	 * Send test message - unified implementation for all channels
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_message( $request ) {
		$channel = $request->get_param( 'channel' );

		if ( empty( $channel ) ) {
			return new WP_Error(
				'missing_channel',
				__( 'Channel parameter is required', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$this->channel = $channel;

		if ( $channel === CampaignChannel::STR_EMAIL ) {
			return $this->send_test_email( $request );
		}

		if ( $channel === CampaignChannel::STR_SMS && ! class_exists( \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::class ) ) {
			return new WP_Error(
				'pro_feature_required',
				__( 'Sms campaigns require Plugin Pro', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return $this->send_test_provider_message( $request, $channel );
	}

	/**
	 * Send test email (inlined from REST_Email_Campaign_Controller)
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	private function send_test_email( $request ) {
		try {
			$email      = $request->get_param( 'email' );
			$subject    = $request->get_param( 'subject' );
			$body       = $request->get_param( 'message' );
			$from_name  = $request->get_param( 'from_name' ) ?: get_option( 'blogname' );
			$from_email = $request->get_param( 'from_email' ) ?: get_option( 'admin_email' );
			$reply_to   = $request->get_param( 'reply_to' );

			if ( empty( $email ) ) {
				return new WP_Error(
					'missing_email',
					__( 'Email address is required for email channel', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $subject ) ) {
				return new WP_Error(
					'missing_subject',
					__( 'Subject is required for email channel', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			$emails               = new Emails();
			$emails->from_address = $from_email;
			$emails->from_name    = $from_name;

			if ( ! empty( $reply_to ) ) {
				$emails->reply_to = $reply_to;
			}

			$for_testing_body = ! empty( $body ) ? $body : $this->get_default_test_email_content();

			$contact = ContactModel::get_by_email( $email ) ?? null;
			$result  = $emails->send(
				$email,
				$subject,
				$this->process_merge_tags( $for_testing_body, $contact )
			);

			if ( ! $result ) {
				$detail = \DoubleScale\Modules\Emails\Emails::get_last_send_failure_detail();
				$msg    = __( 'Failed to send test email', 'doublescale' );
				if ( '' !== $detail ) {
					$msg .= ' ' . $detail;
				}
				return new WP_Error(
					'send_failed',
					$msg,
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array( 'message' => __( 'Test email sent successfully', 'doublescale' ) ),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Send campaign test email - sends test emails using campaign template to multiple recipients
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_campaign_test_email( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$emails      = $request->get_param( 'emails' );

			// Validate emails array
			if ( empty( $emails ) || ! is_array( $emails ) ) {
				return new WP_Error( 'invalid_emails', __( 'Please provide an array of email addresses', 'doublescale' ), array( 'status' => 400 ) );
			}

			// Validate all email addresses
			$invalid_emails = array();
			foreach ( $emails as $email ) {
				if ( ! is_email( $email ) ) {
					$invalid_emails[] = $email;
				}
			}

			if ( ! empty( $invalid_emails ) ) {
				return new WP_Error(
					'invalid_emails',
					sprintf(
						/* translators: %s: comma-separated list of invalid email addresses */
						__( 'Invalid email address(es): %s', 'doublescale' ),
						implode( ', ', $invalid_emails )
					),
					array( 'status' => 400 )
				);
			}

			// Get campaign
			$campaign = CampaignModel::find( $campaign_id );
			if ( ! $campaign ) {
				return new WP_Error( 'campaign_not_found', __( 'Campaign not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Get template ID from campaign
			$campaign_settings = is_array( $campaign->settings ) ? $campaign->settings : json_decode( $campaign->settings, true );

			// Email campaigns store template_ids, while Sms/Whatsapp store full templates
			$template_id = $campaign_settings['template_ids'][0] ?? $campaign_settings['templates'][0]['id'] ?? null;

			if ( ! $template_id ) {
				return new WP_Error( 'template_not_found', __( 'Campaign template not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Get template
			$template = TemplateModel::find( $template_id );
			if ( ! $template ) {
				return new WP_Error( 'template_not_found', __( 'Template not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Extract template settings
			$template_settings = is_array( $template->settings ) ? $template->settings : json_decode( $template->settings, true );
			$from_name         = $template_settings['from_name'] ?? get_option( 'blogname' );
			$from_email        = $template_settings['from_email'] ?? get_option( 'admin_email' );
			$reply_to          = $template_settings['reply_to'] ?? '';
			$subject           = $template->subject ?? 'Test Email';

			// Track results
			$sent_count    = 0;
			$failed_count  = 0;
			$failed_emails = array();

			// Send to each recipient
			foreach ( $emails as $recipient_email ) {
				$email_sender               = new Emails();
				$email_sender->from_address = $from_email;
				$email_sender->from_name    = $from_name;
				if ( ! empty( $reply_to ) && is_email( $reply_to ) ) {
					$email_sender->reply_to = $reply_to;
				}

				// Get contact for merge tags
				$contact = ContactModel::get_by_email( $recipient_email ) ?? null;

				// Render template
				$email_renderer = new EmailRenderer();
				$body_content   = $email_renderer->render_template( $template_id, $contact );

				if ( empty( $body_content ) ) {
					++$failed_count;
					$failed_emails[] = $recipient_email;
					continue;
				}

				// Process subject with merge tags
				$processed_subject = MergeTagsManager::instance()->process_merge_tags( $subject, $contact );

				// Send email
				$result = $email_sender->send( $recipient_email, $processed_subject, $body_content );

				if ( $result ) {
					++$sent_count;
				} else {
					++$failed_count;
					$failed_emails[] = $recipient_email;
				}
			}

			// Prepare response
			if ( $sent_count > 0 && $failed_count === 0 ) {
				return new WP_REST_Response(
					array(
						'success'    => true,
						'message'    => sprintf(
							/* translators: %d: number of emails sent */
							_n(
								'Test email sent successfully to %d recipient',
								'Test emails sent successfully to %d recipients',
								$sent_count,
								'doublescale'
							),
							$sent_count
						),
						'sent_count' => $sent_count,
					),
					200
				);
			} elseif ( $sent_count > 0 && $failed_count > 0 ) {
				return new WP_REST_Response(
					array(
						'success'       => true,
						'message'       => sprintf(
							/* translators: 1: number of successful sends, 2: number of failures */
							__( 'Test emails sent: %1$d succeeded, %2$d failed', 'doublescale' ),
							$sent_count,
							$failed_count
						),
						'sent_count'    => $sent_count,
						'failed_count'  => $failed_count,
						'failed_emails' => $failed_emails,
					),
					200
				);
			} else {
				return new WP_Error(
					'send_failed',
					sprintf(
						/* translators: %s: comma-separated list of failed email addresses */
						__( 'Failed to send test email to: %s', 'doublescale' ),
						implode( ', ', $failed_emails )
					),
					array( 'status' => 500 )
				);
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Send test provider message for Sms/Whatsapp
	 *
	 * @param WP_REST_Request $request
	 * @param string          $channel
	 * @return WP_REST_Response|WP_Error
	 */
	private function send_test_provider_message( $request, $channel ) {
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

			if ( empty( $message ) ) {
				return new WP_Error(
					'missing_message',
					__( 'Message content is required', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// Get provider for this channel
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

			// Process merge tags
			$processed_message = $this->process_merge_tags( $message, $contact );

			// Add opt-out footer for Sms test messages (match campaign behavior)
			if ( $channel === CampaignChannel::STR_SMS ) {
				$footer_text        = apply_filters( 'doublescale_message_opt_out_footer', __( 'Reply STOP to unsubscribe', 'doublescale' ), $channel );
				$processed_message .= "\n\n" . $footer_text;
			}

			// Prepare message data
			$message_data = array(
				'To'   => $phone,
				'Body' => $processed_message,
			);

			// Add webhook URL for tracking (skip for non-routable URLs like localhost)
			$webhooks_enabled = apply_filters( 'doublescale_provider_webhooks_enable', true );
			$webhook_url      = $provider->get_webhook_url( $channel );
			if ( $webhook_url && $webhooks_enabled && \DoubleScale\Modules\Campaigns\Abstracts\AbstractCampaignProcessing::is_publicly_reachable_url( $webhook_url ) ) {
				$message_data['StatusCallback'] = $webhook_url;
			}

			// Send message using provider
			$result = $provider->send_message( $channel, $message_data, $contact ?? new ContactModel() );

			// Handle result
			if ( ! isset( $result['success'] ) || ! $result['success'] ) {
				$error_message = $result['error'] ?? __( 'Failed to send test message', 'doublescale' );

				// Check for provider error details
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

			$channel_upper = strtoupper( $channel );

			return new WP_REST_Response(
				/* translators: %s: channel name in uppercase (e.g., Sms, WHATSAPP) */
				array( 'message' => sprintf( __( 'Test %s sent successfully', 'doublescale' ), $channel_upper ) ),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get default test email content
	 *
	 * @return string
	 */
	private function get_default_test_email_content() {
		$greeting = sprintf(
			/* translators: %1$s: first name merge tag, %2$s: last name merge tag */
			__( 'Hi %1$s %2$s,', 'doublescale' ),
			'{{contact:first_name}}',
			'{{contact:last_name}}'
		);
		$thank_you       = __( 'Thank you for subscribing to our updates.', 'doublescale' );
		$unsubscribe_msg = __( "Don't want to stay in the loop? We'll be sad to see you go, but you can click here to", 'doublescale' );
		$unsubscribe_txt = __( 'unsubscribe', 'doublescale' );

		$default_content = '<div><p>' . esc_html( $greeting ) . '</p><p>' . esc_html( $thank_you ) . '</p><p>' . esc_html( $unsubscribe_msg ) . ' <a href="{{contact:unsubscribe_link}}" target="_blank">' . esc_html( $unsubscribe_txt ) . '</a>.</p></div>';

		return apply_filters( 'doublescale_default_test_mail_content', $default_content );
	}

	/**
	 * Bulk delete campaigns (cross-type)
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_delete( $request ) {
		try {
			$campaign_ids = $request->get_param( 'ids' );

			if ( empty( $campaign_ids ) || ! is_array( $campaign_ids ) ) {
				return new WP_Error(
					'invalid_ids',
					__( 'Invalid campaign IDs provided', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// Get campaigns to verify they exist
			$campaigns = CampaignModel::whereIn( 'id', $campaign_ids )->get();

			if ( $campaigns->isEmpty() ) {
				return new WP_Error(
					'campaigns_not_found',
					__( 'No campaigns found with the provided IDs', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			// Delete campaigns (works across all types: email, sms, whatsapp)
			CampaignModel::destroy( $campaign_ids );

			return new WP_REST_Response(
				array(
					'deleted' => count( $campaign_ids ),
					'message' => __( 'Campaigns deleted successfully', 'doublescale' ),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Permission check for bulk delete
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function bulk_delete_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Resend a single message
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_message( $request ) {
		try {
			$campaign_id = (int) $request->get_param( 'id' );
			$message_id  = (int) $request->get_param( 'message_id' );

			// Get campaign
			$campaign = CampaignModel::find( $campaign_id );
			if ( ! $campaign ) {
				return new WP_Error(
					'campaign_not_found',
					__( 'Campaign not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			// Get tracking entry
			$tracking = CommunicationTrackingModel::find( $message_id );
			if ( ! $tracking ) {
				return new WP_Error(
					'message_not_found',
					__( 'Message not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			// Verify the message belongs to this campaign
			if ( (int) $tracking->source_id !== $campaign_id || (int) $tracking->source_type !== MessageSourceTypes::CAMPAIGN ) {
				return new WP_Error(
					'invalid_message',
					__( 'Message does not belong to this campaign', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// Get contact
			$contact = $tracking->contact;
			if ( ! $contact ) {
				return new WP_Error(
					'contact_not_found',
					__( 'Contact not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			// Get the appropriate processor based on campaign type
			// Use get_type() to get integer value for comparison
			$campaign_type = $campaign->get_type();
			$processor     = null;

			switch ( $campaign_type ) {
				case CampaignChannel::CHANNEL_EMAIL:
					$processor = EmailProcessing::instance();
					break;
				case CampaignChannel::CHANNEL_SMS:
					if ( class_exists( \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::class ) ) {
						$processor = \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::instance();
					} else {
						return new WP_Error(
							'pro_feature_required',
							__( 'Sms campaigns require Plugin Pro', 'doublescale' ),
							array( 'status' => 403 )
						);
					}
					break;
				case CampaignChannel::CHANNEL_WHATSAPP:
					// WhatsApp processing is only available in Pro version
					if ( class_exists( '\DoubleScale\Modules\Campaigns\Campaign\WhatsappProcessing' ) ) {
						$processor = \DoubleScale\Modules\Campaigns\Campaign\WhatsappProcessing::instance();
					} else {
						return new WP_Error(
							'pro_feature_required',
							__( 'Whatsapp campaigns require Plugin Pro', 'doublescale' ),
							array( 'status' => 403 )
						);
					}
					break;
				default:
					return new WP_Error(
						'invalid_campaign_type',
						__( 'Invalid campaign type', 'doublescale' ),
						array( 'status' => 400 )
					);
			}

			// Use the processor's resend_single_message method
			$processor->resend_single_message( $campaign, $contact, $tracking );

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Message queued for resending', 'doublescale' ),
				)
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'resend_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get campaign unsubscribes
	 *
	 * Returns contacts who unsubscribed after receiving this campaign
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign_unsubscribes( $request ) {
		try {
			$campaign_id = $request->get_param( 'id' );
			$per_page    = $request->get_param( 'per_page' ) ?: 10;
			$page        = $request->get_param( 'page' ) ?: 1;
			$keywords    = $request->get_param( 'keywords' ) ?: '';
			$run_batch   = $request->get_param( 'run_batch' ) ?: '';

			// Get campaign to determine channel type
			$campaign = CampaignModel::find( $campaign_id );
			if ( ! $campaign ) {
				return new WP_Error( 'not_found', __( 'Campaign not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Determine mode integer based on campaign type
			$mode = 1; // Default to email
			if ( $campaign->is_sms_campaign() ) {
				$mode = 2;
			} elseif ( $campaign->is_whatsapp_campaign() ) {
				$mode = 3;
			}

			// Query unsubscribes table for this campaign
			$query = ContactUnsubscribeModel::forCampaign( $campaign_id )
				->forMode( $mode )
				->with( 'contact' );

			// Filter by run_batch: only unsubscribes from contacts in this specific batch
			if ( ! empty( $run_batch ) ) {
				$tracking_table = ( new CommunicationTrackingModel() )->getTable();
				$unsub_table    = ( new ContactUnsubscribeModel() )->getTable();

				$batch_contact_ids = CommunicationTrackingModel::query()
					->where( 'source_type', MessageSourceTypes::CAMPAIGN )
					->where( 'source_id', $campaign_id )
					->whereRaw( "DATE_FORMAT({$tracking_table}.sent_at, '%Y-%m-%d %H:%i') = ?", array( $run_batch ) )
					->pluck( 'contact_id' )
					->toArray();

				$query->whereIn( "{$unsub_table}.contact_id", $batch_contact_ids );
			}

			// Apply keyword search if provided
			if ( ! empty( $keywords ) ) {
				$query->whereHas(
					'contact',
					function ( $q ) use ( $keywords ) {
						$q->where(
							function ( $query ) use ( $keywords ) {
								$query->where( 'email', 'LIKE', '%' . $keywords . '%' )
									->orWhere( 'first_name', 'LIKE', '%' . $keywords . '%' )
									->orWhere( 'last_name', 'LIKE', '%' . $keywords . '%' )
									->orWhere( 'phone', 'LIKE', '%' . $keywords . '%' );
							}
						);
					}
				);
			}

			$results = $query->paginate( $per_page, array( '*' ), 'page', $page );

			// Transform data to include reason and created_at
			if ( isset( $results['data'] ) && is_array( $results['data'] ) ) {
				foreach ( $results['data'] as $unsubscribe ) {
					if ( $unsubscribe->contact ) {
						$unsubscribe->contact->unsubscribe_reason = $unsubscribe->reason;
						$unsubscribe->contact->unsubscribed_at    = $unsubscribe->created_at;
					}
				}
			}

			return new WP_REST_Response( $results, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
