<?php

/**
 * Class RestTemplateController
 * This class is responsible for handling the rest api requests for templates
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Rest\Controllers;

use DoubleScale\Modules\Emails\EmailAttachmentResolver;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Emails\EmailRenderer;
use DoubleScale\Modules\Emails\BlockRegistry;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * RestTemplateController class
 */
class RestTemplateController extends RestController {


	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'templates';

	/**
	 * Register the routes for the objects of the controller.
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
					'args'                => $this->get_collection_params(),
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
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Smart save endpoint - creates or updates based on usage
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/save',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_template' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		// Register endpoint for rendering templates (merged from email-builder controller)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/render',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'render_template' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'merge_tags'  => array(
							'description' => __( 'Merge tags to use in the template', 'doublescale' ),
							'type'        => 'object',
							'default'     => array(),
						),
						'contact_id'  => array(
							'description' => __( 'Contact ID to use for merge tags', 'doublescale' ),
							'type'        => 'integer',
							'default'     => null,
						),
						'tracking_id' => array(
							'description' => __( 'Communication tracking ID to use stored merge tag values', 'doublescale' ),
							'type'        => 'integer',
							'default'     => null,
						),
						'preview'     => array(
							'description' => __( 'Whether this is a preview render (strips tracking elements). If not provided, auto-detected based on context: true when no contact_id/tracking_id, false otherwise.', 'doublescale' ),
							'type'        => 'boolean',
							'default'     => null,
						),
					),
				),
			)
		);

		// Register endpoint for getting user templates (non-hidden)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/user-templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_user_templates' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'type'   => array(
							'description' => __( 'Filter by template type.', 'doublescale' ),
							'type'        => 'string',
							'default'     => CampaignChannel::STR_EMAIL,
							'enum'        => CampaignChannel::get_core_channel_strings(),
						),
						'search' => array(
							'description'       => __( 'Search templates by name.', 'doublescale' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Register endpoint for getting email blocks (merged from email-builder controller)
		register_rest_route(
			$this->namespace,
			'/email-blocks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_blocks' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Schema for the template
	 *
	 * @since 1.0.0
	 *
	 * @return array $schema The template schema
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'template',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'       => array(
					'description' => __( 'Name of the template.', 'doublescale' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'type'       => array(
					'description' => __( 'Type of the template.', 'doublescale' ),
					'type'        => 'string',
					'enum'        => CampaignChannel::get_core_channel_strings(),
				),
				'body'       => array(
					'description' => __( 'Body of the template.', 'doublescale' ),
					'type'        => 'string',
					'required'    => false,
				),
				'settings'   => array(
					'description' => __( 'Settings of the template (includes subject, preview_text, from_name, from_email, etc).', 'doublescale' ),
					'type'        => array( 'object', 'null' ),
				),
				'created_at' => array(
					'description' => __( 'Creation time of the template.', 'doublescale' ),
					'type'        => 'string',
					'readonly'    => false,
				),
				'updated_at' => array(
					'description' => __( 'Update time of the template.', 'doublescale' ),
					'type'        => 'string',
					'readonly'    => false,
				),
				'thumbnail'  => array(
					'description' => __( 'Thumbnail URL of the template.', 'doublescale' ),
					'type'        => 'string',
					'required'    => false,
					'arg_options' => array(
						'sanitize_callback' => 'esc_url_raw',
					),
				),
				'hidden'     => array(
					'description' => __( 'Whether the template is hidden from users.', 'doublescale' ),
					'type'        => 'boolean',
					'required'    => false,
					'default'     => false,
				),
			),
		);
	}

	/**
	 * Get collection params
	 * Enhanced with filtering options
	 *
	 * @since 1.0.0
	 *
	 * @return array $params The collection params
	 */
	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description'       => __( 'Limit results to those matching a string.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'   => array(
				'description'       => __( 'Search templates by name.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'     => array(
				'description' => __( 'Filter by template type.', 'doublescale' ),
				'type'        => 'string',
				'default'     => CampaignChannel::STR_EMAIL,
				'enum'        => CampaignChannel::get_core_channel_strings(),
			),
			'category' => array(
				'description'       => __( 'Filter by template category.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hidden'   => array(
				'description' => __( 'Filter by hidden status.', 'doublescale' ),
				'type'        => 'integer',
				'enum'        => array( 0, 1 ),
			),
			'is_pro'   => array(
				'description' => __( 'Filter by pro status.', 'doublescale' ),
				'type'        => 'integer',
				'enum'        => array( 0, 1 ),
			),
			'per_page' => array(
				'description' => __( 'Number of items to return in one page.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 20,
			),
			'page'     => array(
				'description' => __( 'Current page of the collection.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
			),
			'orderby'  => array(
				'description' => __( 'Order by field.', 'doublescale' ),
				'type'        => 'string',
				'default'     => 'id',
			),
			'order'    => array(
				'description' => __( 'Order direction.', 'doublescale' ),
				'type'        => 'string',
				'default'     => 'DESC',
				'enum'        => array( 'ASC', 'DESC' ),
			),
		);
	}

	/**
	 * Get items
	 * Enhanced with better filtering support
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function get_items( $request ) {
		try {
			// Get parameters with defaults
			$type     = $request->get_param( 'type' ) ?: CampaignChannel::STR_EMAIL;
			$per_page = (int) ( $request->get_param( 'per_page' ) ?: 20 );
			$page     = (int) ( $request->get_param( 'page' ) ?: 1 );
			$orderby  = $request->get_param( 'orderby' ) ?: 'id';
			$order    = $request->get_param( 'order' ) ?: 'DESC';
			$search   = $request->get_param( 'search' );
			$keyword  = $request->get_param( 'keyword' ); // Backward compatibility
			$category = $request->get_param( 'category' );

			// Convert string type to integer for database query
			$type_int = CampaignChannel::to_integer( $type ) ?? CampaignChannel::CHANNEL_EMAIL;

			// Build query
			$query = TemplateModel::where( 'type', $type_int );

			// Add conditional filters
			if ( $request->has_param( 'hidden' ) ) {
				$query->where( 'hidden', (int) $request->get_param( 'hidden' ) );
			} else {
				// Default: show only non-hidden templates
				$query->where( 'hidden', 0 );
			}

			if ( $request->has_param( 'is_pro' ) ) {
				$query->where( 'is_pro', (int) $request->get_param( 'is_pro' ) );
			}

			if ( $category ) {
				$query->where( 'category', $category );
			}

			// Search by name (support both 'search' and 'keyword' for backward compatibility)
			$search_term = $search ?: $keyword;
			if ( $search_term ) {
				$query->where( 'name', 'LIKE', '%' . $search_term . '%' );
			}

			// Get total count for pagination
			$total = $query->count();

			// Get paginated results with ordering
			$templates = $query->orderBy( $orderby, $order )
				->offset( ( $page - 1 ) * $per_page )
				->limit( $per_page )
				->get();

			return new WP_REST_Response(
				array(
					'templates' => $templates,
					'total'     => (int) $total,
					'pages'     => ceil( $total / $per_page ),
					'page'      => $page,
					'per_page'  => $per_page,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get user templates (non-hidden only)
	 * Dedicated endpoint for user-created templates
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function get_user_templates( $request ) {
		try {
			// Get parameters with defaults
			$type   = $request->get_param( 'type' ) ?: CampaignChannel::STR_EMAIL;
			$search = $request->get_param( 'search' );

			// Convert string type to integer for database query
			$type_int = CampaignChannel::to_integer( $type ) ?? CampaignChannel::CHANNEL_EMAIL;

			// Build query - only non-hidden templates
			$query = TemplateModel::where( 'type', $type_int )
				->where( 'hidden', 0 ); // Only user-created templates

			// Search by name if provided
			if ( $search ) {
				$query->where( 'name', 'LIKE', '%' . $search . '%' );
			}

			// Get results ordered by most recent first
			$templates = $query->orderBy( 'created_at', 'DESC' )
				->get();

			return new WP_REST_Response( $templates, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function get_item( $request ) {
		try {
			$template_id = $request->get_param( 'id' );
			$template    = TemplateModel::find( $template_id );

			if ( ! $template ) {
				return new WP_Error( 'error', __( 'Template not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $template, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function create_item( $request ) {
		try {
			$template_data = $this->prepare_template( $request );
			$template      = TemplateModel::create( $template_data );

			return new WP_REST_Response( $template, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function update_item( $request ) {
		try {
			$template_id = $request->get_param( 'id' );
			$template    = TemplateModel::find( $template_id );

			if ( ! $template ) {
				return new WP_Error( 'error', __( 'Template not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$template_data = $this->prepare_template( $request );
			$template->update( $template_data );

			return new WP_REST_Response( $template, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Smart save - creates or updates based on template usage
	 *
	 * Logic:
	 * - No ID: Create new template
	 * - ID exists + template in use (tracked): Create new template (preserve original)
	 * - ID exists + template NOT in use: Update existing template
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function save_template( $request ) {
		try {
			$template_id   = $request->get_param( 'id' );
			$campaign_id   = $request->get_param( 'campaign_id' );
			$template_data = $this->prepare_template( $request );

			// Case 1: No ID - create new template
			if ( ! $template_id ) {
				$template = TemplateModel::create( $template_data );

				// Update campaign to use new template ID
				if ( $campaign_id ) {
					$this->add_template_to_campaign( $campaign_id, $template->id );
				}

				return new WP_REST_Response( $template, 201 );
			}

			// Case 2 & 3: ID exists - check if template is in use
			$template = TemplateModel::find( $template_id );

			if ( ! $template ) {
				return new WP_Error( 'error', __( 'Template not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			// Check if template has been used in any sent messages
			if ( TemplateModel::is_used_in_tracking( $template_id ) ) {
				// Template is in use - create new copy to preserve original
				unset( $template_data['id'] );
				$new_template = TemplateModel::create( $template_data );

				// Update campaign to use new template ID
				if ( $campaign_id ) {
					$this->update_campaign_template_id( $campaign_id, $template_id, $new_template->id );
				}

				return new WP_REST_Response( $new_template, 201 );
			} else {
				// Template is NOT in use - safe to update
				$template->update( $template_data );
				return new WP_REST_Response( $template, 200 );
			}
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Add template ID to campaign's template_ids (for first-time creation)
	 *
	 * @param int $campaign_id Campaign ID
	 * @param int $template_id Template ID to add
	 */
	private function add_template_to_campaign( $campaign_id, $template_id ) {
		$campaign = CampaignModel::find( $campaign_id );

		if ( ! $campaign ) {
			return;
		}

		$settings = is_array( $campaign->settings ) ? $campaign->settings : json_decode( $campaign->settings, true );

		// Initialize template_ids array if it doesn't exist
		if ( ! isset( $settings['template_ids'] ) ) {
			$settings['template_ids'] = array();
		}

		// Add template ID if not already in array
		if ( ! in_array( $template_id, $settings['template_ids'] ) ) {
			$settings['template_ids'][] = $template_id;
		}

		// Save campaign
		$campaign->update( array( 'settings' => $settings ) );
	}

	/**
	 * Update campaign's template_ids when a new template is created (replaces old ID)
	 *
	 * @param int $campaign_id Campaign ID
	 * @param int $old_template_id Old template ID
	 * @param int $new_template_id New template ID
	 */
	private function update_campaign_template_id( $campaign_id, $old_template_id, $new_template_id ) {
		$campaign = CampaignModel::find( $campaign_id );

		if ( ! $campaign ) {
			return;
		}

		$settings = is_array( $campaign->settings ) ? $campaign->settings : json_decode( $campaign->settings, true );

		// Update template_ids array
		if ( isset( $settings['template_ids'] ) && is_array( $settings['template_ids'] ) ) {
			$key = array_search( $old_template_id, $settings['template_ids'] );
			if ( $key !== false ) {
				$settings['template_ids'][ $key ] = $new_template_id;
			}
		}

		// Save campaign
		$campaign->update( array( 'settings' => $settings ) );
	}

	/**
	 * Delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function delete_items( $request ) {
		try {
			$template_ids = $request->get_param( 'ids' );
			$templates    = TemplateModel::find_many( $template_ids );

			if ( ! $templates ) {
				return new WP_Error( 'error', __( 'Templates not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			TemplateModel::destroy( $template_ids );

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response $response The response object
	 */
	public function delete_item( $request ) {
		try {
			$template_id = $request->get_param( 'id' );
			$template    = TemplateModel::find( $template_id );

			if ( ! $template ) {
				return new WP_Error( 'error', __( 'Template not found', 'doublescale' ), array( 'status' => 404 ) );
			}

			$template->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare template
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return array $template_data The template data
	 */
	public function prepare_template( $request ) {
		$type = $request->get_param( 'type' ) ?? CampaignChannel::STR_EMAIL;
		$name = $request->get_param( 'name' );

		// Get settings from request, or initialize as empty array
		$settings = $request->get_param( 'settings' ) ?? array();

		// Legacy top-level subject/preview_text are merged only when missing from settings.
		$subject      = $request->get_param( 'subject' );
		$preview_text = $request->get_param( 'preview_text' );

		// Prefer nested settings; top-level fields are legacy only.
		if ( $subject !== null && ! array_key_exists( 'subject', $settings ) ) {
			$settings['subject'] = $subject;
		}

		if ( $preview_text !== null && ! array_key_exists( 'preview_text', $settings ) ) {
			$settings['preview_text'] = $preview_text;
		}

		if ( isset( $settings['attachments'] ) ) {
			$settings['attachments'] = EmailAttachmentResolver::sanitize_attachments( $settings['attachments'] );
		}

		$template_data = array(
			'id'        => $request->get_param( 'id' ),
			'type'      => $type,
			'body'      => $request->get_param( 'body' ),
			'settings'  => $settings,
			'thumbnail' => $request->get_param( 'thumbnail' ),
			'hidden'    => $request->get_param( 'hidden' ) ?? false,
		);

		// Only set name if provided, otherwise leave it out (for updates that don't change name)
		if ( $name !== null ) {
			$template_data['name'] = $name ?: 'New Template';
		}

		// Note: email_body data is now sent directly in the body field as JSON

		// Don't remove thumbnail field - allow empty strings to be saved
		// Don't remove hidden field - allow false values to be saved
		foreach ( $template_data as $key => $value ) {
			if ( $key === 'thumbnail' || $key === 'hidden' ) {
				continue;
			}

			// For ID: keep if it has a value, remove if null/empty
			if ( empty( $value ) && $value !== '0' && $value !== 0 && $value !== false ) {
				unset( $template_data[ $key ] );
			}
		}

		return $template_data;
	}

	/**
	 * Get items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return bool $permission The permission
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Get item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return bool $permission The permission
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Create item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return bool $permission The permission
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Update item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return bool $permission The permission
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Delete items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return bool $permission The permission
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Delete item permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return bool $permission The permission
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Render a template
	 * Merged from REST_Email_Builder_Controller
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response|WP_Error The response object
	 */
	public function render_template( $request ) {
		$template_id      = (int) $request->get_param( 'id' );
		$merge_tags       = $request->get_param( 'merge_tags' ) ?: array();
		$contact_id       = $request->get_param( 'contact_id' );
		$tracking_id      = $request->get_param( 'tracking_id' );
		$explicit_preview = $request->get_param( 'preview' );

		// Get contact - prioritize contact_id parameter, then extract from merge_tags
		$contact = null;
		if ( ! empty( $contact_id ) ) {
			$contact = ContactModel::find( (int) $contact_id );
		} elseif ( ! empty( $merge_tags ) ) {
			// Extract contact from merge_tags if provided (for backward compatibility)
			// Check if contact is in 'contact' key
			if ( isset( $merge_tags['contact'] ) && ( $merge_tags['contact'] instanceof ContactModel || $merge_tags['contact'] instanceof AutomationContactModel ) ) {
				$contact = $merge_tags['contact'];
			} elseif ( ! empty( $merge_tags[0] ) && ( $merge_tags[0] instanceof ContactModel || $merge_tags[0] instanceof AutomationContactModel ) ) {
				$contact = $merge_tags[0];
			}
		}

		// Auto-detect tracking context if contact_id is provided but tracking_id is not
		if ( ! $tracking_id && $contact_id ) {
			$tracking_id = $this->find_tracking_id_for_contact( $contact_id );
		}

		// Smart preview detection logic
		// 1. If preview parameter is explicitly provided, use that value (backward compatibility)
		// 2. If no contact_id and no tracking_id → auto-enable preview mode (template preview)
		// 3. If contact_id provided → normal processing (fresh or stored based on tracking context)
		if ( ! is_null( $explicit_preview ) ) {
			// Explicit preview parameter takes precedence
			$is_preview = (bool) $explicit_preview;
		} elseif ( empty( $contact_id ) && empty( $tracking_id ) ) {
			// No contact or tracking context = template preview mode
			$is_preview = true;
		} else {
			// Contact provided = normal processing (not preview)
			$is_preview = false;
		}

		// Use standard EmailRenderer with tracking context (automatically handles stored values)
		$renderer = new EmailRenderer();
		$html     = $renderer->render_template( $template_id, $contact, $tracking_id );

		if ( empty( $html ) ) {
			return new WP_Error(
				'rendering_failed',
				__( 'Failed to render template', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		// Strip tracking elements if this is a preview render.
		// This prevents admin previews from triggering open/click tracking.
		if ( $is_preview ) {
			$html = $this->strip_tracking_elements( $html );
		}

		return rest_ensure_response(
			array(
				'html' => $html,
			)
		);
	}

	/**
	 * Find the most recent tracking ID for a contact and template combination
	 * This enables automatic detection of tracking context for historical rendering
	 *
	 * @since 1.0.0
	 *
	 * @param int $contact_id Contact ID
	 *
	 * @return int|null Tracking ID or null if not found
	 */
	private function find_tracking_id_for_contact( $contact_id ) {
		// Find the most recent tracking record for this contact
		$tracking = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel::where( 'contact_id', $contact_id )
			->orderBy( 'created_at', 'desc' )
			->first();

		return $tracking ? $tracking->id : null;
	}

	/**
	 * Strip tracking elements from rendered HTML.
	 * Removes tracking pixels and restores original URLs from click tracking links.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html The HTML to strip tracking from.
	 *
	 * @return string HTML with tracking elements removed.
	 */
	private function strip_tracking_elements( $html ) {
		// Remove tracking pixel images (doublescale=email_open).
		$html = preg_replace(
			'/<img[^>]*doublescale=email_open[^>]*>/i',
			'',
			$html
		);

		// Replace click tracking links with original URLs.
		// Pattern: href="...?doublescale=email_click&hash_key=xxx&original=encoded_url".
		$html = preg_replace_callback(
			'/href=["\']([^"\']*\?[^"\']*doublescale=email_click[^"\']*)["\']/',
			function ( $matches ) {
				$tracking_url = $matches[1];

				// Parse the URL to extract the 'original' parameter.
				$parsed_url = wp_parse_url( $tracking_url );
				if ( isset( $parsed_url['query'] ) ) {
					parse_str( $parsed_url['query'], $query_params );

					// If we have an original URL, use it.
					if ( isset( $query_params['original'] ) ) {
						$original_url = urldecode( $query_params['original'] );
						return 'href="' . esc_url( $original_url ) . '"';
					}
				}

				// If we can't extract original URL, return as-is.
				return $matches[0];
			},
			$html
		);

		return $html;
	}

	/**
	 * Get available email blocks
	 * Merged from REST_Email_Builder_Controller
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object
	 *
	 * @return WP_REST_Response The response object
	 */
	public function get_blocks( $request ) {
		$registry = BlockRegistry::instance();
		$blocks   = $registry->get_blocks();

		$response = array();
		foreach ( $blocks as $block ) {
			$response[] = array(
				'type'         => $block->get_type(),
				'name'         => $block->get_name(),
				'defaultProps' => $block->get_default_props(),
			);
		}

		return rest_ensure_response( $response );
	}
}
