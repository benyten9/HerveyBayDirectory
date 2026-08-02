<?php
/**
 * REST API for per-user list table preferences.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\ListPreferences\ListPreferencesManager;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * List preferences REST controller.
 *
 * @since 1.0.0
 */
class RestListPreferencesController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'list-preferences';

	/**
	 * Register routes.
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
					'callback'            => array( $this, 'get_all_preferences' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<list_key>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_preferences' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get all list preferences for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_all_preferences( WP_REST_Request $request ) {
		return new WP_REST_Response( ListPreferencesManager::get_all(), 200 );
	}

	/**
	 * Get preferences for a single list.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preferences( WP_REST_Request $request ) {
		$list_key = sanitize_key( (string) $request->get_param( 'list_key' ) );
		if ( ! ListPreferencesManager::is_allowed_list_key( $list_key ) ) {
			return new WP_Error( 'invalid_list_key', __( 'Invalid list key.', 'doublescale' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( ListPreferencesManager::get( $list_key ), 200 );
	}

	/**
	 * Update preferences for a single list.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_preferences( WP_REST_Request $request ) {
		$user_id = (int) get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'unauthorized', __( 'User not logged in.', 'doublescale' ), array( 'status' => 401 ) );
		}

		$list_key = sanitize_key( (string) $request->get_param( 'list_key' ) );
		if ( ! ListPreferencesManager::is_allowed_list_key( $list_key ) ) {
			return new WP_Error( 'invalid_list_key', __( 'Invalid list key.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		unset( $params['list_key'] );

		$updated = ListPreferencesManager::update( $list_key, $params, $user_id );
		if ( false === $updated ) {
			return new WP_Error( 'update_failed', __( 'Failed to save list preferences.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $updated, 200 );
	}

	/**
	 * Permission check.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function permissions_check( WP_REST_Request $request ) {
		return Permissions::has_sales_rep_access();
	}
}
