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
use DoubleScale\Modules\Campaigns\Services\CampaignResender;
use DoubleScale\Modules\Campaigns\Services\CampaignStatusManager;
use DoubleScale\Modules\Campaigns\Services\ChannelAvailability;
use DoubleScale\Modules\Campaigns\Services\ChannelEngineRegistry;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;
use DoubleScale\Modules\Emails\EmailAttachmentResolver;
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
use DoubleScale\Modules\Emails\EmailTrackingHelper;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\PluginKernel;


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
						'status'     => array(
							'description' => __( 'Message status filter', 'doublescale' ),
							'type'        => 'string',
							'enum'        => array( 'all', 'sent', 'opened', 'clicked', 'failed', 'pending', 'delivered', 'read', 'scheduled' ),
							'required'    => false,
						),
						'error_code' => array(
							'description' => __( 'Filter failed messages by structured error_info code', 'doublescale' ),
							'type'        => 'string',
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

		// Retry all failed messages (Retry Sending on the campaign overview).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/resend',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resend_failed_messages' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id'         => array(
							'description' => __( 'Campaign ID', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'error_code' => array(
							'description' => __( 'Retry only failed messages with this error_info code', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);

		// Rename at any status. The name is internal, but update_item() only
		// accepts drafts, so a sent campaign could never be renamed.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/rename',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rename_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id'   => array(
							'description' => __( 'Campaign ID', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'name' => array(
							'description'       => __( 'New campaign name', 'doublescale' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Resume a campaign paused because Meta restricted the sending number.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/resume',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resume_campaign' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Campaign ID', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
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

		// How many of the filtered contacts this channel can actually reach.
		//
		// The Recipients step counted through the generic contacts endpoint,
		// which knows nothing about the channel. A WhatsApp campaign then
		// promised the whole matched audience while the send path quietly
		// dropped everyone without a `whatsapp_phone`.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reachable-recipients',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_reachable_recipients' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'channel' => array(
							'description' => __( 'Campaign channel', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => CampaignChannel::get_core_channel_strings(),
						),
						'filters' => array(
							'description' => __( 'Recipient filter groups', 'doublescale' ),
							'type'        => 'array',
						),
					),
				),
			)
		);

		// Undo a schedule: the operator changed their mind before it ran.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/cancel-schedule',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_campaign_schedule' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Campaign ID', 'doublescale' ),
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
						'channel'            => array(
							'description' => __( 'Channel type', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => CampaignChannel::get_core_channel_strings(),
						),
						// Email parameters.
						'email'              => array(
							'description' => __( 'Email address (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'subject'            => array(
							'description' => __( 'Email subject (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'from_name'          => array(
							'description' => __( 'From name (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'from_email'         => array(
							'description' => __( 'From email (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						'reply_to'           => array(
							'description' => __( 'Reply-to email (for email channel)', 'doublescale' ),
							'type'        => 'string',
						),
						// Sms/Whatsapp parameters.
						'phone'              => array(
							'description' => __( 'Phone number (for Sms/Whatsapp channels)', 'doublescale' ),
							'type'        => 'string',
						),
						// Whatsapp parameters. Whatsapp only sends pre-approved
						// business templates, so the body comes from the template
						// rather than from operator-typed text.
						'template_id'        => array(
							'description' => __( 'Approved template ID (for Whatsapp channel)', 'doublescale' ),
							'type'        => 'integer',
						),
						'template_variables' => array(
							'description' => __( 'Template variable mappings keyed by slot (for Whatsapp channel)', 'doublescale' ),
							'type'        => 'object',
						),
						// Common parameter.
						//
						// Deliberately not `required`: a Whatsapp test carries a
						// template instead of a body, and a blanket requirement
						// rejected it during argument validation, before the
						// handler could explain anything. Each channel enforces
						// its own content requirement below.
						'message'            => array(
							'description' => __( 'Message content (body for email, message for Sms; Whatsapp uses template_id)', 'doublescale' ),
							'type'        => 'string',
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
						'body'   => array(
							'description' => __( 'Optional live builder JSON. When present, the test is rendered from this instead of the last saved template.', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
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

		// A channel may be created only when it can actually deliver. This is
		// the same predicate send_via_provider() enforces, so the gate and the
		// send path cannot disagree — a campaign that passes here will not
		// discover at send time that no provider was ever connected.
		$valid_campaign_channels = ChannelAvailability::sendable_campaign_channels();

		if ( ! in_array( $type, $valid_campaign_channels, true ) ) {
			// Distinguish "this channel exists but is not set up" from "this is
			// not a channel". The first is actionable by the user; conflating
			// them is what left WhatsApp looking permanently unavailable.
			if ( in_array( $type, CampaignChannel::get_core_channel_strings(), true ) ) {
				// Connecting a provider cannot help when nothing runs the
				// channel; say which plugin to install or update instead.
				if ( ! ChannelAvailability::has_engine( $type ) ) {
					return ChannelAvailability::engine_unavailable_error( $type );
				}

				return new WP_Error(
					"{$type}_provider_not_configured",
					sprintf(
						/* translators: %s: channel name, e.g. "whatsapp". */
						__( 'No %s provider is connected. Connect one in Settings → Integrations before creating this campaign.', 'doublescale' ),
						$type
					),
					array( 'status' => 403 )
				);
			}

			return new WP_Error(
				'invalid_type',
				sprintf(
					/* translators: %s: comma-separated list of valid campaign types. */
					__( 'Invalid campaign type. Must be one of: %s.', 'doublescale' ),
					implode( ', ', $valid_campaign_channels )
				),
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
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
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

		$engine = ChannelEngineRegistry::get( $channel );

		if ( $channel === CampaignChannel::STR_SMS && ! $engine ) {
			return new WP_Error(
				'pro_feature_required',
				__( 'Sms campaigns require Plugin Pro', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		// An engine that brings its own test send — Pro's WhatsApp engine —
		// runs it, so the send logic lives with the engine that owns the
		// channel. Every other engine keeps the provider path below.
		if ( $engine && method_exists( $engine, 'send_test_message' ) ) {
			return $engine->send_test_message( $request );
		}

		return $this->send_test_provider_message( $request, $channel );
	}

	/**
	 * A test send has no triggering post: point the "Last Post" merge tags at the
	 * latest published post, so the test shows a real example.
	 *
	 * @return bool Whether a post was set (and end_test_post() must clear it).
	 */
	private function begin_test_post() {
		$merge_tags = MergeTagsManager::instance();
		if ( $merge_tags->get_current_post_id() ) {
			return false;
		}
		$latest = get_posts(
			array(
				'numberposts' => 1,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);
		if ( ! $latest ) {
			return false;
		}
		$merge_tags->set_current_post_id( (int) $latest[0] );
		return true;
	}

	/**
	 * Clear the post begin_test_post() set.
	 *
	 * @param bool $was_set What begin_test_post() returned.
	 * @return void
	 */
	private function end_test_post( $was_set ) {
		if ( $was_set ) {
			MergeTagsManager::instance()->set_current_post_id( null );
		}
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
			$attachment_paths = array();
			if ( $request->get_param( 'attachments' ) ) {
				$attachment_paths = EmailAttachmentResolver::resolve_paths(
					(array) $request->get_param( 'attachments' )
				);
			} else {
				$attachment_paths = EmailAttachmentResolver::extract_from_builder_body( $body );
				$attachment_paths = EmailAttachmentResolver::resolve_paths( $attachment_paths );
			}

			$contact = ContactModel::get_by_email( $email ) ?? null;

			$test_post = $this->begin_test_post();
			try {
				$subject      = $this->process_merge_tags( $subject, $contact );
				$body_content = $this->process_merge_tags( $for_testing_body, $contact );
			} finally {
				$this->end_test_post( $test_post );
			}
			$body_content = EmailTrackingHelper::prepare_test_email_body( $body_content, $email, $contact );

			$result = $emails->send(
				$email,
				$subject,
				$body_content,
				$attachment_paths
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
			$test_post = $this->begin_test_post();
			try {
				foreach ( $emails as $recipient_email ) {
					$email_sender               = new Emails();
					$email_sender->from_address = $from_email;
					$email_sender->from_name    = $from_name;
					if ( ! empty( $reply_to ) && is_email( $reply_to ) ) {
						$email_sender->reply_to = $reply_to;
					}

					// Get contact for merge tags
					$contact = ContactModel::get_by_email( $recipient_email ) ?? null;

					// Prefer the live builder JSON from the request (what the canvas
					// is showing, including Theme → Link Style). Fall back to the
					// saved template so older clients still work.
					$email_renderer = new EmailRenderer();
					$live_body      = $request->get_param( 'body' );
					$body_content   = '';
					if ( ! empty( $live_body ) ) {
						$decoded = is_array( $live_body ) ? $live_body : json_decode( (string) $live_body, true );
						if ( is_array( $decoded ) ) {
							if ( isset( $decoded['type'] ) && 'builder' === $decoded['type'] && isset( $decoded['value'] ) ) {
								$decoded = $decoded['value'];
							}
							if ( isset( $decoded['sections'] ) ) {
								$body_content = $email_renderer->render_from_builder_data( $decoded, $contact );
							}
						}
					}
					if ( '' === $body_content ) {
						$body_content = $email_renderer->render_template( $template_id, $contact );
					}

					if ( empty( $body_content ) ) {
						++$failed_count;
						$failed_emails[] = $recipient_email;
						continue;
					}

					// Process subject with merge tags
					$processed_subject = MergeTagsManager::instance()->process_merge_tags( $subject, $contact );
					$body_content      = EmailTrackingHelper::prepare_test_email_body( $body_content, $recipient_email, $contact );

					$attachment_paths = EmailAttachmentResolver::resolve_template_paths( $template );

					// Send email
					$result = $email_sender->send( $recipient_email, $processed_subject, $body_content, $attachment_paths );

					if ( $result ) {
						++$sent_count;
					} else {
						++$failed_count;
						$failed_emails[] = $recipient_email;
					}
				}
			} finally {
				$this->end_test_post( $test_post );
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

			// Whatsapp is template-driven: Meta only accepts pre-approved
			// business templates for an outbound test, so there is no body for
			// the operator to type. Resolve the body from the selected template
			// and let the template's ContentSid reach the provider.
			$template          = null;
			$template_settings = array();

			if ( $channel === CampaignChannel::STR_WHATSAPP ) {
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

			// Get provider for this channel. The registry ships with Pro, so a
			// free-only install must report a missing provider rather than
			// fatal on an undefined class.
			$provider = class_exists( MessageProviderRegistry::class )
				? MessageProviderRegistry::instance()->get_provider( $channel )
				: null;

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

			// Whatsapp sends the approved template, not the body. Mirror the
			// campaign send path so a test exercises the same provider contract:
			// ContentSid plus the per-slot variables, and the header/button
			// definition for media and interactive templates.
			if ( $channel === CampaignChannel::STR_WHATSAPP && $template ) {
				$variable_mappings = $request->get_param( 'template_variables' );

				if ( ! is_array( $variable_mappings ) || empty( $variable_mappings ) ) {
					$variable_mappings = $template->get_whatsapp_variable_mappings();
				}

				$content_variables = array();
				foreach ( $variable_mappings as $slot => $value ) {
					$content_variables[ (string) $slot ] = $this->process_merge_tags( $value, $contact );
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
			// Every channel; a Campaign Agent's own campaigns only.
			$campaigns = $this->get_campaign_query()->whereIn( 'id', $campaign_ids )->get();

			if ( $campaigns->isEmpty() ) {
				return new WP_Error(
					'campaigns_not_found',
					__( 'No campaigns found with the provided IDs', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			// Delete campaigns (works across all types: email, sms, whatsapp)
			$found = $campaigns->pluck( 'id' )->map( 'intval' )->all();
			CampaignModel::destroy( $found );

			return new WP_REST_Response(
				array(
					'deleted' => count( $found ),
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
		return $this->campaign_permission( $request );
	}

	/**
	 * Retry every failed message in a campaign.
	 *
	 * Sets status to `resending` so the existing campaign processor picks the
	 * failures up. This must not go through update_item(): that endpoint
	 * rejects any write unless the campaign is still a draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Resume a campaign paused because Meta restricted the sending number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume_campaign( $request ) {
		$campaign = \DoubleScale\Modules\Campaigns\Services\WhatsappAccountPause::resume( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => (string) $campaign->status,
			),
			200
		);
	}

	/**
	 * Count how many filtered contacts this channel can actually reach.
	 *
	 * Deliberately built on CampaignContactFilter — the same class the send
	 * pipeline uses — so the number the Recipients step shows and the number
	 * that receives a message cannot drift apart.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_reachable_recipients( $request ) {
		try {
			$channel = (string) $request->get_param( 'channel' );
			$filters = $request->get_param( 'filters' );
			$filters = is_array( $filters ) ? $filters : array();

			$filter = new \DoubleScale\Modules\Campaigns\Services\CampaignContactFilter();

			// Reachable: subscribed on this channel AND carrying the channel's
			// recipient field.
			// A Campaign Agent's audience is the contacts they own.
			$owner     = Permissions::campaign_audience_owner();
			$reachable = (int) $filter->get_filtered_contacts( $channel, $filters, $owner )->count();

			// Matched: the same audience without the recipient-field gate, so
			// the difference is exactly what the send path would skip.
			$matched = (int) $filter->count_matching_contacts( $channel, $filters, $owner );

			return rest_ensure_response(
				array(
					'channel'   => $channel,
					'matched'   => $matched,
					'reachable' => $reachable,
					'skipped'   => max( 0, $matched - $reachable ),
				)
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'reachable_count_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Cancel a scheduled campaign, returning it to draft.
	 *
	 * The screen offered a "Cancel Schedule" button that only navigated away,
	 * so a campaign the operator thought they had stopped still sent on time.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_campaign_schedule( $request ) {
		try {
			$campaign = CampaignModel::find( (int) $request->get_param( 'id' ) );

			if ( ! $campaign ) {
				return new WP_Error(
					'campaign_not_found',
					__( 'Campaign not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			// Only a pending schedule can be undone. A send already running is
			// stopped by pausing it, not by rewinding it to draft.
			if ( CampaignStatusManager::SCHEDULED !== $campaign->status ) {
				return new WP_Error(
					'campaign_not_scheduled',
					__( 'Only a scheduled campaign can have its schedule cancelled.', 'doublescale' ),
					array( 'status' => 409 )
				);
			}

			$campaign->status = CampaignStatusManager::DRAFT;
			// Clear the time too: a stale execute_at would re-arm the campaign
			// the moment anything set it back to scheduled.
			$campaign->execute_at = null;
			$campaign->save();

			return rest_ensure_response(
				array(
					'success' => true,
					'status'  => CampaignStatusManager::DRAFT,
					'message' => __( 'Schedule cancelled. The campaign is back in draft.', 'doublescale' ),
				)
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'cancel_schedule_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Rename a campaign, whatever its status.
	 *
	 * Only the name changes — status, settings and schedule are untouched, so
	 * this is safe on a campaign that is sending or already sent.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rename_item( $request ) {
		$name = trim( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new WP_Error(
				'invalid_campaign_name',
				__( 'Campaign name is required', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// doublescale_campaigns.name is varchar(255).
		if ( mb_strlen( $name ) > 255 ) {
			return new WP_Error(
				'invalid_campaign_name',
				__( 'Campaign name must be 255 characters or fewer', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		try {
			$campaign = CampaignModel::find( (int) $request->get_param( 'id' ) );

			if ( ! $campaign ) {
				return new WP_Error(
					'campaign_not_found',
					__( 'Campaign not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			$campaign->name = $name;
			$campaign->save();

			return rest_ensure_response(
				array(
					'id'   => (int) $campaign->id,
					'name' => $campaign->name,
				)
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'rename_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function resend_failed_messages( $request ) {
		try {
			$campaign_id = (int) $request->get_param( 'id' );
			$campaign    = CampaignModel::find( $campaign_id );

			if ( ! $campaign ) {
				return new WP_Error(
					'campaign_not_found',
					__( 'Campaign not found', 'doublescale' ),
					array( 'status' => 404 )
				);
			}

			$channel = is_numeric( $campaign->type )
				? CampaignChannel::to_string( (int) $campaign->type )
				: (string) $campaign->type;

			// Pin the channel to THIS campaign. get_campaign_message_query()
			// filters on $this->channel, which is controller state left over
			// from whichever request touched it last — so an email retry that
			// followed a WhatsApp request counted WhatsApp rows and reported
			// "no failed messages" for a campaign that plainly had some.
			$this->channel = $channel;

			$error_code   = sanitize_text_field( (string) $request->get_param( 'error_code' ) );
			$failed_query = $this->get_campaign_message_query( $campaign_id );
			$this->apply_message_status_filter( $failed_query, 'failed' );
			if ( '' !== $error_code ) {
				$this->apply_error_code_filter( $failed_query, $error_code );
			}
			$failed_count = (int) $failed_query->count();

			if ( $failed_count < 1 ) {
				return new WP_Error(
					'no_failed_messages',
					__( 'No failed messages to resend', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// A retry is a send. Creating this campaign was gated on a provider
			// that can deliver, and so is a single-message resend — but bulk
			// retry checked nothing, so an unconfigured site happily queued the
			// whole batch to fail again. Check before touching the status, so a
			// refused retry leaves the campaign exactly as it was instead of
			// stranding it in `resending`.
			if ( $channel && ! ChannelAvailability::is_channel_sendable( $channel ) ) {
				if ( ! ChannelAvailability::has_engine( $channel ) ) {
					return ChannelAvailability::engine_unavailable_error( $channel );
				}

				return new WP_Error(
					"{$channel}_provider_not_configured",
					sprintf(
						/* translators: %s: channel name, e.g. "whatsapp". */
						__( 'No %s provider is connected. Connect one in Settings → Integrations before retrying these messages.', 'doublescale' ),
						$channel
					),
					// 409, not 403: a fixable state conflict, not a licensing wall.
					array( 'status' => 409 )
				);
			}

			// Meta's per-user marketing cap (131049): those recipients are
			// skipped by the resend for a day. Count them the same way here,
			// so a click that could only reach capped recipients is refused
			// with the reason instead of flipping the campaign to "resending"
			// and sending nothing.
			$retryable_query = clone $failed_query;
			CampaignResender::exclude_recipients_in_cooldown( $retryable_query, (string) $channel );
			$retryable_count = (int) $retryable_query->count();
			$held_back       = max( 0, $failed_count - $retryable_count );

			if ( $retryable_count < 1 && $held_back > 0 ) {
				$release = CampaignResender::first_cooldown_release( $campaign_id );

				return new WP_Error(
					'recipients_in_cooldown',
					sprintf(
						/* translators: 1: number of messages, 2: human time difference, e.g. "20 hours". */
						_n(
							'%1$d failed message was held back by Meta\'s per-user marketing limit — the recipient has had too many marketing messages lately, from any business. Retrying now would fail the same way; it can be retried in about %2$s.',
							'%1$d failed messages were held back by Meta\'s per-user marketing limit — the recipients have had too many marketing messages lately, from any business. Retrying now would fail the same way; they can be retried in about %2$s.',
							$held_back,
							'doublescale'
						),
						$held_back,
						human_time_diff( time(), $release ? $release : time() + DAY_IN_SECONDS )
					),
					array(
						'status'     => 409,
						'held_back'  => $held_back,
						'retry_from' => $release ? gmdate( 'Y-m-d H:i:s', $release ) : null,
					)
				);
			}

			if ( CampaignStatusManager::RESENDING !== $campaign->status ) {
				$allowed = array(
					CampaignStatusManager::COMPLETED,
					CampaignStatusManager::FAILED,
					CampaignStatusManager::ACTIVE,
				);

				if ( ! in_array( $campaign->status, $allowed, true ) ) {
					return new WP_Error(
						'invalid_campaign_status',
						__( 'This campaign cannot retry sending in its current status.', 'doublescale' ),
						array( 'status' => 400 )
					);
				}

				$campaign->status = CampaignStatusManager::RESENDING;
				$campaign->save();
			}

			$scope_key = 'doublescale_resend_error_code_' . $campaign_id;
			if ( '' !== $error_code ) {
				update_option( $scope_key, $error_code );
			} else {
				delete_option( $scope_key );
			}
			delete_transient( 'doublescale_failure_causes_' . $campaign_id );

			if ( $channel ) {
				PluginKernel::instance()->campaigns_tasks->enqueue_async( "doublescale_{$channel}_campaigns" );
			}

			return rest_ensure_response(
				array(
					'success'      => true,
					'status'       => CampaignStatusManager::RESENDING,
					'failed_count' => (int) $failed_count,
					'held_back'    => (int) $held_back,
					'message'      => $held_back > 0
						? sprintf(
							/* translators: 1: messages being retried, 2: messages held back. */
							__( 'Retrying %1$d failed messages. %2$d more were held back by Meta\'s per-user marketing limit and can be retried later.', 'doublescale' ),
							$retryable_count,
							$held_back
						)
						: __( 'Resend process initiated. All failed messages will be retried automatically.', 'doublescale' ),
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
	 * Seconds left before a recipient Meta capped may be messaged again.
	 *
	 * @param \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel $tracking Failed message.
	 * @return int Zero when nothing is holding it back.
	 */
	private function recipient_cooldown_remaining( $tracking ) {
		$cooldown = \DoubleScale\Modules\Campaigns\Services\WhatsappErrorCodes::recipient_cooldown_seconds();
		if ( $cooldown <= 0 || ! $tracking || CommunicationTrackingModel::MODE_WHATSAPP !== (int) $tracking->mode ) {
			return 0;
		}

		$info = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::get_error_info( (int) $tracking->id );
		$info = is_string( $info ) ? json_decode( $info, true ) : $info;
		$code = is_array( $info ) ? ( $info['code'] ?? '' ) : '';

		if ( ! \DoubleScale\Modules\Campaigns\Services\WhatsappErrorCodes::is_recipient_cooldown( $code ) ) {
			return 0;
		}

		$last = strtotime( (string) $tracking->updated_at . ' UTC' );

		return $last ? max( 0, ( $last + $cooldown ) - time() ) : 0;
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
					$processor = ChannelEngineRegistry::get( CampaignChannel::STR_SMS );
					if ( ! $processor ) {
						return ChannelAvailability::engine_unavailable_error( CampaignChannel::STR_SMS );
					}
					break;
				case CampaignChannel::CHANNEL_WHATSAPP:
					// The engine that owns WhatsApp on this site — Pro's copy once
					// it ships one, free's otherwise. None at all means a free that
					// no longer ships the engine next to a Pro that predates it;
					// connecting a provider would not help, so say so first.
					$processor = ChannelEngineRegistry::get( CampaignChannel::STR_WHATSAPP );
					if ( ! $processor ) {
						return ChannelAvailability::engine_unavailable_error( CampaignChannel::STR_WHATSAPP );
					}
					// The old guard here tested class_exists() on
					// WhatsappProcessing and returned "requires Pro" otherwise.
					// That class ships in FREE, so the check was always true
					// and the error branch unreachable — and its message was
					// wrong anyway. What a resend actually needs is a provider
					// that can deliver, which is what this asks.
					if ( ! ChannelAvailability::is_channel_sendable( CampaignChannel::STR_WHATSAPP ) ) {
						return new WP_Error(
							'whatsapp_provider_not_configured',
							__( 'No WhatsApp provider is connected. Connect one in Settings → Integrations to resend these messages.', 'doublescale' ),
							// 409, not 403: this is a fixable state conflict,
							// not a licensing wall.
							array( 'status' => 409 )
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

			// Meta's per-user marketing cap is about the recipient, not the
			// account: resending inside their window fails the same way and
			// only costs quality. Say so instead of queueing a certain fail.
			$cooldown = $this->recipient_cooldown_remaining( $tracking );
			if ( $cooldown > 0 ) {
				return new WP_Error(
					'recipient_in_cooldown',
					sprintf(
						/* translators: %s: human time difference, e.g. "3 hours". */
						__( 'Meta held this marketing message back because of how many marketing messages this person has already received — from any business. Your number and your daily limit are fine. This contact can be messaged again in about %s.', 'doublescale' ),
						human_time_diff( time(), time() + $cooldown )
					),
					array( 'status' => 409 )
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
