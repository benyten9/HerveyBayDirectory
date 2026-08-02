<?php
/**
 * Meta WhatsApp Templates REST Controller
 *
 * REST Api endpoints for Meta WhatsApp template management
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Rest\Controllers;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use DoubleScale\Modules\Campaigns\Services\MetaTemplateFetcher;
use DoubleScale\Modules\Campaigns\Services\MetaTemplateSaver;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;

defined( 'ABSPATH' ) || exit;

/**
 * RestMetaWhatsappTemplatesController class
 */
class RestMetaWhatsappTemplatesController extends WP_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1';

	/**
	 * Rest base
	 *
	 * @var string
	 */
	protected $rest_base = 'meta-whatsapp/templates';

	/**
	 * Register routes
	 *
	 * @return void
	 */
	public function register_routes() {
		// Get templates from Meta Api
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// Save template to local database
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/save',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_template' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'sid'      => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Template external ID (name:language)', 'doublescale'),
						),
						'name'     => array(
							'required' => true,
							'type'     => 'string',
						),
						'body'     => array(
							'required' => true,
							'type'     => 'string',
						),
						'settings' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		// Update variable mappings
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/mappings',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_mappings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id'       => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'mappings' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		// Sync templates from Meta Api
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_templates' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
				),
			)
		);
	}

	/**
	 * Check permissions
	 *
	 * @return bool True if user has permission.
	 */
	public function check_permissions() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check admin permissions
	 *
	 * @return bool True if user has admin permission.
	 */
	public function check_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get templates from Meta Api
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_templates( $request ) {
		try {
			$fetcher   = new MetaTemplateFetcher();
			$templates = $fetcher->fetch_approved_templates();

			return new WP_REST_Response(
				array(
					'success'   => true,
					'templates' => $templates,
				),
				200
			);
		} catch ( \Exception $e ) {
			$is_config_error = str_contains( $e->getMessage(), 'not configured' );

			return new WP_Error(
				'fetch_failed',
				$e->getMessage(),
				array( 'status' => $is_config_error ? 400 : 500 )
			);
		}
	}

	/**
	 * Save template to local database
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function save_template( $request ) {
		try {
			$template_data = array(
				'sid'      => $request->get_param( 'sid' ),
				'name'     => $request->get_param( 'name' ),
				'body'     => $request->get_param( 'body' ),
				'category' => $request->get_param( 'category' ) ?? 'UTILITY',
				'language' => $request->get_param( 'language' ) ?? 'en',
				'settings' => $request->get_param( 'settings' ),
			);

			$saver    = new MetaTemplateSaver();
			$template = $saver->save_on_use( $template_data );

			return new WP_REST_Response(
				array(
					'success'     => true,
					'template_id' => $template->id,
					'message'     => __( 'Template saved successfully', 'doublescale'),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'save_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Update variable mappings for a template
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function update_mappings( $request ) {
		$template_id = $request->get_param( 'id' );
		$mappings    = $request->get_param( 'mappings' );

		$template = TemplateModel::find( $template_id );
		if ( ! $template ) {
			return new WP_Error(
				'template_not_found',
				__( 'Template not found', 'doublescale'),
				array( 'status' => 404 )
			);
		}

		try {
			$saver = new MetaTemplateSaver();
			$saver->update_variable_mappings( $template, $mappings );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Variable mappings updated successfully', 'doublescale'),
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'update_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Sync templates from Meta Api to local database
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function sync_templates( $request ) {
		try {
			$saver  = new MetaTemplateSaver();
			$result = $saver->sync_from_meta();

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf(
						/* translators: %1$d: created count, %2$d: updated count, %3$d: total count */
						__( 'Synced templates: %1$d created, %2$d updated (%3$d total)', 'doublescale'),
						$result['created'],
						$result['updated'],
						$result['total']
					),
					'data'    => $result,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'sync_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}





