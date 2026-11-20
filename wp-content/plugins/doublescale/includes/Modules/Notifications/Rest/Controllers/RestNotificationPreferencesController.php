<?php
/**
 * REST Api: Notification Preferences Controller
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Rest\Controllers;

use WP_REST_Controller;
use DoubleScale\Core\UserRoles\Permissions;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Modules\Notifications\Services\NotificationPreferences;

defined( 'ABSPATH' ) || exit;

/**
 * RestNotificationPreferencesController class
 *
 * Handles REST Api endpoints for user notification preferences.
 *
 * @since 1.2.0
 */
class RestNotificationPreferencesController extends WP_REST_Controller {

	/**
	 * REST base
	 *
	 * @var string
	 */
	protected $rest_base = 'notification-preferences';

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1';

	/**
	 * Register routes
	 *
	 * @since 1.2.0
	 */
	public function register_routes() {
		// GET/POST /doublescale/v1/notification-preferences
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_preferences' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_update_args(),
				),
			)
		);

		// GET /doublescale/v1/notification-preferences/defaults
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/defaults',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_defaults' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// GET /doublescale/v1/notification-preferences/categories
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_categories' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// GET /doublescale/v1/notification-preferences/subcategories/{category}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/subcategories/(?P<category>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subcategories' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'category' => array(
						'description'       => __( 'Notification category key.', 'doublescale' ),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_category' ),
					),
				),
			)
		);
	}

	/**
	 * Check permissions
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access notification preferences.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'doublescale_access' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access notification preferences.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get default notification preferences
	 *
	 * Returns the default preferences structure before any user customization.
	 * This ensures frontend and backend defaults stay in sync.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_defaults( $request ) {
		return new WP_REST_Response( NotificationPreferences::get_defaults(), 200 );
	}

	/**
	 * Get current user's notification preferences
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_preferences( $request ) {
		$user_id     = get_current_user_id();
		$preferences = NotificationPreferences::get( $user_id );

		return new WP_REST_Response( $preferences, 200 );
	}

	/**
	 * Update current user's notification preferences
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_preferences( $request ) {
		$user_id = get_current_user_id();
		$current = NotificationPreferences::get( $user_id );

		// What the request leaves out stays as it is: an app or an integration
		// may change one switch without sending the whole page back.
		$channels      = (array) ( $request->get_param( 'channels' ) ?? array() );
		$subcategories = (array) ( $request->get_param( 'subcategories' ) ?? array() );
		$preferences   = array(
			'channels'      => array_merge( (array) ( $current['channels'] ?? array() ), $channels ),
			'subcategories' => (array) ( $current['subcategories'] ?? array() ),
		);
		foreach ( $subcategories as $key => $value ) {
			$preferences['subcategories'][ $key ] = array_merge(
				(array) ( $preferences['subcategories'][ $key ] ?? array() ),
				(array) $value
			);
		}

		// Only the categories this user is shown can change: every other
		// subcategory keeps what is stored, whatever the request carries.
		$visible = array();
		foreach ( array_keys( self::visible_categories() ) as $category ) {
			$visible += NotificationCategories::get_subcategories( $category );
		}
		foreach ( (array) ( $current['subcategories'] ?? array() ) as $key => $value ) {
			if ( ! isset( $visible[ $key ] ) ) {
				$preferences['subcategories'][ $key ] = $value;
			}
		}

		$result = NotificationPreferences::update( $user_id, $preferences );

		if ( false === $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update notification preferences.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		// Return updated preferences.
		return new WP_REST_Response( NotificationPreferences::get( $user_id ), 200 );
	}

	/**
	 * Get available notification categories
	 *
	 * Includes metadata about subcategories for each category.
	 * Categories are filtered based on user capabilities:
	 * - System category requires manage_options (administrators)
	 * - Campaigns, Automations, Forms, Integrations require doublescale_crm_manager
	 * - Other categories accessible by all CRM users
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_categories( $request ) {
		return new WP_REST_Response( self::visible_categories(), 200 );
	}

	/**
	 * The categories the current user is shown, and may change.
	 *
	 * Uses the centralized capability map from NotificationCategories.
	 * Automation-only staff (Automation Manager / Agent with no other
	 * DoubleScale role) see the Automations category alone: it is the only
	 * one that reaches them.
	 *
	 * @return array Category key => metadata.
	 */
	private static function visible_categories() {
		$categories = NotificationCategories::get_all_with_metadata();

		foreach ( array_keys( $categories ) as $category_key ) {
			$required_cap = NotificationCategories::get_required_capability( $category_key );

			// If category requires a capability the user doesn't have, remove it.
			if ( $required_cap && ! current_user_can( $required_cap ) ) {
				unset( $categories[ $category_key ] );
			}
		}

		if ( Permissions::is_automation_only() ) {
			$categories = array_intersect_key( $categories, array( NotificationCategories::AUTOMATIONS => true ) );
		}
		// Helpdesk staff (and no other role): their helpdesk alerts only.
		if ( Permissions::is_support_only() ) {
			$categories = array_intersect_key( $categories, array( NotificationCategories::SUPPORT => true ) );
		}
		// Booking staff (and no other role): their booking alerts only.
		if ( Permissions::is_booking_only() ) {
			$categories = array_intersect_key( $categories, array( NotificationCategories::BOOKING => true ) );
		}
		// Project staff (and no other role): their project and task alerts.
		if ( Permissions::is_project_only() ) {
			$categories = array_intersect_key( $categories, array( NotificationCategories::PROJECTS => true, NotificationCategories::TASKS => true ) );
		}

		return $categories;
	}

	/**
	 * Get subcategories for a specific category
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subcategories( $request ) {
		$category      = $request->get_param( 'category' );
		$subcategories = NotificationCategories::get_subcategories( $category );

		return new WP_REST_Response( $subcategories, 200 );
	}

	/**
	 * Validate category parameter
	 *
	 * @since 1.2.0
	 *
	 * @param string $category Category key to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_category( $category ) {
		if ( ! NotificationCategories::is_valid( $category ) ) {
			return new WP_Error(
				'invalid_category',
				__( 'Invalid notification category.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Get update endpoint arguments
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	private function get_update_args() {
		return array(
			'channels'      => array(
				'description' => __( 'Global channel settings.', 'doublescale' ),
				'type'        => 'object',
				'required'    => false,
				'properties'  => array(
					'bell'    => array(
						'type' => 'boolean',
					),
					'email'   => array(
						'type' => 'boolean',
					),
					'browser' => array(
						'type' => 'boolean',
					),
				),
			),
			'subcategories' => array(
				'description' => __( 'Per-subcategory channel settings (flat structure).', 'doublescale' ),
				'type'        => 'object',
				'required'    => false,
			),
		);
	}
}
