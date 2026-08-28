<?php

/**
 * REST Api: Contact Controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Api
 */

namespace DoubleScale\Modules\Contacts\Rest\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\ListPreferences\ListPreferencesManager;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use Exception;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Core\Logger\Models\LogModel;
use DoubleScale\Modules\Contacts\Models\TagModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use DoubleScale\Modules\Contacts\Filters\Process as Contact_Filters_Process;
use DoubleScale\Modules\Contacts\Services\ContactUpdateNotifier;
use DoubleScale\Modules\Contacts\Services\EmailAttachmentService;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\OrderStatus;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Pro\Modules\LeadScoring\LeadScoringManager;

/**
 * RestContactController is REST api controller class for log
 *
 * @since 1.0.0
 */
class RestContactController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'contacts';

	/**
	 * Columns the contacts list may be sorted by.
	 *
	 * Note the status columns are channel-specific (email_status / sms_status);
	 * there is no single `status` column on this table.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const SORTABLE_COLUMNS = array( 'first_name', 'last_name', 'email', 'phone', 'city', 'country', 'email_status', 'sms_status', 'created_at', 'updated_at' );

	/**
	 * Polymorphic attachable_type for contact file attachments.
	 */
	private const CONTACT_ATTACHABLE_TYPE = 'contact';

	/**
	 * Maximum upload size per contact attachment (10 MB).
	 */
	private const CONTACT_ATTACHMENT_MAX_BYTES = 10485760;

	/**
	 * Maximum active attachments per contact.
	 */
	private const CONTACT_ATTACHMENT_MAX_COUNT = 20;

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'keywords'           => array(
							'description' => __( 'Keyword to search.', 'doublescale' ),
							'type'        => 'string',
						),
						'per_page'           => array(
							'description' => __( 'Number of items to fetch.', 'doublescale' ),
							'type'        => 'integer',
						),
						'page'               => array(
							'description' => __( 'Page number.', 'doublescale' ),
							'type'        => 'integer',
						),
						'filters'            => array(
							'description' => __( 'Filters to apply.', 'doublescale' ),
							'type'        => 'array',
						),
						'subscribed'         => array(
							'description' => __( 'Subscribed contacts.', 'doublescale' ),
							'type'        => 'boolean',
						),
						'campaign_type'      => array(
							'description' => __( 'Campaign type for filtering contacts.', 'doublescale' ),
							'type'        => 'string',
							'enum'        => CampaignChannel::get_core_channel_strings(),
						),
						'has_whatsapp_phone' => array(
							'description' => __( 'Filter contacts by WhatsApp phone presence.', 'doublescale' ),
							'type'        => 'boolean',
						),
					) + $this->get_sorting_collection_params( self::SORTABLE_COLUMNS ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids'   => array(
							'description' => __( 'Contact IDs.', 'doublescale' ),
							'type'        => 'array',
						),
						'force' => array(
							'description' => __( 'Also delete financial records (invoices, contracts, credit notes) tied to these contacts.', 'doublescale' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/deletion-impact',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_deletion_impact' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Contact IDs to inspect before deletion.', 'doublescale' ),
							'type'        => 'array',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/list-preferences',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_list_preferences' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_list_preferences' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'column_visibility' => array(
							'description' => __( 'Visible columns for the contacts list.', 'doublescale' ),
							'type'        => 'object',
						),
						'per_page'          => array(
							'description' => __( 'Items per page for the contacts list.', 'doublescale' ),
							'type'        => 'integer',
						),
						'show_filters'      => array(
							'description' => __( 'Whether the contacts filter panel is open.', 'doublescale' ),
							'type'        => 'boolean',
						),
						'keyword'           => array(
							'description' => __( 'Contacts search keyword.', 'doublescale' ),
							'type'        => 'string',
						),
						'date_range'        => array(
							'description' => __( 'Contacts date range filter.', 'doublescale' ),
							'type'        => 'object',
						),
					),
				),
			)
		);

		// Get contact
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'description' => __( 'Also delete financial records tied to this contact.', 'doublescale' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Get contact notes
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_contact_notes' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id'       => array(
							'description' => __( 'Contact ID.', 'doublescale' ),
							'type'        => 'integer',
						),
						'per_page' => array(
							'description' => __( 'Number of items to fetch.', 'doublescale' ),
							'type'        => 'integer',
						),
						'page'     => array(
							'description' => __( 'Page number.', 'doublescale' ),
							'type'        => 'integer',
						),
					),
				),
			)
		);

		// Send opt-in email
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/send-opt-in',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_opt_in_email' ),
					'permission_callback' => array( $this, 'send_opt_in_email_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Contact ID.', 'doublescale' ),
							'type'        => 'integer',
						),
					),
				),
			)
		);

		// Unified send message endpoint (email, Sms, WhatsApp)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/send-message',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_message' ),
					'permission_callback' => array( $this, 'send_message_permissions_check' ),
					'args'                => array(
						'id'      => array(
							'description' => __( 'Contact ID.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'channel' => array(
							'description' => __( 'Communication channel: email, sms, or whatsapp.', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => CampaignChannel::get_core_channel_strings(),
						),
						'to'      => array(
							'description' => __( 'Recipient (email address or phone number in E.164 format).', 'doublescale' ),
							'type'        => 'string',
							'required'    => true,
						),
						'body'    => array(
							'description' => __( 'Message body (HTML for email, plain text for SMS, ignored for WhatsApp templates).', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
						),
						'subject' => array(
							'description' => __( 'Email subject (required for email, ignored for Sms/Whatsapp).', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
						),
						'deal_id' => array(
							'description' => __( 'Link the resulting activity to this deal.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => false,
						),
						'project_id' => array(
							'description' => __( 'Link the resulting activity to this project.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => false,
						),
					),
				),
			)
		);

		// Get automation contacts
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/automation-contacts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_automation_contacts' ),
					'permission_callback' => array( $this, 'get_automation_contacts_permissions_check' ),
					'args'                => array(
						'id'       => array(
							'description' => __( 'Contact ID.', 'doublescale' ),
							'type'        => 'integer',
						),
						'per_page' => array(
							'description' => __( 'Number of items to fetch.', 'doublescale' ),
							'type'        => 'integer',
						),
						'page'     => array(
							'description' => __( 'Page number.', 'doublescale' ),
							'type'        => 'integer',
						),
					),
				),
			)
		);

		// Get the filters.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/filters',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_filters' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'get_analytics_permissions_check' ),
					'args'                => array(
						'interval'   => array(
							'description' => __( 'Interval for the analytics.', 'doublescale' ),
							'type'        => 'string',
							'enum'        => array( 'custom', 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year' ),
							'required'    => false,
						),
						'start_date' => array(
							'description' => __( 'Start date for the analytics.', 'doublescale' ),
							'type'        => 'string',
							'format'      => 'date',
						),
						'end_date'   => array(
							'description' => __( 'End date for the analytics.', 'doublescale' ),
							'type'        => 'string',
							'format'      => 'date',
						),
					),
				),
			)
		);

		// Add to lists
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/add-to-list',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_to_lists' ),
					'permission_callback' => array( $this, 'add_to_lists_permissions_check' ),
					'args'                => array(
						'ids'      => array(
							'description' => __( 'Contact IDs.', 'doublescale' ),
							'type'        => 'array',
						),
						'list_ids' => array(
							'description' => __( 'Lists to add.', 'doublescale' ),
							'type'        => 'array',
						),
					),
				),
			)
		);

		// Remove from lists
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/remove-from-list',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'remove_from_lists' ),
					'permission_callback' => array( $this, 'remove_from_lists_permissions_check' ),
					'args'                => array(
						'ids'      => array(
							'description' => __( 'Contact IDs.', 'doublescale' ),
							'type'        => 'array',
						),
						'list_ids' => array(
							'description' => __( 'Lists to remove.', 'doublescale' ),
							'type'        => 'array',
						),
					),
				),
			)
		);

		// Add tags
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/add-tag',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_tags' ),
					'permission_callback' => array( $this, 'add_tags_permissions_check' ),
					'args'                => array(
						'ids'     => array(
							'description' => __( 'Contact IDs.', 'doublescale' ),
							'type'        => 'array',
						),
						'tag_ids' => array(
							'description' => __( 'Tags to add.', 'doublescale' ),
							'type'        => 'array',
						),
					),
				),
			)
		);

		// Remove tags
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/remove-tag',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'remove_tags' ),
					'permission_callback' => array( $this, 'remove_tags_permissions_check' ),
					'args'                => array(
						'ids'     => array(
							'description' => __( 'Contact IDs.', 'doublescale' ),
							'type'        => 'array',
						),
						'tag_ids' => array(
							'description' => __( 'Tags to remove.', 'doublescale' ),
							'type'        => 'array',
						),
					),
				),
			)
		);

		// ===================================================================
		// UNIFIED MESSAGES ENDPOINT
		// ===================================================================
		// Get all messages (campaigns + individual) for contact by channel
		// Usage: /contacts/{id}/messages?mode=email (or sms, whatsapp)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_messages' ),
					'permission_callback' => array( $this, 'get_messages_permissions_check' ),
					'args'                => array(
						'id'       => array(
							'description' => __( 'Contact ID.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'mode'     => array(
							'description' => __( 'Message channel: email, sms, or whatsapp.', 'doublescale' ),
							'type'        => 'string',
							'required'    => false,
							'enum'        => CampaignChannel::get_core_channel_strings(),
							'default'     => 'email',
						),
						'per_page' => array(
							'description' => __( 'Number of items to fetch.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 25,
						),
						'page'     => array(
							'description' => __( 'Page number.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 1,
						),
						'activity_id' => array(
							'description' => __( 'Filter individual message by linked activity ID.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => false,
						),
					),
				),
			)
		);

		// Get purchase history
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/purchase-history',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_purchase_history' ),
					'permission_callback' => array( $this, 'get_purchase_history_permissions_check' ),
					'args'                => array(
						'id'                => array(
							'description' => __( 'Contact ID.', 'doublescale' ),
							'type'        => 'integer',
						),
						'woo_page'          => array(
							'description' => __( 'WooCommerce page number.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 1,
						),
						'woo_per_page'      => array(
							'description' => __( 'WooCommerce items per page.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 10,
						),
						'edd_page'          => array(
							'description' => __( 'EDD page number.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 1,
						),
						'edd_per_page'      => array(
							'description' => __( 'EDD items per page.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 10,
						),
						'surecart_page'     => array(
							'description' => __( 'SureCart page number.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 1,
						),
						'surecart_per_page' => array(
							'description' => __( 'SureCart items per page.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 10,
						),
					),
				),
			)
		);

		// Get lms courses
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/lms-courses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_lms_courses' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Contact ID.', 'doublescale' ),
							'type'        => 'integer',
						),
					),
				),
			)
		);

		// Get lead score (Pro feature; route is omitted when the module is
		// unavailable or disabled). class_exists() is called with autoload=true
		// because Pro provides this class lazily via its autoloader.
		if ( class_exists( LeadScoringManager::class, true )
			&& function_exists( 'doublescale_is_module_active' )
			&& doublescale_is_module_active( 'leadscoring' ) ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/lead-score',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_lead_score' ),
						'permission_callback' => array( $this, 'get_item_permissions_check' ),
						'args'                => array(
							'id' => array(
								'description' => __( 'Contact ID.', 'doublescale' ),
								'type'        => 'integer',
							),
						),
					),
				)
			);
		}

		// Contact file attachments.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_attachments' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_attachment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments/(?P<attachment_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_attachment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Schema for the contact
	 *
	 * @since 1.0.0
	 *
	 * @return array $schema The contact schema
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'contact',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'first_name'      => array(
					'description'  => __( 'First name of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'last_name'       => array(
					'description'  => __( 'Last name of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'company_name'    => array(
					'description'  => __( 'Company or organization name.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'company_registration_number' => array(
					'description'  => __( 'Company registration number.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'tax_vat_number'  => array(
					'description'  => __( 'Tax or VAT identification number.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'avatar_id'       => array(
					'description'  => __( 'WordPress media attachment ID for the contact profile image.', 'doublescale' ),
					'type'         => 'integer',
					'arg_options' => array(
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							if ( is_null( $value ) || '' === $value || 0 === (int) $value ) {
								return true;
							}

							$attachment_id = absint( $value );
							if ( ! get_post( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
								return new WP_Error(
									'rest_invalid_param',
									__( 'Invalid avatar image attachment.', 'doublescale' ),
									array( 'status' => 400 )
								);
							}

							return true;
						},
					),
				),
				'avatar_url'      => array(
					'description' => __( 'Profile image URL.', 'doublescale' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'email'           => array(
					'description'  => __( 'Email of the contact (optional when a phone number is provided).', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_email',
					),
				),
				'phone'           => array(
					'description'  => __( 'Phone number of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'whatsapp_phone'  => array(
					'description'  => __( 'Whatsapp phone number of the contact in E.164 format (e.g., +12025551234).', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value, $request, $param ) {
							// Allow empty values (null, empty string, or false)
							if ( is_null( $value ) || $value === '' || $value === false ) {
								return true;
							}
							// Validate E.164 format if value is provided
							if ( ! preg_match( '/^\+[0-9]{1,15}$/', $value ) ) {
								return new WP_Error(
									'rest_invalid_param',
									/* translators: %s: field name (phone, mobile, etc.) */
									sprintf( __( '%s must be in E.164 format (e.g., +12025551234)', 'doublescale' ), $param ),
									array( 'status' => 400 )
								);
							}
							return true;
						},
					),
				),
				'address_1'       => array(
					'description'  => __( 'Address line 1 of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'address_2'       => array(
					'description'  => __( 'Address line 2 of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'city'            => array(
					'description'  => __( 'City of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'state'           => array(
					'description'  => __( 'State of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'country'         => array(
					'description'  => __( 'Country of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'zip'             => array(
					'description'  => __( 'Zip code of the contact.', 'doublescale' ),
					'type'         => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'email_status'    => array(
					'description'  => __( 'Email subscription status.', 'doublescale' ),
					'type'         => 'string',
					'enum'         => array( 'subscribed', 'unsubscribed', 'bounced', 'blocked', 'unverified' ),
					'default'      => 'subscribed',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'sms_status'      => array(
					'description'  => __( 'Sms subscription status.', 'doublescale' ),
					'type'         => 'string',
					'enum'         => array( 'subscribed', 'unsubscribed', 'blocked' ),
					'default'      => 'subscribed',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'whatsapp_status' => array(
					'description'  => __( 'Whatsapp subscription status.', 'doublescale' ),
					'type'         => 'string',
					'enum'         => array( 'subscribed', 'unsubscribed', 'blocked' ),
					'default'      => 'subscribed',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'created_at'      => array(
					'type'        => 'string',
					'description' => 'Created at',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'updated_at'      => array(
					'type'        => 'string',
					'description' => 'Updated at',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get lms courses
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_lms_courses( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			$is_learndash_active  = doublescale_is_plugin_active( 'sfwd-lms/sfwd_lms.php' );
			$is_tutor_active      = doublescale_is_plugin_active( 'tutor/tutor.php' );
			$is_lifterlms_active  = doublescale_is_plugin_active( 'lifterlms/lifterlms.php' );
			$is_learnpress_active = doublescale_is_plugin_active( 'learnpress/learnpress.php' );

			if ( ! $is_learndash_active && ! $is_tutor_active && ! $is_lifterlms_active && ! $is_learnpress_active ) {
				return new WP_Error( 'error', 'No LMS plugin is active', array( 'status' => 400 ) );
			}

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			$user = get_user_by( 'email', $contact->email );
			if ( ! $user ) {
				return new WP_REST_Response(
					array(
						'data'  => array(),
						'total' => 0,
					),
					200
				);
			}

			$result = array();

			// LearnDash courses.
			if ( $is_learndash_active ) {
				$result = array_merge( $result, $this->get_learndash_courses( $user->ID ) );
			}

			// TutorLMS courses.
			if ( $is_tutor_active ) {
				$result = array_merge( $result, $this->get_tutor_courses( $user->ID ) );
			}

			// LifterLMS courses.
			if ( $is_lifterlms_active ) {
				$result = array_merge( $result, $this->get_lifterlms_courses( $user->ID ) );
			}

			// LearnPress courses.
			if ( $is_learnpress_active ) {
				$result = array_merge( $result, $this->get_learnpress_courses( $user->ID ) );
			}

			return new WP_REST_Response(
				array(
					'data'  => $result,
					'total' => count( $result ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get LearnDash courses for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array
	 */
	private function get_learndash_courses( $user_id ) {
		$result  = array();
		$courses = learndash_user_get_enrolled_courses( $user_id );

		foreach ( $courses as $course_id ) {
			$course = get_post( $course_id );
			if ( $course ) {
				$completed_on = learndash_user_get_course_completed_date( $user_id, $course_id );
				$started_on   = ld_course_access_from( $user_id, $course_id );
				$result[]     = array(
					'id'           => $course->ID,
					'name'         => $course->post_title,
					'url'          => get_edit_post_link( $course->ID ),
					'status'       => learndash_course_status( $course_id, $user_id ),
					'completed_on' => $completed_on ? gmdate( 'Y-m-d H:i:s', $completed_on ) : null,
					'started_on'   => $started_on ? gmdate( 'Y-m-d H:i:s', $started_on ) : null,
					'lms'          => 'learndash',
				);
			}
		}

		return $result;
	}

	/**
	 * Get TutorLMS courses for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array
	 */
	private function get_tutor_courses( $user_id ) {
		$result = array();

		if ( ! function_exists( 'tutor_utils' ) ) {
			return $result;
		}

		$enrolled_courses = tutor_utils()->get_enrolled_courses_by_user( $user_id );

		if ( $enrolled_courses && $enrolled_courses->have_posts() ) {
			while ( $enrolled_courses->have_posts() ) {
				$enrolled_courses->the_post();
				$course_id    = get_the_ID();
				$is_completed = tutor_utils()->is_completed_course( $course_id, $user_id );

				// Get completion date if completed.
				// TutorLMS stores completion in comments table with comment_type = 'course_completed'.
				$completed_on = null;
				if ( $is_completed ) {
					global $wpdb;
					$completion_comment = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT comment_date FROM {$wpdb->comments} WHERE comment_post_ID = %d AND user_id = %d AND comment_type = %s",
							$course_id,
							$user_id,
							'course_completed'
						)
					);
					if ( $completion_comment && ! empty( $completion_comment->comment_date ) ) {
						$completed_on = $completion_comment->comment_date;
					}
				}

				// Get enrollment date.
				// Note: tutor_utils()->is_enrolled() returns an object with ID, post_author, post_date, etc.
				$started_on = null;
				$enrolled   = tutor_utils()->is_enrolled( $course_id, $user_id );
				if ( $enrolled && isset( $enrolled->post_date ) ) {
					$started_on = $enrolled->post_date;
				}

				// Determine status (use underscores to match frontend STATUS_STYLES keys).
				if ( $is_completed ) {
					$status = 'completed';
				} else {
					// Check progress.
					$progress = tutor_utils()->get_course_completed_percent( $course_id, $user_id );
					if ( $progress > 0 ) {
						$status = 'in_progress';
					} else {
						$status = 'not_started';
					}
				}

				$result[] = array(
					'id'           => $course_id,
					'name'         => get_the_title(),
					'url'          => get_edit_post_link( $course_id ),
					'status'       => $status,
					'completed_on' => $completed_on,
					'started_on'   => $started_on,
					'lms'          => 'tutorlms',
				);
			}
			wp_reset_postdata();
		}

		return $result;
	}

	/**
	 * Get LifterLMS courses for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array
	 */
	private function get_lifterlms_courses( $user_id ) {
		$result = array();

		if ( ! function_exists( 'llms_get_student' ) ) {
			return $result;
		}

		$student = llms_get_student( $user_id );
		if ( ! $student ) {
			return $result;
		}

		$courses = $student->get_courses( array( 'limit' => 10000 ) );
		if ( empty( $courses['results'] ) ) {
			return $result;
		}

		foreach ( $courses['results'] as $course_id ) {
			$course = get_post( $course_id );
			if ( ! $course ) {
				continue;
			}

			// Get enrollment status.
			$enrollment_status = $student->get_enrollment_status( $course_id );
			$is_complete       = $student->is_complete( $course_id, 'course' );

			// Get dates.
			$started_on   = $student->get_enrollment_date( $course_id, 'enrolled' );
			$completed_on = null;
			if ( $is_complete ) {
				$completed_on = $student->get_completion_date( $course_id );
			}

			// Determine status.
			if ( $is_complete ) {
				$status = 'completed';
			} elseif ( 'enrolled' === $enrollment_status ) {
				// Check progress.
				$progress = $student->get_progress( $course_id, 'course' );
				if ( $progress > 0 ) {
					$status = 'in_progress';
				} else {
					$status = 'not_started';
				}
			} else {
				$status = 'not_started';
			}

			$result[] = array(
				'id'           => $course->ID,
				'name'         => $course->post_title,
				'url'          => get_edit_post_link( $course->ID ),
				'status'       => $status,
				'completed_on' => $completed_on,
				'started_on'   => $started_on,
				'lms'          => 'lifterlms',
			);
		}

		return $result;
	}

	/**
	 * Get LearnPress courses for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array
	 */
	private function get_learnpress_courses( $user_id ) {
		$result = array();

		if ( ! defined( 'LP_PLUGIN_FILE' ) ) {
			return $result;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'learnpress_user_items';

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return $result;
		}

		// Get all course enrollments for user.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is the LearnPress-prefixed user_items table; values bound via prepare().
		$enrollments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE user_id = %d AND item_type = %s ORDER BY start_time DESC",
				$user_id,
				'lp_course'
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $enrollments ) ) {
			return $result;
		}

		foreach ( $enrollments as $enrollment ) {
			$course = get_post( $enrollment->item_id );
			if ( ! $course ) {
				continue;
			}

			// Determine status based on graduation field.
			if ( 'passed' === $enrollment->graduation ) {
				$status = 'completed';
			} elseif ( 'enrolled' === $enrollment->status || 'finished' === $enrollment->status ) {
				$status = 'in_progress';
			} else {
				$status = 'not_started';
			}

			// Get completion date if passed.
			$completed_on = null;
			if ( 'passed' === $enrollment->graduation && ! empty( $enrollment->end_time ) && '0000-00-00 00:00:00' !== $enrollment->end_time ) {
				$completed_on = $enrollment->end_time;
			}

			$result[] = array(
				'id'           => $course->ID,
				'name'         => $course->post_title,
				'url'          => get_edit_post_link( $course->ID ),
				'status'       => $status,
				'completed_on' => $completed_on,
				'started_on'   => ! empty( $enrollment->start_time ) && '0000-00-00 00:00:00' !== $enrollment->start_time ? $enrollment->start_time : null,
				'lms'          => 'learnpress',
			);
		}

		return $result;
	}

	/**
	 * Get lms courses permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function get_lms_courses_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Get lead score
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_lead_score( $request ) {
		if ( ! class_exists( LeadScoringManager::class, true ) ) {
			return new WP_Error( 'not_available', __( 'Lead scoring is not available', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'leadscoring' ) ) {
			return new WP_Error( 'not_available', __( 'Lead scoring is not available', 'doublescale' ), array( 'status' => 404 ) );
		}

		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			// Get the lead score data
			$lead_score_data = LeadScoringManager::get_lead_score( $contact );

			if ( ! $lead_score_data ) {
				return new WP_REST_Response(
					array(
						'points' => 0,
						'level'  => null,
					),
					200
				);
			}

			// Format the response
			$response = array(
				'points' => $lead_score_data['points'],
				'level'  => $lead_score_data['level'] ? array(
					'id'     => $lead_score_data['level']->id,
					'name'   => $lead_score_data['level']->name,
					'slug'   => $lead_score_data['level']->slug,
					'points' => $lead_score_data['level']->points,
				) : null,
			);

			return new WP_REST_Response( $response, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get purchase history
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_purchase_history( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			$results = array(
				'edd'      => $this->get_edd_purchase_history( $contact, $request ),
				'wc'       => $this->get_wc_purchase_history( $contact, $request ),
				'surecart' => $this->get_surecart_purchase_history( $contact, $request ),
			);

			return new WP_REST_Response( $results, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Attach WooCommerce orders to paginated contacts for list columns.
	 *
	 * Matches orders by billing email and registered customer ID (same rules as
	 * wc_get_orders with a customer email), sorts newest-first, and sets revenue
	 * from completed and processing orders only.
	 *
	 * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $contacts Paginated contacts.
	 * @return void
	 */
	private function attach_wc_orders_to_contacts( $contacts ) {
		if (
			! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' )
			|| ! class_exists( '\DoubleScale\Modules\Campaigns\Models\WcOrderModel' )
			|| ! class_exists( 'Automattic\Woocommerce\Utilities\OrderUtil' )
			|| ! \Automattic\Woocommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return;
		}

		$items = $contacts->items();
		if ( empty( $items ) ) {
			return;
		}

		$emails               = array();
		$customer_ids         = array();
		$email_by_customer_id = array();

		foreach ( $items as $contact ) {
			if ( empty( $contact->email ) ) {
				continue;
			}
			$emails[] = $contact->email;
			$user     = get_user_by( 'email', $contact->email );
			if ( $user ) {
				$customer_ids[]                         = (int) $user->ID;
				$email_by_customer_id[ (int) $user->ID ] = $contact->email;
			}
		}

		$emails       = array_values( array_unique( $emails ) );
		$customer_ids = array_values( array_unique( $customer_ids ) );

		if ( empty( $emails ) && empty( $customer_ids ) ) {
			return;
		}

		$orders_query = \DoubleScale\Modules\Campaigns\Models\WcOrderModel::query()
			->whereIn( 'status', OrderStatus::get_revenue_statuses() )
			->orderBy( 'date_created_gmt', 'desc' );

		$orders_query->where(
			function ( $query ) use ( $emails, $customer_ids ) {
				if ( ! empty( $emails ) ) {
					$query->whereIn( 'billing_email', $emails );
				}
				if ( ! empty( $customer_ids ) ) {
					$query->orWhereIn( 'customer_id', $customer_ids );
				}
			}
		);

		$all_orders      = $orders_query->get();
		$orders_by_email = array();

		foreach ( $all_orders as $order ) {
			$assigned_email = null;
			if ( in_array( $order->billing_email, $emails, true ) ) {
				$assigned_email = $order->billing_email;
			} elseif ( isset( $email_by_customer_id[ (int) $order->customer_id ] ) ) {
				$assigned_email = $email_by_customer_id[ (int) $order->customer_id ];
			}

			if ( ! $assigned_email ) {
				continue;
			}

			if ( ! isset( $orders_by_email[ $assigned_email ] ) ) {
				$orders_by_email[ $assigned_email ] = array();
			}

			$orders_by_email[ $assigned_email ][ (int) $order->id ] = $order;
		}

		foreach ( $items as $contact ) {
			if ( empty( $contact->email ) ) {
				continue;
			}

			$contact_orders = array_values( $orders_by_email[ $contact->email ] ?? array() );
			usort(
				$contact_orders,
				function ( $a, $b ) {
					return strtotime( (string) $b->date_created_gmt ) <=> strtotime( (string) $a->date_created_gmt );
				}
			);

			$contact->setRelation( 'orders', collect( $contact_orders ) );

			if ( ! empty( $contact_orders ) ) {
				$contact->revenue = $this->format_wc_revenue_display(
					$this->sum_wc_model_revenue_by_currency( $contact_orders )
				);
			}
		}
	}

	/**
	 * Sum HPOS order rows by currency.
	 *
	 * @param array<int, \DoubleScale\Modules\Campaigns\Models\WcOrderModel> $orders Orders to sum.
	 * @return array<string, float>
	 */
	private function sum_wc_model_revenue_by_currency( array $orders ) {
		$revenue_by_currency = array();

		foreach ( $orders as $order ) {
			$currency = $order->currency ?: get_woocommerce_currency();
			if ( ! isset( $revenue_by_currency[ $currency ] ) ) {
				$revenue_by_currency[ $currency ] = 0.0;
			}
			$revenue_by_currency[ $currency ] += (float) $order->total_amount;
		}

		return $revenue_by_currency;
	}

	/**
	 * Format revenue for display (single or multi-currency).
	 *
	 * @param array<string, float> $revenue_by_currency Revenue keyed by currency code.
	 * @return string
	 */
	private function format_wc_revenue_display( array $revenue_by_currency ) {
		if ( empty( $revenue_by_currency ) ) {
			return '';
		}

		if ( 1 === count( $revenue_by_currency ) ) {
			$currency = array_key_first( $revenue_by_currency );

			return number_format( $revenue_by_currency[ $currency ], 2, '.', '' ) . ' ' . $currency;
		}

		$parts = array();
		foreach ( $revenue_by_currency as $currency => $amount ) {
			$parts[] = number_format( $amount, 2, '.', '' ) . ' ' . $currency;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Pick the currency with the most orders and return headline revenue stats.
	 *
	 * @param array<int, \WC_Order> $orders Paid orders.
	 * @return array{currency: string, revenue: float, average: float, revenue_by_currency: array<string, float>}
	 */
	private function summarize_wc_order_revenue( array $orders ) {
		$revenue_by_currency = array();
		$count_by_currency   = array();

		foreach ( $orders as $order ) {
			$currency = $order->get_currency() ?: get_woocommerce_currency();

			if ( ! isset( $revenue_by_currency[ $currency ] ) ) {
				$revenue_by_currency[ $currency ] = 0.0;
				$count_by_currency[ $currency ]   = 0;
			}

			$revenue_by_currency[ $currency ] += floatval( $order->get_total() );
			++$count_by_currency[ $currency ];
		}

		$dominant_currency = '';
		$dominant_count    = -1;
		foreach ( $count_by_currency as $currency => $count ) {
			if ( $count > $dominant_count ) {
				$dominant_currency = $currency;
				$dominant_count    = $count;
			}
		}

		$revenue = 0.0;
		$average = 0.0;
		if ( '' !== $dominant_currency ) {
			$revenue = $revenue_by_currency[ $dominant_currency ];
			$average = $dominant_count > 0 ? ( $revenue / $dominant_count ) : 0.0;
		}

		return array(
			'currency'            => $dominant_currency ?: get_woocommerce_currency(),
			'revenue'             => $revenue,
			'average'             => $average,
			'revenue_by_currency' => $revenue_by_currency,
		);
	}

	/**
	 * Get default purchase history structure
	 *
	 * @return array
	 */
	private function get_default_purchase_history() {
		return array(
			'orders'              => array(),
			'total'               => 0,
			'revenue'             => 0,
			'average'             => 0,
			'last_order'          => null,
			'currency'            => null,
			'revenue_by_currency' => array(),
		);
	}

	/**
	 * Pick the currency with the most sale orders and return headline EDD revenue stats.
	 *
	 * Net revenue includes refund rows (negative totals) grouped by order currency.
	 *
	 * @param array<int, \DoubleScale\Modules\Campaigns\Models\EddOrderModel> $sale_orders   Revenue-counting sale orders.
	 * @param array<int, \DoubleScale\Modules\Campaigns\Models\EddOrderModel> $refund_orders Refund orders for the contact.
	 * @return array{currency: string, revenue: float, average: float, revenue_by_currency: array<string, float>}
	 */
	private function summarize_edd_order_revenue( array $sale_orders, array $refund_orders = array() ) {
		$revenue_by_currency = array();
		$count_by_currency   = array();
		$default_currency    = function_exists( 'edd_get_option' ) ? edd_get_option( 'currency', 'USD' ) : 'USD';

		foreach ( $sale_orders as $order ) {
			$currency = $order->currency ?: $default_currency;

			if ( ! isset( $revenue_by_currency[ $currency ] ) ) {
				$revenue_by_currency[ $currency ] = 0.0;
				$count_by_currency[ $currency ]   = 0;
			}

			$revenue_by_currency[ $currency ] += (float) $order->total;
			++$count_by_currency[ $currency ];
		}

		foreach ( $refund_orders as $order ) {
			$currency = $order->currency ?: $default_currency;

			if ( ! isset( $revenue_by_currency[ $currency ] ) ) {
				$revenue_by_currency[ $currency ] = 0.0;
			}

			$revenue_by_currency[ $currency ] += (float) $order->total;
		}

		$dominant_currency = '';
		$dominant_count    = -1;
		foreach ( $count_by_currency as $currency => $count ) {
			if ( $count > $dominant_count ) {
				$dominant_currency = $currency;
				$dominant_count    = $count;
			}
		}

		$revenue = 0.0;
		$average = 0.0;
		if ( '' !== $dominant_currency ) {
			$revenue = $revenue_by_currency[ $dominant_currency ];
			$average = $dominant_count > 0 ? ( $revenue / $dominant_count ) : 0.0;
		}

		return array(
			'currency'            => $dominant_currency ?: $default_currency,
			'revenue'             => $revenue,
			'average'             => $average,
			'revenue_by_currency' => $revenue_by_currency,
		);
	}

	/**
	 * Get EDD purchase history for a contact.
	 *
	 * Queries by email, EDD customer ID, and WordPress user ID. Only sale orders
	 * with net revenue statuses appear in totals and the order list. Revenue is
	 * net of refund rows and grouped by order currency.
	 *
	 * @param ContactModel    $contact
	 * @param WP_REST_Request $request
	 * @return array
	 */
	private function get_edd_purchase_history( $contact, $request ) {
		$result = $this->get_default_purchase_history();

		if ( ! defined( 'EDD_PLUGIN_FILE' ) ) {
			return $result;
		}

		$page     = (int) $request->get_param( 'edd_page' );
		$per_page = (int) $request->get_param( 'edd_per_page' );
		$offset   = ( $page - 1 ) * $per_page;

		$result['currency'] = edd_get_option( 'currency', 'USD' );

		$revenue_query = $contact->edd_revenue_orders_query();
		$total_count   = (clone $revenue_query)->count();

		if ( $total_count === 0 ) {
			return $result;
		}

		$paginated_orders = (clone $revenue_query)
			->orderBy( 'date_created', 'desc' )
			->skip( $offset )
			->take( $per_page )
			->get();

		$all_sale_orders = (clone $revenue_query)->get();
		$refund_orders   = $contact->edd_orders_query()
			->where( 'type', 'refund' )
			->get()
			->all();

		$revenue_summary = $this->summarize_edd_order_revenue( $all_sale_orders->all(), $refund_orders );

		$result['currency']            = $revenue_summary['currency'];
		$result['revenue']             = $revenue_summary['revenue'];
		$result['average']             = $revenue_summary['average'];
		$result['revenue_by_currency'] = $revenue_summary['revenue_by_currency'];
		$result['orders']              = $paginated_orders;
		$result['total']               = $total_count;
		$result['last_order']          = (clone $revenue_query)
			->orderBy( 'date_created', 'desc' )
			->value( 'date_created' );

		return $result;
	}

	/**
	 * Get the admin edit URL for a WooCommerce order.
	 *
	 * Handles HPOS (Custom Orders Table) and avoids HTML entity escaping.
	 *
	 * @param int $order_id
	 * @return string|null
	 */
	private function get_wc_order_edit_url( $order_id ) {
		if (
			class_exists( 'Automattic\Woocommerce\Utilities\OrderUtil' )
			&& method_exists( 'Automattic\Woocommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
			&& \Automattic\Woocommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $order_id ) );
		}

		return get_edit_post_link( absint( $order_id ), '' );
	}

	/**
	 * Get WooCommerce purchase history for a contact.
	 *
	 * Queries by email so a single call covers user-linked, guest, and
	 * email-mismatched orders. Only completed and processing orders are included
	 * in totals, the order list, and revenue. Revenue is grouped by order
	 * currency to avoid summing across currencies.
	 *
	 * @param ContactModel    $contact
	 * @param WP_REST_Request $request
	 * @return array
	 */
	private function get_wc_purchase_history( $contact, $request ) {
		$result = $this->get_default_purchase_history();

		if ( ! doublescale_is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			return $result;
		}

		$page          = (int) $request->get_param( 'woo_page' );
		$per_page      = (int) $request->get_param( 'woo_per_page' );
		$offset        = ( $page - 1 ) * $per_page;
		$paid_statuses = OrderStatus::get_revenue_statuses();
		$email         = $contact->email;
		$order_query   = array(
			'customer' => $email,
			'status'   => $paid_statuses,
		);

		// wc_get_orders() with a string $customer matches BOTH _customer_user
		// (resolved to a user by email) and billing_email — which catches
		// guest orders, orders missing the customer-user link, and orders
		// where the user changed their account email after purchase.
		$total_count = count(
			wc_get_orders(
				array_merge(
					$order_query,
					array(
						'limit'  => -1,
						'return' => 'ids',
					)
				)
			)
		);

		// Default the headline currency to the store currency so an empty
		// purchase history still has a sensible label.
		$result['currency'] = get_woocommerce_currency();

		if ( $total_count === 0 ) {
			return $result;
		}

		$wc_orders = wc_get_orders(
			array_merge(
				$order_query,
				array(
					'limit'   => $per_page,
					'offset'  => $offset,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			)
		);

		$formatted_orders = array_map(
			function ( $order ) {
				$date_created = $order->get_date_created();
				return array(
					'id'           => $order->get_id(),
					'total_amount' => floatval( $order->get_total() ),
					'date'         => $date_created ? $date_created->format( 'Y-m-d H:i:s' ) : null,
					'url'          => $this->get_wc_order_edit_url( $order->get_id() ),
					'status'       => wc_get_order_status_name( $order->get_status() ),
					'subtotal'     => floatval( $order->get_subtotal() ),
					'currency'     => $order->get_currency(),
				);
			},
			$wc_orders
		);

		$paid_orders = wc_get_orders(
			array_merge(
				$order_query,
				array(
					'limit' => -1,
				)
			)
		);

		$revenue_summary = $this->summarize_wc_order_revenue( $paid_orders );

		$result['currency']            = $revenue_summary['currency'];
		$result['revenue']             = $revenue_summary['revenue'];
		$result['average']             = $revenue_summary['average'];
		$result['revenue_by_currency'] = $revenue_summary['revenue_by_currency'];
		$result['orders']              = $formatted_orders;
		$result['total']               = $total_count;
		$result['last_order']          = ! empty( $wc_orders ) && $wc_orders[0]->get_date_created()
			? $wc_orders[0]->get_date_created()->format( 'Y-m-d H:i:s' )
			: null;

		return $result;
	}

	/**
	 * Get SureCart purchase history for a contact
	 *
	 * @param ContactModel    $contact
	 * @param WP_REST_Request $request
	 * @return array
	 */
	private function get_surecart_purchase_history( $contact, $request ) {
		$result = $this->get_default_purchase_history();

		if ( ! defined( 'SURECART_PLUGIN_FILE' ) || ! class_exists( '\SureCart\Models\Customer' ) ) {
			return $result;
		}

		$customer = \SureCart\Models\Customer::byEmail( $contact->email );
		if ( ! $customer || is_wp_error( $customer ) ) {
			return $result;
		}

		// SureCart's Model::get() returns an array of model objects directly
		$sc_orders = \SureCart\Models\Order::where(
			array(
				'customer_ids' => array( $customer->id ),
			)
		)->with( array( 'checkout' ) )->get();

		if ( ! is_array( $sc_orders ) || empty( $sc_orders ) ) {
			return $result;
		}

		$excluded_statuses = array( 'canceled', 'cancelled', 'refunded', 'failed' );
		$formatted_orders  = array();
		$total_revenue     = 0;
		$paid_count        = 0;

		foreach ( $sc_orders as $order ) {
			$order_status = $order->status ?? '';
			$order_total  = isset( $order->checkout->total_amount ) ? ( $order->checkout->total_amount / 100 ) : 0;
			$is_paid      = ! in_array( $order_status, $excluded_statuses, true );

			if ( $is_paid ) {
				$total_revenue += $order_total;
				++$paid_count;
			}

			$formatted_orders[] = array(
				'id'           => $order->id ?? '',
				'number'       => $order->number ?? '',
				'total_amount' => $order_total,
				'date'         => isset( $order->created_at ) ? gmdate( 'Y-m-d H:i:s', $order->created_at ) : null,
				'url'          => admin_url( 'admin.php?page=sc-orders&action=edit&id=' . ( $order->id ?? '' ) ),
				'status'       => $order_status,
				'order_type'   => $order->order_type ?? '',
				'currency'     => isset( $order->checkout->currency ) ? strtoupper( $order->checkout->currency ) : 'USD',
			);
		}

		$total_count = count( $formatted_orders );

		// SureCart Api does not support pagination natively, so paginate the result set.
		$page     = (int) $request->get_param( 'surecart_page' );
		$per_page = (int) $request->get_param( 'surecart_per_page' );
		$offset   = ( $page - 1 ) * $per_page;

		$paged_orders = array_slice( $formatted_orders, $offset, $per_page );

		$result['orders']     = $paged_orders;
		$result['total']      = $total_count;
		$result['revenue']    = $total_revenue;
		$result['average']    = $paid_count > 0 ? ( $total_revenue / $paid_count ) : 0;
		$result['last_order'] = $formatted_orders[0]['date'] ?? null;
		$result['currency']   = $formatted_orders[0]['currency'] ?? 'USD';

		return $result;
	}

	/**
	 * Unified endpoint to get ALL messages (campaigns + individual) for contact by channel
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_messages( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$mode       = $request->get_param( 'mode' ) ?: CommunicationTrackingModel::MODE_EMAIL; // Default to email (1)
			$per_page   = $request->get_param( 'per_page' ) ?: 25;
			$page       = $request->get_param( 'page' ) ?: 1;

			// Validate contact exists (using helper)
			$contact = $this->validate_contact_exists( $contact_id );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			// Map channel to tracking mode (using helper)
			$tracking_mode = $this->map_mode_to_tracking_mode( $mode );
			if ( is_wp_error( $tracking_mode ) ) {
				return $tracking_mode;
			}

			// Get ALL messages (campaigns + individual) for this channel
			$messages_query = CommunicationTrackingModel::where( 'contact_id', $contact_id )
				->where( 'mode', $tracking_mode );

			$activity_id = (int) $request->get_param( 'activity_id' );
			if ( $activity_id > 0 ) {
				$messages_query->where( 'source_type', MessageSourceTypes::INDIVIDUAL )
					->where( 'source_id', $activity_id );
			}

			$messages_query->with(
					array(
						'campaign'                    => function ( $query ) {
							$query->select( 'id', 'name', 'type' );
						},
						'template'                    => function ( $query ) {
							$query->select( 'id', 'subject', 'body', 'settings' );
						},
						'activity'                    => function ( $query ) {
							$query->select( 'id', 'activity_type', 'data', 'user_id', 'created_at' );
						}, // Include activity content for individual messages (email_sent, sms_sent, whatsapp_sent)
						'communication_tracking_meta' => function ( $query ) {
							$query->select( 'id', 'communication_tracking_id', 'meta_key', 'meta_value' );
						}, // Include merge tag values for historical rendering
					)
				)
				->orderBy( 'created_at', 'desc' );

			// Execute paginated query
			$messages = $messages_query->paginate( $per_page, array( '*' ), 'page', $page );

			// source_id is polymorphic: for campaigns it points to a campaign,
			// for individual messages it points to an activity. The eager-loaded
			// "campaign" and "activity" relations both key off source_id, so
			// they can accidentally cross-match (e.g. activity ID 2 matches
			// campaign ID 2). Null out the wrong relation on each record.
			foreach ( $messages->items() as $msg ) {
				if ( (int) $msg->source_type !== MessageSourceTypes::CAMPAIGN ) {
					$msg->setRelation( 'campaign', null );
				}
				if ( (int) $msg->source_type !== MessageSourceTypes::INDIVIDUAL ) {
					$msg->setRelation( 'activity', null );
				}
			}

			// Resolve stored merge tag values in template subjects for display.
			foreach ( $messages->items() as $msg ) {
				if ( $msg->template && $msg->template->subject ) {
					$msg->resolved_subject = $msg->render_original_content( $msg->template->subject );
				}
			}

			// Attach stored email attachments to each individual-message row.
			// For INDIVIDUAL messages source_id is the email activity id (see the
			// model's activity() docblock), so we key the map on it directly without
			// touching the eager-loaded `activity` relation. Campaign rows (no
			// activity) always get an empty list. `attachments` is a dynamic
			// attribute serialized into the JSON response, exactly like
			// `resolved_subject` above — there is no relation/accessor name clash.
			$activity_ids = array();
			foreach ( $messages->items() as $msg ) {
				$msg->attachments = array();
				if ( (int) $msg->source_type === MessageSourceTypes::INDIVIDUAL && $msg->source_id ) {
					$activity_ids[] = (int) $msg->source_id;
				}
			}
			if ( ! empty( $activity_ids ) ) {
				$attachment_map = ( new EmailAttachmentService() )->map_for_activities( $activity_ids );
				foreach ( $messages->items() as $msg ) {
					if ( (int) $msg->source_type === MessageSourceTypes::INDIVIDUAL && $msg->source_id ) {
						$msg->attachments = $attachment_map[ (int) $msg->source_id ] ?? array();
					}
				}
			}

			// Get statistics in a single query (using helper - 80% faster!)
			$statistics = $this->get_message_statistics( $contact_id, $tracking_mode, $mode );

			// Merge messages with statistics
			$result = array_merge(
				array(
					'messages' => $messages,
					'mode'     => $mode,
				),
				$statistics
			);

			return new WP_REST_Response( $result, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Helper: Map channel mode to tracking mode constant
	 *
	 * @param int|string $mode Channel mode as integer (1, 2, 3) or string (email|sms|whatsapp).
	 *
	 * @return int|WP_Error Tracking mode constant or WP_Error.
	 */
	private function map_mode_to_tracking_mode( $mode ) {
		// If it's already an integer, validate it directly
		if ( is_int( $mode ) || ctype_digit( (string) $mode ) ) {
			$mode_int    = (int) $mode;
			$valid_modes = array( CommunicationTrackingModel::MODE_EMAIL, CommunicationTrackingModel::MODE_SMS, CommunicationTrackingModel::MODE_WHATSAPP );

			if ( in_array( $mode_int, $valid_modes, true ) ) {
				return $mode_int;
			}

			return new WP_Error(
				'invalid_mode',
				/* translators: %d: invalid mode number */
				sprintf( __( 'Invalid mode: %d. Must be 1 (email), 2 (sms), or 3 (whatsapp).', 'doublescale' ), $mode_int ),
				array( 'status' => 400 )
			);
		}

		// Map string modes to tracking mode constants
		$mode_map = array(
			CampaignChannel::STR_EMAIL    => CommunicationTrackingModel::MODE_EMAIL,
			CampaignChannel::STR_SMS      => CommunicationTrackingModel::MODE_SMS,
			CampaignChannel::STR_WHATSAPP => CommunicationTrackingModel::MODE_WHATSAPP,
		);

		if ( ! isset( $mode_map[ $mode ] ) ) {
			return new WP_Error(
				'invalid_mode',
				/* translators: %s: invalid mode string */
				sprintf( __( 'Invalid mode: %s. Must be 1 (email), 2 (sms), or 3 (whatsapp).', 'doublescale' ), $mode ),
				array( 'status' => 400 )
			);
		}

		return $mode_map[ $mode ];
	}

	/**
	 * Helper: Get message statistics in a single query
	 *
	 * @param int    $contact_id    Contact ID.
	 * @param int    $tracking_mode Tracking mode constant.
	 * @param string $mode          Channel mode (email|sms|whatsapp).
	 *
	 * @return array Statistics array.
	 */
	private function get_message_statistics( $contact_id, $tracking_mode, $mode ) {
		global $wpdb;

		// Single query to get all statistics at once
		$table = esc_sql( $wpdb->prefix . 'doublescale_communication_tracking' );

		// Build query based on mode
		if ( $mode === CampaignChannel::STR_EMAIL ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the prefixed tracking table name; all values bound via prepare().
			$query = $wpdb->prepare(
				"SELECT
					COUNT(CASE WHEN direction = %d THEN 1 END) as total_sent,
					COUNT(CASE WHEN opened = 1 AND direction = %d THEN 1 END) as total_opened,
					COUNT(CASE WHEN clicked = 1 AND direction = %d THEN 1 END) as total_clicked,
					COUNT(CASE WHEN direction = %d THEN 1 END) as total_received
				FROM {$table}
				WHERE contact_id = %d AND mode = %d",
				MessageDirection::OUTBOUND,
				MessageDirection::OUTBOUND,
				MessageDirection::OUTBOUND,
				MessageDirection::INBOUND,
				$contact_id,
				$tracking_mode
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above with $wpdb->prepare().
			$stats = $wpdb->get_row( $query );

			$total_sent     = (int) ( $stats->total_sent ?? 0 );
			$total_opened   = (int) ( $stats->total_opened ?? 0 );
			$total_clicked  = (int) ( $stats->total_clicked ?? 0 );
			$total_received = (int) ( $stats->total_received ?? 0 );

			return array(
				'total_sent'     => $total_sent,
				'total_opened'   => $total_opened,
				'total_clicked'  => $total_clicked,
				'total_received' => $total_received,
				'open_rate'      => $total_sent > 0 ? round( ( $total_opened / $total_sent ) * 100, 2 ) : 0,
				'click_rate'     => $total_sent > 0 ? round( ( $total_clicked / $total_sent ) * 100, 2 ) : 0,
			);
		} else {
			// Sms/Whatsapp statistics
			// For Sms, "total_sent" means successfully sent (SENT + DELIVERED statuses)
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the prefixed tracking table name; all values bound via prepare().
			$query = $wpdb->prepare(
				"SELECT
					COUNT(CASE WHEN status IN (%d, %d) THEN 1 END) as total_sent,
					COUNT(CASE WHEN status IN (%d, %d) THEN 1 END) as total_delivered,
					COUNT(CASE WHEN status = %d THEN 1 END) as total_failed
				FROM {$table}
				WHERE contact_id = %d AND mode = %d",
				TrackingStatus::SENT,
				TrackingStatus::DELIVERED,
				TrackingStatus::DELIVERED,
				TrackingStatus::SENT,
				TrackingStatus::FAILED,
				$contact_id,
				$tracking_mode
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above with $wpdb->prepare().
			$stats = $wpdb->get_row( $query );

			return array(
				'total_sent'      => (int) ( $stats->total_sent ?? 0 ),
				'total_delivered' => (int) ( $stats->total_delivered ?? 0 ),
				'total_failed'    => (int) ( $stats->total_failed ?? 0 ),
			);
		}
	}

	/**
	 * Helper: Validate contact exists
	 *
	 * @param int $contact_id Contact ID.
	 *
	 * @return ContactModel|WP_Error Contact model or WP_Error.
	 */
	private function validate_contact_exists( $contact_id ) {
		$contact = ContactModel::find( $contact_id );

		if ( ! $contact ) {
			return new WP_Error(
				'contact_not_found',
				__( 'Contact not found', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		return $contact;
	}

	/**
	 * Build a REST error when a contact identifier is already in use.
	 *
	 * @param array{field: string, contact: ContactModel}|null $conflict Conflict details.
	 *
	 * @return WP_Error|null
	 */
	private function identifier_conflict_error( $conflict ) {
		if ( ! $conflict || empty( $conflict['field'] ) ) {
			return null;
		}

		$messages = array(
			'email'          => __( 'A contact with this email address already exists.', 'doublescale' ),
			'phone'          => __( 'A contact with this phone number already exists.', 'doublescale' ),
			'whatsapp_phone' => __( 'A contact with this WhatsApp number already exists.', 'doublescale' ),
		);

		$field = (string) $conflict['field'];
		$code  = $field . '_exists';

		return new WP_Error(
			$code,
			$messages[ $field ] ?? __( 'Contact already exists.', 'doublescale' ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * Permission check for unified messages endpoint
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function get_messages_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Get filters
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_filters( $request ) {
		$filters = FiltersManager::instance()->get_groups();

		return new WP_REST_Response( $filters, 200 );
	}

	/**
	 * Get a collection of contacts
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function get_items( $request ) {
		try {
			$per_page           = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page               = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$keywords           = $request->get_param( 'keywords' ) ?? '';
			$filters            = $this->normalize_contact_filters_param( $request->get_param( 'filters' ) );
			$subscribed         = $request->get_param( 'subscribed' ) ?? false;
			$campaign_type      = $request->get_param( 'campaign_type' ) ?? null;
			$has_whatsapp_phone = $request->get_param( 'has_whatsapp_phone' ) ?? null;
			$from               = $request->get_param( 'from' ) ?? null;
			$to                 = $request->get_param( 'to' ) ?? null;
			$query              = ContactModel::query();
			$total_count        = $query->count();

			// Start with base query and load relationships
			// Load custom_fields when the CustomField model is available.
			$relationships = array( 'lists', 'tags', 'notes' );
			if ( class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
				$relationships[] = 'custom_fields';
			}
			$contacts = $query->with( $relationships );

			// Apply date range filters
			if ( $from ) {
				$contacts->where( 'created_at', '>=', $from );
			}
			if ( $to ) {
				$contacts->where( 'created_at', '<=', $to );
			}

			// Apply filters FIRST to narrow down the results
			if ( $filters ) {
				$filters_process = new Contact_Filters_Process( $contacts, $filters );
				$contacts        = $filters_process->filter();
			}

			// Apply subscription filter
			if ( $subscribed ) {
				$contacts = $contacts->where( 'email_status', 'subscribed' );
			}

			// Apply campaign type filter (email/phone availability + channel status)
			if ( $campaign_type ) {
				// Convert campaign_type to integer format for processing
				// Frontend may send: "sms" (string), "2" (numeric string), or 2 (integer)
				if ( is_numeric( $campaign_type ) ) {
					$campaign_type_int = (int) $campaign_type;
				} else {
					$campaign_type_int = CampaignChannel::to_integer( $campaign_type );
				}

				// Convert back to string for channel status field lookup
				$campaign_type_string = CampaignChannel::to_string( $campaign_type_int );

				if ( $campaign_type_string ) {
					// Apply channel-specific status filter (e.g., sms_status = 'subscribed')
					$channel_status_field = $campaign_type_string . '_status';
					$contacts             = $contacts->where( $channel_status_field, 'subscribed' );

					if ( class_exists( '\DoubleScale\Modules\Campaigns\Services\CampaignContactFilter' ) ) {
						$campaign_contact_filter = \DoubleScale\Modules\Campaigns\Services\CampaignContactFilter::instance();
						$contacts                = $campaign_contact_filter->apply_campaign_type_filter( $contacts, $campaign_type_int );
					}
				}
			}

			// Apply WhatsApp phone filter
			if ( ! is_null( $has_whatsapp_phone ) ) {
				if ( $has_whatsapp_phone ) {
					$contacts = $contacts->whereNotNull( 'whatsapp_phone' )
						->where( 'whatsapp_phone', '!=', '' );
				} else {
					$contacts = $contacts->where(
						function ( $query ) {
							$query->whereNull( 'whatsapp_phone' )
								->orWhere( 'whatsapp_phone', '=', '' );
						}
					);
				}
			}

			// Apply keyword search AFTER filters (search within filtered results)
			if ( '' !== $keywords ) {
				$has_custom_fields = class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' );
				$contacts          = $contacts->where(
					function ( $query ) use ( $keywords, $has_custom_fields ) {
						$query->where( 'first_name', 'like', '%' . $keywords . '%' )
							->orWhere( 'last_name', 'like', '%' . $keywords . '%' )
							->orWhere( 'email', 'like', '%' . $keywords . '%' )
							->orWhere( 'phone', 'like', '%' . $keywords . '%' )
							->orWhere( 'whatsapp_phone', 'like', '%' . $keywords . '%' );

						if ( $has_custom_fields ) {
							$query->orWhereHas(
								'custom_fields',
								function ( $custom_field_query ) use ( $keywords ) {
									$custom_field_query->where( 'value', 'like', '%' . $keywords . '%' );
								}
							);
						}
					}
				);
			}

			// Paginate and get results (pagination automatically handles total count)
			// Note: paginate() returns total in the response, so filtered_total comes from pagination
			$this->apply_sorting( $contacts, $request, self::SORTABLE_COLUMNS );

			$contacts = $contacts->paginate( $per_page, array( '*' ), 'page', $page );

			$this->attach_wc_orders_to_contacts( $contacts );

			$filtered_total = $contacts->total();

			return new WP_REST_Response(
				$contacts->toArray() + array(
					'total_count'    => $total_count,
					'filtered_total' => $filtered_total,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get a collection of contacts
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function create_item( $request ) {
		try {
			$contact_data = $this->prepare_contact( $request );

			if ( ! ContactModel::has_identifier(
				$contact_data['email'] ?? null,
				$contact_data['phone'] ?? '',
				$contact_data['whatsapp_phone'] ?? ''
			) ) {
				return new WP_Error(
					'missing_identifier',
					__( 'Contact must have an email address or phone number.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			$existing = ContactModel::find_identifier_conflict( $contact_data );
			if ( $existing ) {
				return $this->identifier_conflict_error( $existing );
			}

			$contact = ContactModel::create( $contact_data );

			$sync_lists = $this->sync_lists( $request, $contact );
			if ( is_wp_error( $sync_lists ) ) {
				return $sync_lists;
			}

			$sync_tags = $this->sync_tags( $request, $contact );
			if ( is_wp_error( $sync_tags ) ) {
				return $sync_tags;
			}

			$sync_custom_fields = $this->sync_custom_fields( $request, $contact );
			if ( is_wp_error( $sync_custom_fields ) ) {
				return $sync_custom_fields;
			}

			$sync_notes = $this->sync_notes( $request, $contact );
			if ( is_wp_error( $sync_notes ) ) {
				return $sync_notes;
			}

			return new WP_REST_Response( $contact, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a collection of contacts
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function delete_items( $request ) {
		try {
			$contact_ids = array_values(
				array_filter(
					array_map( 'intval', (array) ( $request->get_param( 'ids' ) ? $request->get_param( 'ids' ) : array() ) )
				)
			);
			$force       = rest_sanitize_boolean( $request->get_param( 'force' ) );

			if ( empty( $contact_ids ) ) {
				return new WP_Error( 'invalid_request', __( 'No contacts selected.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$guard = $this->guard_contact_deletion( $contact_ids, $force );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			$contacts = ContactModel::find( $contact_ids );

			if ( ! $contacts ) {
				return new WP_Error( 'not_found', 'Contacts not found', array( 'status' => 404 ) );
			}

			ContactModel::destroy( $contact_ids );

			return new WP_REST_Response( $contacts, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function delete_item( $request ) {
		try {
			$contact_id = (int) $request->get_param( 'id' );
			$force      = rest_sanitize_boolean( $request->get_param( 'force' ) );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			$guard = $this->guard_contact_deletion( array( $contact_id ), $force );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			$contact->delete();

			return new WP_REST_Response( $contact, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Summarize related records that will be removed or unlinked when contacts are deleted.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_deletion_impact( $request ) {
		$contact_ids = array_values(
			array_filter(
				array_map( 'intval', (array) $request->get_param( 'ids' ) )
			)
		);

		if ( empty( $contact_ids ) ) {
			return new WP_Error( 'invalid_request', __( 'No contacts selected.', 'doublescale' ), array( 'status' => 400 ) );
		}

		try {
			$impact = $this->build_contact_deletion_impact( $contact_ids );
		} catch ( \Throwable $e ) {
			// Never surface raw SQL (missing-module tables) to the admin UI.
			return new WP_Error(
				'deletion_impact_unavailable',
				__( 'Could not calculate related records for these contacts. Please try again.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'contact_count'  => count( $contact_ids ),
				'related'        => $impact,
				'requires_force' => $this->contact_deletion_requires_force( $impact ),
			),
			200
		);
	}

	/**
	 * Which of the given contact ids still have invoices.
	 *
	 * Invoices reference contacts without a DB constraint, so deletion is
	 * guarded here instead.
	 *
	 * @param array<int|string> $contact_ids Contact ids to check.
	 * @return array<int>
	 */
	private function contact_ids_with_invoices( array $contact_ids ): array {
		$contact_ids = array_values( array_filter( array_map( 'intval', $contact_ids ) ) );

		if ( empty( $contact_ids ) || ! $this->module_storage_ready( 'documents', \DoubleScale\Modules\Documents\Models\InvoiceModel::class ) ) {
			return array();
		}

		try {
			$ids = \DoubleScale\Modules\Documents\Models\InvoiceModel::query()
				->whereIn( 'contact_id', $contact_ids )
				->pluck( 'contact_id' )
				->unique()
				->values()
				->toArray();
		} catch ( \Throwable $e ) {
			return array();
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Block deletion when financial records exist unless the caller confirmed force.
	 *
	 * @param array<int> $contact_ids Contact ids.
	 * @param bool       $force       Whether to cascade-delete financial records first.
	 * @return true|WP_Error
	 */
	private function guard_contact_deletion( array $contact_ids, bool $force ) {
		$impact = $this->build_contact_deletion_impact( $contact_ids );

		if ( ! $force && $this->contact_deletion_requires_force( $impact ) ) {
			return new WP_Error(
				'contact_has_invoices',
				__( 'These contacts have invoices and cannot be deleted. Delete or reassign their invoices first.', 'doublescale' ),
				array(
					'status'         => 409,
					'blocked_ids'      => $this->contact_ids_with_invoices( $contact_ids ),
					'impact'           => $impact,
					'requires_force'   => true,
				)
			);
		}

		if ( $force ) {
			$this->cascade_financial_records_for_contacts( $contact_ids );
		}

		return true;
	}

	/**
	 * Whether deletion needs explicit confirmation because financial records exist.
	 *
	 * @param array<string, int> $impact Related record counts from {@see build_contact_deletion_impact()}.
	 * @return bool
	 */
	private function contact_deletion_requires_force( array $impact ): bool {
		foreach ( array( 'invoices', 'contracts', 'credit_notes' ) as $key ) {
			if ( ! empty( $impact[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count related CRM records that will be removed or unlinked with these contacts.
	 *
	 * Skips modules that are disabled or whose tables are missing so deletion
	 * impact (and the user-facing warning modal) still loads for everything else.
	 *
	 * @param array<int> $contact_ids Contact ids.
	 * @return array<string, int>
	 */
	private function build_contact_deletion_impact( array $contact_ids ): array {
		$contact_ids = array_values( array_filter( array_map( 'intval', $contact_ids ) ) );

		$impact = array(
			'invoices'     => 0,
			'payments'     => 0,
			'proposals'    => 0,
			'deals'        => 0,
			'projects'     => 0,
			'tasks'        => 0,
			'contracts'    => 0,
			'credit_notes' => 0,
			'tickets'      => 0,
			'bookings'     => 0,
			'activities'   => 0,
		);

		if ( empty( $contact_ids ) ) {
			return $impact;
		}

		$impact['invoices'] = $this->count_related_for_contacts(
			'documents',
			\DoubleScale\Modules\Documents\Models\InvoiceModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Modules\Documents\Models\InvoiceModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		if ( $impact['invoices'] > 0 && class_exists( \DoubleScale\Modules\Documents\Models\PaymentModel::class ) ) {
			try {
				$invoice_ids = \DoubleScale\Modules\Documents\Models\InvoiceModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->pluck( 'id' )
					->toArray();
				if ( ! empty( $invoice_ids ) ) {
					$impact['payments'] = (int) \DoubleScale\Modules\Documents\Models\PaymentModel::query()
						->whereIn( 'invoice_id', $invoice_ids )
						->count();
				}
			} catch ( \Throwable $e ) {
				$impact['payments'] = 0;
			}
		}

		$impact['proposals'] = $this->count_related_for_contacts(
			'documents',
			\DoubleScale\Modules\Documents\Models\ProposalModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Modules\Documents\Models\ProposalModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		$impact['deals'] = $this->count_related_for_contacts(
			'deals',
			\DoubleScale\Pro\Modules\Deals\Models\DealModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Pro\Modules\Deals\Models\DealModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		$impact['projects'] = $this->count_related_for_contacts(
			'projects',
			\DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		$impact['tasks'] = $this->count_related_for_contacts(
			'tasks',
			\DoubleScale\Pro\Modules\Tasks\Models\TaskModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Pro\Modules\Tasks\Models\TaskModel::query()
					->where( 'entity_type', \DoubleScale\Core\Constants\TaskEntityType::CONTACT )
					->whereIn( 'entity_id', $contact_ids )
					->count();
			}
		);

		$impact['contracts'] = $this->count_related_for_contacts(
			'contracts',
			\DoubleScale\Pro\Modules\Contracts\Models\ContractModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Pro\Modules\Contracts\Models\ContractModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		$impact['credit_notes'] = $this->count_related_for_contacts(
			'credit_notes',
			\DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		$impact['tickets'] = $this->count_related_for_contacts(
			'support',
			\DoubleScale\Modules\Support\Models\TicketModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Modules\Support\Models\TicketModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		$impact['bookings'] = $this->count_related_for_contacts(
			'booking',
			\DoubleScale\Modules\Booking\Models\BookingModel::class,
			static function () use ( $contact_ids ) {
				return (int) \DoubleScale\Modules\Booking\Models\BookingModel::query()
					->whereIn( 'contact_id', $contact_ids )
					->count();
			}
		);

		try {
			if ( class_exists( \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::class ) ) {
				$impact['activities'] = (int) \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::query()
					->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT )
					->whereIn( 'entity_id', $contact_ids )
					->distinct()
					->count( 'activity_id' );
			}
		} catch ( \Throwable $e ) {
			$impact['activities'] = 0;
		}

		return $impact;
	}

	/**
	 * Count related rows for a module, or 0 when the module/table is unavailable.
	 *
	 * @param string   $slug        Module slug.
	 * @param string   $model_class Eloquent model class.
	 * @param callable $counter     Returns int count.
	 */
	private function count_related_for_contacts( string $slug, string $model_class, callable $counter ): int {
		if ( ! $this->module_storage_ready( $slug, $model_class ) ) {
			return 0;
		}

		try {
			return (int) $counter();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Whether a module is enabled and its storage table exists.
	 *
	 * class_exists alone is not enough: disabled modules still autoload models,
	 * but their tables may never have been created.
	 *
	 * @param string $slug        Module slug.
	 * @param string $model_class Eloquent model class.
	 */
	private function module_storage_ready( string $slug, string $model_class ): bool {
		if ( function_exists( 'doublescale_is_module_storage_ready' ) ) {
			return doublescale_is_module_storage_ready( $slug, $model_class );
		}

		if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( $slug ) ) {
			return false;
		}

		return class_exists( $model_class );
	}

	/**
	 * Delete financial records that block contact removal when force-delete is confirmed.
	 *
	 * @param array<int> $contact_ids Contact ids.
	 * @return void
	 */
	private function cascade_financial_records_for_contacts( array $contact_ids ): void {
		$contact_ids = array_values( array_filter( array_map( 'intval', $contact_ids ) ) );

		if ( empty( $contact_ids ) ) {
			return;
		}

		if ( $this->module_storage_ready( 'credit_notes', \DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel::class ) ) {
			$credit_notes = \DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel::query()
				->whereIn( 'contact_id', $contact_ids )
				->get();
			foreach ( $credit_notes as $credit_note ) {
				$credit_note->delete();
			}
		}

		if ( $this->module_storage_ready( 'contracts', \DoubleScale\Pro\Modules\Contracts\Models\ContractModel::class ) ) {
			$contracts = \DoubleScale\Pro\Modules\Contracts\Models\ContractModel::query()
				->whereIn( 'contact_id', $contact_ids )
				->get();
			foreach ( $contracts as $contract ) {
				$contract->delete();
			}
		}

		if ( $this->module_storage_ready( 'documents', \DoubleScale\Modules\Documents\Models\InvoiceModel::class ) ) {
			$invoices = \DoubleScale\Modules\Documents\Models\InvoiceModel::query()
				->whereIn( 'contact_id', $contact_ids )
				->get();
			foreach ( $invoices as $invoice ) {
				$invoice->delete();
			}
		}
	}

	/**
	 * Get a contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function get_item( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			// Load relationships — include custom_fields when the model is available.
			$contact->load( array( 'lists', 'tags' ) );
			if ( class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
				$contact->load( 'custom_fields' );
			}

			return new WP_REST_Response( $contact, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update a contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function update_item( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			$contact_data = $this->prepare_contact( $request );

			$duplicate = ContactModel::find_identifier_conflict( $contact_data, (int) $contact_id );
			if ( $duplicate ) {
				return $this->identifier_conflict_error( $duplicate );
			}

			$changes      = ContactUpdateNotifier::collect_field_changes( $contact, $contact_data );
			$contact->update( $contact_data );

			$sync_lists = $this->sync_lists( $request, $contact );
			if ( is_wp_error( $sync_lists ) ) {
				return $sync_lists;
			}

			$sync_tags = $this->sync_tags( $request, $contact );
			if ( is_wp_error( $sync_tags ) ) {
				return $sync_tags;
			}

			$custom_fields_param = $request->get_param( 'custom_fields' );
			if (
				is_array( $custom_fields_param )
				&& ! empty( $custom_fields_param )
				&& class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' )
			) {
				$contact->load( 'custom_fields' );
				$normalized = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::normalize_submission( $custom_fields_param );
				$changes    = ContactUpdateNotifier::merge_custom_field_changes( $contact, $normalized, $changes );
			}

			$sync_custom_fields = $this->sync_custom_fields( $request, $contact );
			if ( is_wp_error( $sync_custom_fields ) ) {
				return $sync_custom_fields;
			}

			$sync_notes = $this->sync_notes( $request, $contact );
			if ( is_wp_error( $sync_notes ) ) {
				return $sync_notes;
			}

			// Load relationships for the response payload.
			$contact->load( array( 'lists', 'tags' ) );
			if ( class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
				$contact->load( 'custom_fields' );
			}

			if ( ! empty( $changes ) ) {
				ContactUpdateNotifier::fire(
					$contact,
					array(
						'updated_by' => 'admin',
						'changes'    => $changes,
					)
				);
			}

			return new WP_REST_Response( $contact, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get contact notes
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function get_contact_notes( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			$per_page   = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page       = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$activities = ActivityModel::withMorphAppends()
				->notes()
				->forContact( $contact_id )
				->orderBy( 'created_at', 'desc' )
				->paginate( $per_page, array( '*' ), 'page', $page );

			// Transform the paginated data to note format
			$notes_data = collect( $activities->items() )->map(
				function ( $activity ) {
					return $activity->to_note_format();
				}
			)->values()->toArray();

			// Build paginated response matching the expected format
			$paginated_array         = $activities->toArray();
			$paginated_array['data'] = $notes_data;

			return new WP_REST_Response( $paginated_array, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get automation contacts
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response $response The response data.
	 */
	public function get_automation_contacts( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			$per_page = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 10;
			$page     = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
			$contacts = $contact->automation_contacts()->orderBy( 'created_at', 'desc' )->paginate( $per_page, array( '*' ), 'page', $page );
			$contacts->load( 'automation.steps', 'contact', 'processes.step', 'current_step', 'next_step' );

			return new WP_REST_Response( $contacts, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Prepare contact from request
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return array $contact The contact model.
	 */
	protected function prepare_contact( $request ) {
		$contact = array(
			'first_name'                  => $request->get_param( 'first_name' ),
			'last_name'                   => $request->get_param( 'last_name' ),
			'company_name'                => $request->get_param( 'company_name' ),
			'company_registration_number' => $request->get_param( 'company_registration_number' ),
			'tax_vat_number'              => $request->get_param( 'tax_vat_number' ),
			'avatar_id'                   => $request->get_param( 'avatar_id' ),
			'email'                       => $request->get_param( 'email' ),
			'phone'           => $request->get_param( 'phone' ),
			'whatsapp_phone'  => $request->get_param( 'whatsapp_phone' ),
			'address_1'       => $request->get_param( 'address_1' ),
			'address_2'       => $request->get_param( 'address_2' ),
			'city'            => $request->get_param( 'city' ),
			'state'           => $request->get_param( 'state' ),
			'country'         => $request->get_param( 'country' ),
			'zip'             => $request->get_param( 'zip' ),
			'email_status'    => $request->get_param( 'email_status' ),
			'sms_status'      => $request->get_param( 'sms_status' ),
			'whatsapp_status' => $request->get_param( 'whatsapp_status' ),
		);

		foreach ( $contact as $key => $value ) {
			if ( is_null( $value ) ) {
				unset( $contact[ $key ] );
			}
		}

		return ContactModel::normalize_contact_data( $contact );
	}

	/**
	 * Add lists to contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @param ContactModel    $contact The contact model.
	 *
	 * @return void
	 */
	protected function sync_lists( $request, $contact ) {
		try {
			$lists = $request->get_param( 'lists' );
			if ( is_array( $lists ) ) {
				if ( empty( $lists ) ) {
					$contact->sync_lists( array() );
					return;
				}
				$lists_arr = array();

				foreach ( $lists as $list ) {
					if ( isset( $list['type'] ) && 'new' === $list['type'] ) {
						$list = ListModel::create( array( 'name' => $list['name'] ) );
						if ( $list ) {
							$lists_arr[] = $list->id;
						}
					} else {
						$list = ListModel::find( $list['id'] );
						if ( $list ) {
							$lists_arr[] = $list->id;
						}
					}
				}

				$contact->sync_lists( $lists_arr );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Add tags to contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @param ContactModel    $contact The contact model.
	 *
	 * @return void
	 */
	protected function sync_tags( $request, $contact ) {
		try {
			$tags = $request->get_param( 'tags' );
			if ( is_array( $tags ) ) {
				if ( empty( $tags ) ) {
					$contact->sync_tags( array() );
					return;
				}

				$tags_arr = array();

				foreach ( $tags as $tag ) {
					if ( isset( $tag['type'] ) && 'new' === $tag['type'] ) {
						$tag = TagModel::create( array( 'name' => $tag['name'] ) );
						if ( $tag ) {
							$tags_arr[] = $tag->id;
						}
					} else {
						$tag = TagModel::find( $tag['id'] );
						if ( $tag ) {
							$tags_arr[] = $tag->id;
						}
					}
				}

				$contact->sync_tags( $tags_arr );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Sync custom fields to contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return void|WP_Error
	 */
	protected function sync_custom_fields( $request, $contact ) {
		try {
			// Custom fields are PRO-only feature
			if ( ! class_exists( 'DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel' ) ) {
				return;
			}

			$custom_fields = $request->get_param( 'custom_fields' );
			if ( ! $custom_fields ) {
				return;
			}

			$normalized_fields = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::normalize_submission( $custom_fields );
			if ( empty( $normalized_fields ) ) {
				return;
			}

			$custom_fields_arr = array();

			foreach ( $normalized_fields as $field_id => $value ) {
				$custom_field_model = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::find( $field_id );
				if ( ! $custom_field_model ) {
					return new WP_Error( 'error', __( 'Custom field not found', 'doublescale' ), array( 'status' => 400 ) );
				}

				$validated = $custom_field_model->validate_submission_value( $value );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				if ( is_array( $value ) ) {
					$value = implode( ',', $value );
				}

				$custom_fields_arr[ $field_id ] = array(
					'value'       => $value,
					'entity_type' => 'contact',
				);
			}

			$contact->custom_fields()->sync( $custom_fields_arr );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Sync notes to contact
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return void|WP_Error
	 */
	protected function sync_notes( $request, $contact ) {
		try {
			$notes = $request->get_param( 'notes' );
			if ( $notes ) {
				foreach ( $notes as $note ) {
					ActivityModel::create(
						array(
							'contact_id'    => $contact->id,
							'activity_type' => 'note',
							'data'          => array(
								'title' => sanitize_text_field( $note['title'] ?? '' ),
								'type'  => sanitize_text_field( $note['type'] ?? 'note' ),
								'note'  => sanitize_text_field( $note['text'] ?? '' ),
							),
							'user_id'       => get_current_user_id() ?: null,
						)
					);
				}
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get analytics
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_analytics( $request ) {
		try {
			$interval   = $request->get_param( 'interval' ) ? $request->get_param( 'interval' ) : 'last_30_days';
			$start_date = $request->get_param( 'start_date' ) ? $request->get_param( 'start_date' ) : '';
			$end_date   = $request->get_param( 'end_date' ) ? $request->get_param( 'end_date' ) : '';

			if ( 'custom' !== $interval ) {
				$start_date = Utils::get_start_date( $interval, $start_date );
				$end_date   = Utils::get_end_date( $interval, $end_date );
			}

			$dates              = Utils::get_dates_between_dates( $start_date, $end_date );
			$type               = $dates['type'] ?? 'hour';
			$total_contacts     = ContactModel::count();
			$total_subscribed   = ContactModel::where( 'email_status', 'subscribed' )->count();
			$total_unsubscribed = ContactModel::where( 'email_status', 'unsubscribed' )->count();
			$contacts           = array();

			foreach ( $dates['dates'] as $date ) {
				switch ( $type ) {
					case 'hour':
						$contacts[ $date ] = ContactModel::whereBetween( 'created_at', array( $date, gmdate( 'Y-m-d H:i:s', strtotime( $date . ' +1 hour' ) ) ) )->count();
						break;
					case 'day':
						$contacts[ $date ] = ContactModel::whereDay( 'created_at', gmdate( 'd', strtotime( $date ) ) )->count();
						break;
					case 'month':
						$contacts[ $date ] = ContactModel::whereMonth( 'created_at', gmdate( 'm', strtotime( $date ) ) )->count();
						break;
					case 'year':
						$contacts[ $date ] = ContactModel::whereYear( 'created_at', gmdate( 'Y', strtotime( $date ) ) )->count();
						break;
				}
			}

			$analytics = array(
				'contacts'           => $contacts,
				'data'               => $dates,
				'total'              => $total_contacts,
				'total_subscribed'   => $total_subscribed,
				'total_unsubscribed' => $total_unsubscribed,
			);
			return new WP_REST_Response( $analytics, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Send optin email
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function send_opt_in_email( $request ) {
		try {
			$contact_id = $request->get_param( 'id' );
			$contact    = ContactModel::find( $contact_id );

			if ( ! $contact ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}

			if ( ! class_exists( '\DoubleScale\Modules\Emails\Emails' ) ) {
				return new WP_Error(
					'campaigns_unavailable',
					__( 'Double opt-in email is not available in this build.', 'doublescale' ),
					array( 'status' => 501 )
				);
			}

			$result = \DoubleScale\Modules\Emails\Emails::send_double_optin_email( $contact );

			// Log the result for troubleshooting and audit trail.
			if ( ! $result ) {
				LogModel::create(
					array(
						'timestamp' => gmdate( 'Y-m-d H:i:s' ),
						'level'     => 400,
						'message'   => 'Failed to send double opt-in confirmation email',
						'source'    => 'DoubleScale\Modules\Contacts\Rest\Controllers\RestContactController',
						'context'   => array(
							'contact_id' => $contact->id,
							'email'      => $contact->email,
							'reason'     => 'wp_mail() returned false',
							'endpoint'   => '/contacts/' . $contact->id . '/send-opt-in-email',
							'user_id'    => get_current_user_id(),
						),
					)
				);
			} else {
				LogModel::create(
					array(
						'timestamp' => gmdate( 'Y-m-d H:i:s' ),
						'level'     => 600,
						'message'   => 'Double opt-in confirmation email sent successfully',
						'source'    => 'DoubleScale\Modules\Contacts\Rest\Controllers\RestContactController',
						'context'   => array(
							'contact_id' => $contact->id,
							'email'      => $contact->email,
							'user_id'    => get_current_user_id(),
						),
					)
				);
			}

			return new WP_REST_Response( array( 'success' => $result ), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Send message to contact (unified endpoint for email, Sms, WhatsApp)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_message( $request ) {
		$access = Permissions::validate_send_contact_message_access(
			$request->get_param( 'id' ),
			$request->get_param( 'project_id' ),
			$request->get_param( 'deal_id' )
		);
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$channel = $request->get_param( 'channel' );

		// Validate channel parameter.
		if ( ! in_array( $channel, CampaignChannel::get_core_channel_strings(), true ) ) {
			return new WP_Error(
				'invalid_channel',
				__( 'Invalid channel. Must be email, sms, or whatsapp.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Validate email requires subject and body.
		if ( $channel === CampaignChannel::STR_EMAIL && empty( $request->get_param( 'subject' ) ) ) {
			return new WP_Error(
				'missing_subject',
				__( 'Subject is required for email messages.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( $channel === CampaignChannel::STR_EMAIL && empty( $request->get_param( 'body' ) ) ) {
			return new WP_Error(
				'missing_body',
				__( 'Body is required for email messages.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Route to appropriate sender based on channel. SMS and WhatsApp ship with
		// the Pro plugin; in standalone-free mode we return a clear error rather
		// than fatally crashing on a missing class.
		$sender_class_by_channel = array(
			CampaignChannel::STR_EMAIL    => \DoubleScale\Modules\Inbox\IndividualMessaging\EmailIndividualSender::class,
			CampaignChannel::STR_SMS      => '\\DoubleScale\\Modules\\Inbox\\IndividualMessaging\\SmsIndividualSender',
			CampaignChannel::STR_WHATSAPP => '\\DoubleScale\\Modules\\Inbox\\IndividualMessaging\\WhatsappIndividualSender',
		);

		$sender_class = $sender_class_by_channel[ $channel ] ?? null;

		if ( ! $sender_class || ! class_exists( $sender_class ) ) {
			return new WP_Error(
				'sender_unavailable',
				__( 'Sending messages on this channel requires the DoubleScale Pro plugin.', 'doublescale' ),
				array( 'status' => 501 )
			);
		}

		$sender = new $sender_class();

		return $sender->send( $request );
	}

	/**
	 * Check permissions for sending messages (email, Sms, WhatsApp)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function send_message_permissions_check( $request ) {
		return Permissions::can_send_contact_message();
	}

	/**
	 * Add to lists
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function add_to_lists( $request ) {
		try {
			$contact_ids = $request->get_param( 'ids' );
			$list_ids    = $request->get_param( 'list_ids' );

			if ( ! $list_ids ) {
				return new WP_Error( 'error', 'Lists not found', array( 'status' => 404 ) );
			}

			$contacts = ContactModel::find( $contact_ids );
			if ( ! $contacts ) {
				return new WP_Error( 'not_found', 'Contacts not found', array( 'status' => 404 ) );
			}

			foreach ( $contacts as $contact ) {
				$contact->add_lists( $list_ids );
			}

			return new WP_REST_Response( $contacts, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}



	/**
	 * Remove from lists
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function remove_from_lists( $request ) {
		try {
			$contact_ids = $request->get_param( 'ids' );
			$list_ids    = $request->get_param( 'list_ids' );

			if ( ! $list_ids ) {
				return new WP_Error( 'error', 'Lists not found', array( 'status' => 404 ) );
			}

			$contacts = ContactModel::find( $contact_ids );
			if ( ! $contacts ) {
				return new WP_Error( 'not_found', 'Contacts not found', array( 'status' => 404 ) );
			}

			foreach ( $contacts as $contact ) {
				$contact->lists()->detach( $list_ids );
			}

			return new WP_REST_Response( $contacts, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}



	/**
	 * Add tags
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function add_tags( $request ) {
		try {
			$contact_ids = $request->get_param( 'ids' );
			$tags_ids    = $request->get_param( 'tag_ids' );

			if ( ! $tags_ids ) {
				return new WP_Error( 'error', 'Tags not found', array( 'status' => 404 ) );
			}

			$contacts = ContactModel::find( $contact_ids );
			if ( ! $contacts ) {
				return new WP_Error( 'not_found', 'Contacts not found', array( 'status' => 404 ) );
			}

			foreach ( $contacts as $contact ) {
				$contact->add_tags( $tags_ids );
			}

			return new WP_REST_Response( $contacts, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}



	/**
	 * Remove tags
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function remove_tags( $request ) {
		try {
			$contact_ids = $request->get_param( 'ids' );
			$tags_ids    = $request->get_param( 'tag_ids' );

			if ( ! $tags_ids ) {
				return new WP_Error( 'error', 'Tags not found', array( 'status' => 404 ) );
			}

			$contacts = ContactModel::find( $contact_ids );
			if ( ! $contacts ) {
				return new WP_Error( 'not_found', 'Contacts not found', array( 'status' => 404 ) );
			}

			foreach ( $contacts as $contact ) {
				$contact->tags()->detach( $tags_ids );
			}

			return new WP_REST_Response( $contacts, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	// all permissions checks

	/**
	 * User meta key for contacts list column visibility preferences.
	 *
	 * @var string
	 */
	const LIST_COLUMN_VISIBILITY_META_KEY = 'doublescale_contacts_list_column_visibility';

	/**
	 * Get saved contacts list column visibility for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 *
	 * @return array<string, bool>
	 */
	public static function get_list_column_visibility( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$prefs = ListPreferencesManager::get( 'contacts', $user_id );
		if ( ! empty( $prefs['column_visibility'] ) && is_array( $prefs['column_visibility'] ) ) {
			return $prefs['column_visibility'];
		}

		$saved = get_user_meta( $user_id, self::LIST_COLUMN_VISIBILITY_META_KEY, true );

		return is_array( $saved ) ? self::sanitize_column_visibility( $saved ) : array();
	}

	/**
	 * Get contacts list UI preferences for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_list_preferences( $request ) {
		$prefs = ListPreferencesManager::get( 'contacts' );

		return new WP_REST_Response(
			array(
				'column_visibility' => $prefs['column_visibility'] ?? self::get_list_column_visibility(),
				'per_page'          => $prefs['per_page'] ?? null,
				'show_filters'      => $prefs['show_filters'] ?? null,
				'keyword'           => $prefs['keyword'] ?? '',
				'date_range'        => $prefs['date_range'] ?? null,
			),
			200
		);
	}

	/**
	 * Save contacts list UI preferences for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_list_preferences( $request ) {
		$user_id = (int) get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'unauthorized', __( 'User not logged in.', 'doublescale' ), array( 'status' => 401 ) );
		}

		$params = array();
		if ( null !== $request->get_param( 'column_visibility' ) ) {
			$params['column_visibility'] = $this->sanitize_column_visibility( $request->get_param( 'column_visibility' ) );
		}
		if ( null !== $request->get_param( 'per_page' ) ) {
			$params['per_page'] = $request->get_param( 'per_page' );
		}
		if ( null !== $request->get_param( 'show_filters' ) ) {
			$params['show_filters'] = $request->get_param( 'show_filters' );
		}
		if ( null !== $request->get_param( 'keyword' ) ) {
			$params['keyword'] = $request->get_param( 'keyword' );
		}
		if ( null !== $request->get_param( 'date_range' ) ) {
			$params['date_range'] = $request->get_param( 'date_range' );
		}

		$updated = ListPreferencesManager::update( 'contacts', $params, $user_id );
		if ( false === $updated ) {
			return new WP_Error( 'update_failed', __( 'Failed to save list preferences.', 'doublescale' ), array( 'status' => 500 ) );
		}

		if ( isset( $updated['column_visibility'] ) ) {
			update_user_meta( $user_id, self::LIST_COLUMN_VISIBILITY_META_KEY, $updated['column_visibility'] );
		}

		return new WP_REST_Response( $updated, 200 );
	}

	/**
	 * Sanitize contacts list column visibility payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $visibility Raw visibility map.
	 *
	 * @return array<string, bool>
	 */
	private static function sanitize_column_visibility( $visibility ) {
		if ( ! is_array( $visibility ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $visibility as $column => $visible ) {
			$key = sanitize_key( (string) $column );
			if ( '' === $key ) {
				continue;
			}
			$sanitized[ $key ] = (bool) $visible;
		}

		return $sanitized;
	}

	/**
	 * List active file attachments for a contact.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_attachments( $request ) {
		try {
			$contact = $this->get_contact_for_attachment( $request );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			return new WP_REST_Response(
				array(
					'items' => $this->get_contact_attachments_shaped( (int) $contact->id ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Upload a file attachment to a contact.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment( $request ) {
		try {
			$contact = $this->get_contact_for_attachment( $request );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			$files = $request->get_file_params();
			$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
			if ( ! $file ) {
				return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$existing_count = AttachmentModel::forType( self::CONTACT_ATTACHABLE_TYPE )
				->where( 'attachable_id', (int) $contact->id )
				->active()
				->count();

			$too_many = $this->guard_contact_attachment_count( (int) $existing_count );
			if ( $too_many ) {
				return $too_many;
			}

			$service    = $this->attachment_service();
			$attachment = $service->store_upload(
				$file,
				self::CONTACT_ATTACHABLE_TYPE,
				(int) $contact->id,
				array( 'user_id' => get_current_user_id() ),
				array(
					'status'         => 'active',
					'max_size_bytes' => self::CONTACT_ATTACHMENT_MAX_BYTES,
					'meta'           => array( 'contact_id' => (int) $contact->id ),
				)
			);

			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_contact_file_attached', $contact, $attachment );

			return new WP_REST_Response(
				$service->shape_for_api( $attachment ),
				201
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a contact file attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_attachment( $request ) {
		try {
			$contact = $this->get_contact_for_attachment( $request );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}

			$attachment = $this->find_contact_attachment( $contact, (int) $request->get_param( 'attachment_id' ) );
			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_contact_file_removed', $contact, $attachment );

			$attachment->delete();

			return new WP_REST_Response(
				array(
					'deleted' => true,
					'id'      => (int) $request->get_param( 'attachment_id' ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Resolve a contact for attachment endpoints.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return ContactModel|WP_Error
	 */
	private function get_contact_for_attachment( $request ) {
		$contact_id = (int) $request->get_param( 'id' );
		$contact    = ContactModel::find( $contact_id );

		if ( ! $contact ) {
			return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
		}

		return $contact;
	}

	/**
	 * Fetch active contact attachments shaped for API responses.
	 *
	 * @param int $contact_id Contact ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_contact_attachments_shaped( int $contact_id ): array {
		$service = $this->attachment_service();
		$rows    = AttachmentModel::forType( self::CONTACT_ATTACHABLE_TYPE )
			->where( 'attachable_id', $contact_id )
			->active()
			->orderBy( 'created_at', 'desc' )
			->get();

		$shaped = array();
		foreach ( $rows as $attachment ) {
			$shaped[] = $service->shape_for_api( $attachment );
		}

		return $shaped;
	}

	/**
	 * Resolve a contact-scoped attachment row.
	 *
	 * @param ContactModel $contact       Parent contact.
	 * @param int          $attachment_id Attachment row id.
	 * @return AttachmentModel|WP_Error
	 */
	private function find_contact_attachment( $contact, int $attachment_id ) {
		$attachment = AttachmentModel::forType( self::CONTACT_ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $contact->id )
			->where( 'id', $attachment_id )
			->first();

		if ( ! $attachment ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $attachment;
	}

	/**
	 * Enforce the per-contact attachment count cap.
	 *
	 * @param int $existing_count Active attachments already on the contact.
	 * @return WP_Error|null
	 */
	private function guard_contact_attachment_count( int $existing_count ): ?WP_Error {
		if ( $existing_count >= self::CONTACT_ATTACHMENT_MAX_COUNT ) {
			return new WP_Error(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files allowed per contact */
					_n(
						'You can attach at most %d file to this contact.',
						'You can attach at most %d files to this contact.',
						self::CONTACT_ATTACHMENT_MAX_COUNT,
						'doublescale'
					),
					self::CONTACT_ATTACHMENT_MAX_COUNT
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * @return AttachmentService
	 */
	private function attachment_service(): AttachmentService {
		return new AttachmentService();
	}

	/**
	 * Check if a given request has access to get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool $response Permission check result.
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::can_read_contacts();
	}

	/**
	 * Check if a given request has access to create items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool $response Permission check result.
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if a given request has access to delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool $response Permission check result.
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::can_delete_contacts();
	}

	/**
	 * Check if a given request has access to update items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool $response Permission check result.
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool $response Permission check result.
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::can_delete_contacts();
	}

	/**
	 * Check if a given request has access to get a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool $response Permission check result.
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::can_read_contacts();
	}

	/**
	 * Check if a given request has access to get automation contacts
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool $response Permission check result.
	 */
	public function get_automation_contacts_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Send optin email permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function send_opt_in_email_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Get analytics permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_analytics_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Add to lists permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function add_to_lists_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Remove from lists permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function remove_from_lists_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Add tags permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function add_tags_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Remove tags permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function remove_tags_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to get purchase history
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function get_purchase_history_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Normalize the contacts list "filters" query/body param for Contact_Filters_Process.
	 *
	 * Some clients send JSON as a string, or nested stdClass from decode paths; Process expects arrays.
	 *
	 * @param mixed $filters Raw filters param.
	 *
	 * @return array|null
	 */
	private function normalize_contact_filters_param( $filters ) {
		if ( null === $filters || false === $filters || '' === $filters ) {
			return null;
		}
		if ( is_string( $filters ) ) {
			$decoded = json_decode( $filters, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return null;
			}
			$filters = $decoded;
		} elseif ( is_object( $filters ) ) {
			$decoded = json_decode( wp_json_encode( $filters ), true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			$filters = $decoded;
		}
		if ( ! is_array( $filters ) ) {
			return null;
		}
		if ( empty( $filters ) ) {
			return $filters;
		}
		return map_deep(
			$filters,
			static function ( $value ) {
				return $value;
			}
		);
	}
}
