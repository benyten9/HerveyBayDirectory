<?php
/**
 * Admin Client Portal page management.
 *
 *   GET  /doublescale/v1/portal/page   → page status (id, view/edit URLs, shortcode)
 *   POST /doublescale/v1/portal/page   → adopt-or-create the portal page
 *
 * Powers the "Client Portal" settings card: shows where the portal page lives
 * and lets an admin (re)create it if it was never provisioned or was trashed.
 * Admin-only — distinct from the customer-facing `/portal/*` endpoints, which
 * gate on {@see \DoubleScale\Modules\Portal\Services\PortalIdentity}.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalPageLayout;
use DoubleScale\Modules\Portal\Services\PortalPageProvisioner;
use DoubleScale\Modules\Portal\Services\PortalLoginSettings;
use DoubleScale\Modules\Portal\Services\PortalWelcomeSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalPageController.
 */
class RestPortalPageController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/page',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Book a meeting: whose meetings clients can book from the portal.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/booking-options',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_booking_options' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_booking_options' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'mode'      => array(
							'type' => 'string',
							'enum' => \DoubleScale\Modules\Portal\Services\PortalBookingOptions::MODES,
						),
						'event_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		// The portal's brand colour.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/layout',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_layout' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_layout' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'brand_color' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// The welcome message under "Welcome back, {name}" (empty = the built-in line).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/welcome',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_welcome' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_welcome' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'welcome_message' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// The login page's branding: the business logo (and its width) and the heading.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/login',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_login' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_login' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'show_logo'  => array( 'type' => 'boolean' ),
						'logo_width' => array( 'type' => 'integer' ),
						'heading'    => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'subtitle'    => array( 'type' => 'string' ),
						'panel_title' => array( 'type' => 'string' ),
						'panel_text'  => array( 'type' => 'string' ),
						'points'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Managing the portal page is a site-settings action.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return the current portal page status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_page( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return new WP_REST_Response( PortalPageProvisioner::get_status(), 200 );
	}

	/**
	 * Adopt-or-create the portal page (the settings "Create page" action).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		$page_id = PortalPageProvisioner::provision();

		if ( $page_id <= 0 ) {
			return new WP_Error(
				'portal_page_create_failed',
				__( 'The portal page could not be created.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( PortalPageProvisioner::get_status(), 200 );
	}

	/**
	 * Return the portal welcome message settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_welcome( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return new WP_REST_Response( PortalWelcomeSettings::settings(), 200 );
	}

	/**
	 * Save the portal welcome message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_welcome( $request ) {
		return new WP_REST_Response( PortalWelcomeSettings::update( array( 'welcome_message' => (string) $request->get_param( 'welcome_message' ) ) ), 200 );
	}

	/**
	 * Return the login page branding settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_login( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return new WP_REST_Response( PortalLoginSettings::settings(), 200 );
	}

	/**
	 * Save the login page branding.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_login( $request ) {
		$input = array(
			'show_logo'  => (bool) $request->get_param( 'show_logo' ),
			'logo_width' => (int) $request->get_param( 'logo_width' ),
			'heading'    => (string) $request->get_param( 'heading' ),
		);
		// Texts and points only when sent, so an older screen keeps them.
		foreach ( array_merge( PortalLoginSettings::TEXT_FIELDS, array( 'points' ) ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = 'points' === $field ? (array) $request->get_param( $field ) : (string) $request->get_param( $field );
			}
		}
		return new WP_REST_Response( PortalLoginSettings::update( $input ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_layout( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return new WP_REST_Response( PortalPageLayout::settings(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_layout( $request ) {
		return new WP_REST_Response( PortalPageLayout::update( array( 'brand_color' => (string) $request->get_param( 'brand_color' ) ) ), 200 );
	}

	/**
	 * The setting, and every event the team could offer.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * Whether the Booking module is on: the portal's "Book a meeting" options
	 * only exist then.
	 */
	private static function booking_on(): bool {
		return ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( 'booking' );
	}

	/**
	 * Booking is off: answer as if the route did not exist, which the settings
	 * card already reads as "leave me out".
	 *
	 * @return WP_Error
	 */
	private static function booking_off_error(): WP_Error {
		return new WP_Error( 'rest_no_route', __( 'Booking is turned off.', 'doublescale' ), array( 'status' => 404 ) );
	}

	public function get_booking_options() {
		if ( ! self::booking_on() ) {
			return self::booking_off_error();
		}
		return new WP_REST_Response(
			\DoubleScale\Modules\Portal\Services\PortalBookingOptions::settings()
				+ array( 'events' => \DoubleScale\Modules\Portal\Services\PortalBookingOptions::all_events() ),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_booking_options( $request ) {
		if ( ! self::booking_on() ) {
			return self::booking_off_error();
		}
		$input = array();
		foreach ( array( 'mode', 'event_ids' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		\DoubleScale\Modules\Portal\Services\PortalBookingOptions::update( $input );

		return $this->get_booking_options();
	}
}
