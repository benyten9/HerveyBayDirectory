<?php

/**
 * REST Api: Import_Export Controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Api
 */

namespace DoubleScale\Modules\Contacts\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use Exception;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Contacts\ImportExport\Security;
use DoubleScale\Modules\Contacts\ImportExport\Importers\Manager;

/**
 * Import_Export Controller
 */
class RestImportExportController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'import-export';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/export",
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'export' ),
					'permission_callback' => array( $this, 'import_export_permissions_check' ),
					'args'                => array(
						'file_id' => array(
							'required' => false,
							'type'     => array( 'string', 'integer' ),
						),
						'offset'  => array(
							'required' => false,
							'type'     => 'integer',
						),
						'fields'  => array(
							'required'             => false,
							'type'                 => 'array',
							'items'                => array(
								'type' => 'string',
							),
							'additionalProperties' => true,
						),
						'filters' => array(
							'description' => __( 'Filters to apply.', 'doublescale' ),
							'type'        => 'array',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/import",
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => array( $this, 'import_export_permissions_check' ),
					'args'                => array(
						'source'                => array(
							'required' => true,
							'type'     => 'string',
						),
						'offset'                => array(
							'required' => false,
							'type'     => 'integer',
						),
						'file_name'             => array(
							'required' => false,
							'type'     => 'string',
						),
						'mapping'               => array(
							'required'             => false,
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'lists_mapping'         => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array(
								'type' => 'object',
							),
						),
						'tags_mapping'          => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array(
								'type' => 'object',
							),
						),
						'custom_fields_mapping' => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array(
								'type' => 'object',
							),
						),
						'lists'                 => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array(
								'type' => 'number',
							),
						),
						'tags'                  => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array(
								'type' => 'number',
							),
						),
						'status'                => array(
							'required' => false,
							'type'     => 'string',
						),
						'update_existing'       => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'send_double_optin'     => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'credentials'           => array(
							'required' => false,
							'type'     => 'object',
						),
						'membership_filter'     => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
							),
						),
						'phone_is_whatsapp'     => array(
							'required' => false,
							'type'     => 'boolean',
						),
					),
				),
			)
		);

		// Upload and analyze file
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/upload",
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => array( $this, 'import_export_permissions_check' ),
				),
			)
		);

		// Download file
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/download",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download' ),
					'permission_callback' => array( $this, 'import_export_permissions_check' ),
					'args'                => array(
						'file_id' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		$this->register_importer_routes();
		$this->register_oauth_routes();
	}

	/**
	 * Register importer routes
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_importer_routes() {
		$importers = Manager::instance()->get_importers();
		foreach ( $importers as $importer ) {
			register_rest_route(
				$this->namespace,
				"/{$this->rest_base}/{$importer->slug}",
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function ( $request ) use ( $importer ) {
							try {
								$credentials = $request->get_param( 'credentials' ) ?? array();
								$importer->set_credentials( $credentials );
								$fields = $importer->get_fields();

								return new WP_REST_Response( $fields, 200 );
							} catch ( Exception $e ) {
								return new WP_Error( 'importer_error', $e->getMessage(), array( 'status' => 500 ) );
							}
						},
						'permission_callback' => array( $this, 'import_export_permissions_check' ),
						'args'                => array(
							'credentials' => array(
								'required' => false,
								'type'     => 'object',
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Register OAuth routes
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_oauth_routes() {
		// Get OAuth authorization URL
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/oauth/authorize",
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_oauth_authorization_url' ),
					'permission_callback' => array( $this, 'import_export_permissions_check' ),
					'args'                => array(
						'provider'  => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'gohighlevel' ),
						),
						'client_id' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		// Get OAuth connection status
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/oauth/status",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_oauth_status' ),
					'permission_callback' => array( $this, 'import_export_permissions_check' ),
					'args'                => array(
						'provider' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'gohighlevel' ),
						),
					),
				),
			)
		);

		// Clear OAuth connection
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/oauth/disconnect",
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'disconnect_oauth' ),
					'permission_callback' => array( $this, 'import_export_permissions_check' ),
					'args'                => array(
						'provider' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'gohighlevel' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Get OAuth authorization URL
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_oauth_authorization_url( $request ) {
		$provider = $request->get_param( 'provider' );

		$client_id     = $request->get_param( 'client_id' );
		$client_secret = $request->get_param( 'client_secret' );

		switch ( $provider ) {
			case 'gohighlevel':
				$auth_url = admin_url( 'admin.php?doublescale-ghl=authorize&client_id=' . urlencode( $client_id ) . '&client_secret=' . urlencode( $client_secret ) );

				return new WP_REST_Response(
					array(
						'authorization_url' => $auth_url,
					),
					200
				);

			default:
				return new WP_Error(
					'invalid_provider',
					__( 'Invalid OAuth provider', 'doublescale' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Get OAuth connection status
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_oauth_status( $request ) {
		$provider = $request->get_param( 'provider' );

		switch ( $provider ) {
			case 'gohighlevel':
				if ( ! class_exists( '\DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth' ) ) {
					return new WP_REST_Response(
						array(
							'connected' => false,
						),
						200
					);
				}
				$tokens = \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::get_stored_tokens();
				if ( $tokens ) {
					return new WP_REST_Response(
						array(
							'connected'    => true,
							'connected_at' => $tokens['created_at'],
							'expires_at'   => $tokens['expires_at'],
							'expires_in'   => max( 0, $tokens['expires_at'] - time() ),
						),
						200
					);
				} else {
					return new WP_REST_Response(
						array(
							'connected' => false,
						),
						200
					);
				}

			default:
				return new WP_REST_Response(
					array(
						'connected' => false,
						'error'     => 'Invalid provider',
					),
					400
				);
		}
	}

	/**
	 * Disconnect OAuth connection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect_oauth( $request ) {
		$provider = $request->get_param( 'provider' );

		switch ( $provider ) {
			case 'gohighlevel':
				if ( ! class_exists( '\DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth' ) ) {
					return new WP_REST_Response(
						array(
							'success' => false,
							'message' => __( 'GoHighLevel integration is not available.', 'doublescale' ),
						),
						200
					);
				}
				$result = \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::clear_stored_tokens();
				return new WP_REST_Response(
					array(
						'success' => $result,
						'message' => $result
							? __( 'GoHighLevel connection cleared', 'doublescale' )
							: __( 'No connection to clear', 'doublescale' ),
					),
					200
				);

			default:
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Invalid provider',
					),
					400
				);
		}
	}


	/**
	 * Download
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function download( $request ) {
		$file_id = $request->get_param( 'file_id' );
		$file    = Security::get_upload_file_path( 'doublescale-export-' . $file_id . '.csv' );

		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'file_not_found', 'File not found', array( 'status' => 404 ) );
		}

		$file_name = basename( $file );
		$file_size = filesize( $file );

		// Set headers.
		header( 'Content-Type: application/csv' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . $file_size );

		// Check if file exists.
		if ( file_exists( $file ) ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			// Read file.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Csv file content output for download.
			echo $wp_filesystem->get_contents( $file );

			// Delete file.
			wp_delete_file( $file );
		}

		exit;
	}

	/**
	 * Export
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( $request ) {
		// Check if Pro plugin provides the Export class
		if ( ! class_exists( 'DoubleScale\Modules\Contacts\ImportExport\Export' ) ) {
			return new WP_Error(
				'pro_feature',
				__( 'Contact export is a Pro feature. Please upgrade to Plugin Pro.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		// Delegate to Pro plugin's Export class
		$file_id = $request->get_param( 'file_id' ) ? $request->get_param( 'file_id' ) : time();
		$offset  = $request->get_param( 'offset' ) ?? 0;
		$fields  = $request->get_param( 'fields' ) ?? array();
		$filters = $request->get_param( 'filters' ) ?? array();

		$args = array(
			'file_id' => $file_id,
			'offset'  => $offset,
			'fields'  => $fields,
			'filters' => $filters,
		);

		$exporter = new \DoubleScale\Modules\Contacts\ImportExport\Export( $args );
		$result   = $exporter->export();

		// Check if export returned an error
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['file_id'] = $file_id;

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Upload
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function upload( $request ) {
		$file = $request->get_file_params();
		if ( ! $file ) {
			return new WP_Error( 'no_file', 'No file provided', array( 'status' => 400 ) );
		}

		// WP Filesystem.
		global $wp_filesystem;

		// Check if WP Filesystem is loaded.
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! Security::prepare_upload_dir() ) {
			return new WP_Error( 'filesystem_error', 'Failed to create upload directory', array( 'status' => 500 ) );
		}

		$upload_dir = Security::get_upload_dir();
		$file_name  = time() . '_' . $file['file']['name'];
		$file_path  = $upload_dir . '/' . $file_name;

		if ( ! $wp_filesystem->move( $file['file']['tmp_name'], $file_path ) ) {
			return new WP_Error( 'move_error', 'Failed to move file', array( 'status' => 500 ) );
		}

		$header_columns = $this->get_header_columns( $file_path );

		return new WP_REST_Response(
			array(
				'file_name'      => $file_name,
				'header_columns' => $header_columns,
			),
			200
		);
	}

	/**
	 * Get header columns
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path File path.
	 *
	 * @return array
	 */
	public function get_header_columns( $file_path ) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$contents = $wp_filesystem->get_contents( $file_path );
		if ( false !== $contents ) {
			$lines = explode( "\n", $contents );
			if ( ! empty( $lines[0] ) ) {
				return str_getcsv( $lines[0] );
			}
		}

		return null;
	}

	/**
	 * Import
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function import( $request ) {
		$source                = $request->get_param( 'source' ) ?? 'csv';
		$offset                = $request->get_param( 'offset' ) ?? 0;
		$cursor                = $request->get_param( 'cursor' ) ?? null;
		$file_name             = $request->get_param( 'file_name' ) ?? '';
		$mapping               = $request->get_param( 'mapping' ) ?? array();
		$lists_mapping         = $request->get_param( 'lists_mapping' ) ?? array();
		$tags_mapping          = $request->get_param( 'tags_mapping' ) ?? array();
		$custom_fields_mapping = $request->get_param( 'custom_fields_mapping' ) ?? array();
		$lists                 = $request->get_param( 'lists' ) ?? array();
		$tags                  = $request->get_param( 'tags' ) ?? array();
		$status                = $request->get_param( 'status' ) ?? 'subscribed';
		$update_existing       = $request->get_param( 'update_existing' ) ?? false;
		$send_double_optin     = $request->get_param( 'send_double_optin' ) ?? false;
		$credentials           = $request->get_param( 'credentials' ) ?? array();
		$membership_filter     = $request->get_param( 'membership_filter' ) ?? array();
		$phone_is_whatsapp     = $request->get_param( 'phone_is_whatsapp' );
		$result                = array();

		$args = array(
			'offset'                => $offset,
			'cursor'                => $cursor,
			'status'                => $status,
			'update_existing'       => $update_existing,
			'send_double_optin'     => $send_double_optin,
			'lists_mapping'         => $lists_mapping,
			'tags_mapping'          => $tags_mapping,
			'custom_fields_mapping' => $custom_fields_mapping,
			'lists'                 => $lists,
			'tags'                  => $tags,
			'file_name'             => $file_name,
			'mapping'               => $mapping,
			'credentials'           => $credentials,
			'membership_filter'     => $membership_filter,
			'phone_is_whatsapp'     => $phone_is_whatsapp,
		);

		try {
			$importer = Manager::instance()->get_importer( $source );
			$importer = new $importer( $args );
			$result   = $importer->import();

			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'import_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Import and export permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function import_export_permissions_check( $request ) {
		return Permissions::has_sales_manager_access();
	}
}
