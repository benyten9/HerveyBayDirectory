<?php

/**
 * REST Api: Site Verification & Auth Controller (Mobile App)
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Application_Passwords;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\ModuleManager;
use DoubleScale\Core\UserRoles\UserRoles;

class RestSiteVerificationController extends RestController {
	protected $rest_base = 'site';

	const TEMP_TOKEN_TTL          = 900; // 15 minutes
	const LOGIN_ATTEMPT_WINDOW    = 300; // 5 minutes
	const LOGIN_ATTEMPT_THRESHOLD = 5;

	public function register_routes() {
		// Verify site.
		//
		// Public by design (`permission_callback` => `__return_true`). The
		// response only contains the plugin name, version, SSL state, and
		// whether WP Application Passwords are available — all information
		// already discoverable from any WP install (`/wp-json/`, the login
		// page, etc.). No user data, no auth state, no enumeration vector;
		// the response shape is identical for every caller.
		//
		// The DoubleScale mobile app calls this BEFORE login to confirm the
		// host is a real DoubleScale install and that Application Passwords
		// are available.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/verify',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'verify_site' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Login (username/password).
		//
		// Public by design (`permission_callback` => `__return_true`): this IS
		// the auth handshake — the caller cannot be authenticated yet because
		// they are obtaining credentials. Safeguards in login():
		//   * SSL is enforced (HTTP returns 403/`ssl_required`).
		//   * Credentials are validated via wp_authenticate(), so the WP
		//     core hooks for rate-limiting / two-factor / login-lockout
		//     plugins still apply.
		//   * A per-IP transient throttle returns 429 after
		//     LOGIN_ATTEMPT_THRESHOLD failures inside LOGIN_ATTEMPT_WINDOW —
		//     this covers the REST surface for lockout plugins that only
		//     hook wp-login.php.
		//   * On success only a short-lived (15 min) single-use temp token
		//     is issued; the user must follow up with create-app-password
		//     to obtain a long-lived credential. The token is HMAC-hashed
		//     before being stored in user meta, so DB read access alone
		//     cannot replay it.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/login',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'login' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'username' => array(
							'required' => true,
							'type'     => 'string',
						),
						'password' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		// Create Application Password (with temp token).
		//
		// NOT public — `check_temp_token` is a real permission callback that
		// validates the one-time token issued by /login above, ties the
		// request to that user via `wp_set_current_user`, and enforces the
		// 15-minute expiry. The endpoint is reachable only as the second
		// step of the mobile-app login flow. SSL is also enforced inside
		// `create_application_password` so a long-lived credential never
		// leaves the server over plaintext.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/create-app-password',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_application_password' ),
					'permission_callback' => array( $this, 'check_temp_token' ),
					'args'                => array(
						'name' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Module status (mobile app).
		//
		// Public by design: enabled/disabled flags are site configuration the
		// mobile app needs before login to build navigation. Read-only; no
		// user data. Admin toggling remains on POST /modules.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/modules/(?P<slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_module_status' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/modules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_modules_status' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Authenticated mobile user profile (roles, caps, mobile flags).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_current_user_profile' ),
					'permission_callback' => array( $this, 'check_authenticated_user' ),
				),
			)
		);
	}

	/**
	 * Verify site
	 */
	public function verify_site() {
		return new WP_REST_Response(
			array(
				'success' => true,
				'plugin'  => 'Double Scale',
				'version' => DOUBLESCALE_VERSION,
				'is_ssl'  => is_ssl(),
				'auth'    => array(
					'application_passwords' => $this->is_application_password_available(),
				),
			),
			200
		);
	}

	/**
	 * List all modules with enabled state (mobile app).
	 *
	 * @return WP_REST_Response
	 */
	public function get_modules_status() {
		return new WP_REST_Response(
			array(
				'success' => true,
				'modules' => $this->build_mobile_modules_payload(),
			),
			200
		);
	}

	/**
	 * Check whether a single module is enabled (mobile app).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_module_status( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' );

		if ( ! $this->is_known_module_slug( $slug ) ) {
			return new WP_Error(
				'module_not_found',
				sprintf(
					/* translators: %s: module slug */
					__( 'Module "%s" was not found.', 'doublescale' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'slug'    => $slug,
				'enabled' => ModuleManager::isEnabled( $slug ),
			),
			200
		);
	}

	/**
	 * Permission check for authenticated mobile endpoints.
	 *
	 * @return true|WP_Error
	 */
	public function check_authenticated_user() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You are not currently logged in.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Current user profile for the mobile app.
	 *
	 * @return WP_REST_Response
	 */
	public function get_current_user_profile() {
		$user = wp_get_current_user();
		$roles = array_values( array_map( 'strval', (array) $user->roles ) );
		$primary_role = $this->resolve_primary_role( $roles );
		$mobile       = $this->build_mobile_flags( $roles, $user->ID );

		return new WP_REST_Response(
			array(
				'success'      => true,
				'id'           => (int) $user->ID,
				'username'     => (string) $user->user_login,
				'display_name' => (string) $user->display_name,
				'roles'        => $roles,
				'primary_role' => $primary_role,
				'mobile'       => $mobile,
				'capabilities' => $this->build_capabilities_payload( $user ),
				'avatar_url'   => (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			),
			200
		);
	}

	/**
	 * @param array<int, string> $roles
	 */
	private function resolve_primary_role( array $roles ): ?string {
		$priority = array(
			'administrator',
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
			UserRoles::SUPPORT_MANAGER,
			UserRoles::SUPPORT_AGENT,
			UserRoles::BOOKING_MANAGER,
			UserRoles::BOOKING_AGENT,
			UserRoles::PROJECT_MANAGER,
			UserRoles::PROJECT_MEMBER,
		);

		$normalized = array_map(
			static function ( $role ) {
				return strtolower( (string) $role );
			},
			$roles
		);

		foreach ( $priority as $role ) {
			if ( in_array( strtolower( $role ), $normalized, true ) ) {
				return $role;
			}
		}

		return $roles[0] ?? null;
	}

	/**
	 * @param array<int, string> $roles
	 * @return array{show_license_status: bool, show_push_notifications: bool, calendar_own_only: bool}
	 */
	private function build_mobile_flags( array $roles, int $user_id ): array {
		$normalized = array_map(
			static function ( $role ) {
				return strtolower( (string) $role );
			},
			$roles
		);

		$is_admin = in_array( 'administrator', $normalized, true )
			|| user_can( $user_id, 'manage_options' );
		$is_crm_manager = in_array( strtolower( UserRoles::CRM_MANAGER ), $normalized, true );
		$is_sales_manager = in_array( strtolower( UserRoles::SALES_MANAGER ), $normalized, true );
		$is_sales_rep = in_array( strtolower( UserRoles::SALES_REP ), $normalized, true );
		$is_booking_agent = in_array( strtolower( UserRoles::BOOKING_AGENT ), $normalized, true );

		$show_license_status = $is_admin || $is_crm_manager || $is_sales_manager || $is_sales_rep;
		$show_push_notifications = $is_admin || $is_sales_manager || $is_sales_rep;
		$calendar_own_only = $is_booking_agent && ! $is_admin && ! $is_crm_manager;

		return array(
			'show_license_status'     => $show_license_status,
			'show_push_notifications'   => $show_push_notifications,
			'calendar_own_only'         => $calendar_own_only,
		);
	}

	/**
	 * @param \WP_User $user
	 * @return array<string, bool>
	 */
	private function build_capabilities_payload( $user ): array {
		$capabilities = array();

		foreach ( (array) $user->allcaps as $capability => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			$capability = (string) $capability;
			if (
				0 === strpos( $capability, 'doublescale_' )
				|| 'manage_options' === $capability
			) {
				$capabilities[ $capability ] = true;
			}
		}

		return $capabilities;
	}

	/**
	 * Login endpoint (temporary token)
	 */
	public function login( WP_REST_Request $request ) {
		if ( ! is_ssl() ) {
			return new WP_Error(
				'ssl_required',
				__( 'SSL is required for login.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		$attempt_key = $this->login_attempt_key( $request );
		$attempts    = (int) get_transient( $attempt_key );
		if ( $attempts >= self::LOGIN_ATTEMPT_THRESHOLD ) {
			return new WP_Error(
				'too_many_attempts',
				__( 'Too many failed login attempts. Please try again later.', 'doublescale'),
				array( 'status' => 429 )
			);
		}

		$user = wp_authenticate(
			$request->get_param( 'username' ),
			$request->get_param( 'password' )
		);

		if ( is_wp_error( $user ) ) {
			set_transient( $attempt_key, $attempts + 1, self::LOGIN_ATTEMPT_WINDOW );
			return new WP_Error(
				'login_failed',
				__( 'Invalid username or password.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		delete_transient( $attempt_key );

		$token   = wp_generate_password( 64, false );
		$expires = time() + self::TEMP_TOKEN_TTL;

		// Store the HMAC of the token, never the token itself. The plaintext
		// is returned to the client only in this response; a DB-read attacker
		// cannot replay the token because they only see the hash.
		update_user_meta( $user->ID, 'doublescale_temp_token', $this->hash_token( $token ) );
		update_user_meta( $user->ID, 'doublescale_temp_token_expiry', $expires );

		return new WP_REST_Response(
			array(
				'success' => true,
				'user_id' => $user->ID,
				'token'   => $token,
				'expires' => $expires,
				'message' => __( 'Login successful. Temporary token issued.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * Create Application Password
	 */
	public function create_application_password( WP_REST_Request $request ) {

		if ( ! is_ssl() ) {
			return new WP_Error(
				'ssl_required',
				__( 'SSL is required to create an application password.', 'doublescale'),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->is_application_password_available() ) {
			return new WP_Error(
				'application_password_not_available',
				__( 'Application password is not available. Please contact support.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		$user = wp_get_current_user();
		$name = $request->get_param( 'name' ) ?: 'Double Scale Mobile App';

		list($password, $item) =
			WP_Application_Passwords::create_new_application_password(
				$user->ID,
				array( 'name' => $name )
			);

		// One-time token cleanup
		delete_user_meta( $user->ID, 'doublescale_temp_token' );
		delete_user_meta( $user->ID, 'doublescale_temp_token_expiry' );

		return new WP_REST_Response(
			array(
				'success'              => true,
				'username'             => $user->user_login,
				'application_password' => $password,
				'uuid'                 => $item['uuid'],
			),
			201
		);
	}

	/**
	 * Validate temporary token
	 */
	public function check_temp_token( WP_REST_Request $request ) {
		$token = $request->get_header( 'x-doublescale-token' );
		if ( ! $token ) {
			$token = $request->get_header( 'x-ds-token' );
		}

		if ( ! $token ) {
			return new WP_Error(
				'missing_token',
				__( 'Temporary token is required.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		$incoming_hash = $this->hash_token( $token );

		$users = get_users(
			array(
				'meta_key'   => 'doublescale_temp_token',
				'meta_value' => $incoming_hash,
				'number'     => 1,
			)
		);

		if ( empty( $users ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid token.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		$user          = $users[0];
		$stored_hash   = (string) get_user_meta( $user->ID, 'doublescale_temp_token', true );

		// Defence in depth: even though `get_users` did an exact-match lookup,
		// re-compare with a constant-time check so future refactors of the
		// lookup path cannot introduce a timing leak.
		if ( ! hash_equals( $stored_hash, $incoming_hash ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid token.', 'doublescale'),
				array( 'status' => 401 )
			);
		}

		$expiry = (int) get_user_meta( $user->ID, 'doublescale_temp_token_expiry', true );

		if ( time() > $expiry ) {
			return new WP_Error(
				'token_expired',
				__( 'Token expired.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		// REST-only context bind so `wp_get_current_user()` downstream resolves
		// to the token holder. No cookie is set, no persistent session created.
		wp_set_current_user( $user->ID );
		return true;
	}

	private function is_application_password_available() {
		return function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available();
	}

	/**
	 * @return array<int, array{slug: string, label: string, enabled: bool}>
	 */
	private function build_mobile_modules_payload(): array {
		$rows = doublescale_build_modules_list_payload( ModuleManager::all() );

		return array_map(
			static function ( array $row ): array {
				return array(
					'slug'    => (string) $row['slug'],
					'label'   => (string) $row['label'],
					'enabled' => (bool) $row['enabled'],
				);
			},
			$rows
		);
	}

	private function is_known_module_slug( string $slug ): bool {
		if ( ModuleManager::getModule( $slug ) ) {
			return true;
		}

		if ( ! function_exists( 'doublescale_is_phantom_module_toggle_slug' )
			|| ! doublescale_is_phantom_module_toggle_slug( $slug ) ) {
			return false;
		}

		return function_exists( 'doublescale_phantom_module_admin_meta' )
			&& null !== doublescale_phantom_module_admin_meta( $slug );
	}

	/**
	 * Hash a temp token for at-rest storage / comparison.
	 *
	 * Uses `wp_salt('auth')` so the hash is tied to the site secret — copying
	 * the user_meta row to another install does not produce a usable token.
	 */
	private function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Per-IP transient key for the /site/login attempt counter.
	 */
	private function login_attempt_key( WP_REST_Request $request ): string {
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return 'doublescale_login_attempts_' . md5( $ip );
	}
}
