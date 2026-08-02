<?php
/**
 * REST_Settings_Controller class.
 *
 * @since 1.0.0
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Smtp\Settings;
use DoubleScale\Modules\Smtp\SmtpConnectionValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST_Settings_Controller class.
 *
 * @since 1.0.0
 */
class RestSmtpSettingsController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'smtp/settings';

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
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
					'args'                => \rest_get_endpoint_args_for_schema( $this->get_schema(), WP_REST_Server::CREATABLE ),
				),
			)
		);
	}

	/**
	 * Retrieves schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_schema() {
		$schema = array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'settings',
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'default_connection'    => array(
					'type'    => 'string',
					'default' => '',
				),
				'fallback_connection'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'connections'           => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'default'              => array(),
				),
				'disable_summary_email' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'alerts_settings'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'default'              => array(),
				),
			),
			'default'              => array(
				'default_connection'    => '',
				'fallback_connection'   => '',
				'connections'           => array(),
				'disable_summary_email' => false,
				'alerts_settings'       => array(),
			),
		);

		return $schema;
	}

	/**
	 * Retrieves settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get( $request ) { // phpcs:ignore
		$schema   = $this->get_schema();
		$defaults = $schema['default'];
		$stored   = Settings::get_all();
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Merge so new schema keys appear before apply_filters.
		$all_settings = array_replace( $defaults, $stored );

		$all_settings = apply_filters(
			'doublescale_smtp_rest_settings',
			$all_settings,
		);

		// Align legacy mailer options with bundled oauth_app (noop when unchanged). Lets OAuth work
		// after upgrading without requiring an extra Save if credentials already exist in connections.
		if ( ! empty( $all_settings['connections'] ) && is_array( $all_settings['connections'] ) ) {
			Settings::sync_oauth_apps_from_bundle( $all_settings );
		}

		return new WP_REST_Response( $all_settings, 200 );
	}

	/**
	 * Checks if a given request has access to get settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_permissions_check( $request ) {
		return Settings::user_can_manage_smtp_rest();
	}

	/**
	 * Updates settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update( $request ) {
		$settings = $request->get_json_params();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( isset( $settings['connections'] ) ) {
			$merged = array_replace( Settings::get_all(), $settings );
			$valid  = SmtpConnectionValidator::validate_settings_connections( $merged );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		Settings::update_many( $settings );

		if ( isset( $settings['connections'] ) || isset( $settings['default_connection'] ) ) {
			Settings::sync_oauth_apps_from_bundle( Settings::get_all() );

			/**
			 * Fires when SMTP connections (or the default connection) change.
			 *
			 * Gated to connection-touching saves only — not every SMTP settings
			 * write — so listeners (e.g. Support's default-mailbox seeder, which
			 * needs a connection before it can bind a sending identity) don't run
			 * on unrelated saves such as alerts or logging config.
			 *
			 * @param array $connections The post-save connection bundle.
			 */
			do_action( 'doublescale_smtp_connections_updated', Settings::get( 'connections', array() ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Checks if a given request has access to update settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function update_permissions_check( $request ) {
		return Settings::user_can_manage_smtp_rest();
	}
}
