<?php

/**
 * Class Website Tracking
 * Handles website page visit tracking
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\WebsiteTracking;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel;

/**
 * Website Tracking class
 */
class Website {

	/**
	 * Tracking cookie name
	 */
	const TRACKING_COOKIE = 'doublescale_tracking';

	/**
	 * Page visits cookie name (for anonymous users)
	 */
	const PAGE_VISITS_COOKIE = 'doublescale_page_visits';

	/**
	 * Cookie expiry in days
	 */
	const COOKIE_EXPIRY_DAYS = 30;

	/**
	 * Form entries sent from this browser before it was known (native forms), signed.
	 * Handed to the contact when the visitor is identified (form, login, signup).
	 */
	const PENDING_ENTRIES_COOKIE = 'doublescale_pending_entries';

	/**
	 * Most entries remembered per browser.
	 */
	const PENDING_ENTRIES_MAX = 20;

	/**
	 * Class Instance.
	 *
	 * @since 1.2.0
	 * @var Website
	 */
	private static $instance;

	/**
	 * Current contact ID from cookie
	 *
	 * @var int|null
	 */
	private $current_contact_id = null;

	/**
	 * Pending entries changed during this request (null: read the cookie).
	 *
	 * @var int[]|null
	 */
	private $pending = null;

	/**
	 * Website Instance.
	 *
	 * @since 1.2.0
	 * @return Website
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		 // Deconstruct tracking cookie on every request
		$this->deconstruct_tracking_cookie();

		// Set tracking cookie on login
		add_action( 'wp_login', array( $this, 'on_user_login' ), 10, 2 );
		// Track user logout
		add_action( 'wp_logout', array( $this, 'on_user_logout' ) );

		add_action( 'doublescale_contact_subscribe', array( $this, 'track_anonymous_visits_after_signup' ), 10, 1 );
		add_action( 'doublescale_contact_unsubscribe', array( $this, 'track_anonymous_visits_after_signup' ), 10, 1 );

		// Enqueue tracking scripts (frontend + wp-login.php; login does not fire wp_enqueue_scripts).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking_scripts' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_tracking_scripts' ) );

		// REST Api for tracking
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_action( 'doublescale_daily_doublescale_cleanup_page_visits', array( $this, 'cleanup_old_page_visits' ) );

		// Native forms: a form sent without an email or phone goes to the visitor this browser
		// is known as (signed cookie only), or waits until the visitor is identified.
		add_filter( 'doublescale_native_form_known_visitor', array( $this, 'known_visitor_for_forms' ), 10, 1 );
		add_action( 'doublescale_native_form_unlinked', array( $this, 'remember_pending_entry' ), 10, 1 );
	}

	/**
	 * The contact this browser is known as, for a form sent without an email or phone.
	 * Only a signed tracking cookie counts (one this site issued when the browser created
	 * the contact or logged in); a logged-in visitor is handled by the form itself.
	 *
	 * @param int $contact_id Contact id found so far (0 for none).
	 * @return int
	 */
	public function known_visitor_for_forms( $contact_id ) {
		if ( (int) $contact_id > 0 || ! $this->current_contact_id ) {
			return (int) $contact_id;
		}
		return ContactModel::find( $this->current_contact_id ) ? (int) $this->current_contact_id : 0;
	}

	/**
	 * Remember a form entry sent before this browser was known, so it reaches the contact
	 * once the visitor is identified.
	 *
	 * @param int $submission_id Submission id.
	 * @return void
	 */
	public function remember_pending_entry( $submission_id ) {
		$submission_id = (int) $submission_id;
		if ( $submission_id <= 0 || headers_sent() ) {
			return;
		}
		$ids   = $this->pending_entries();
		$ids[] = $submission_id;
		$ids   = array_slice( array_values( array_unique( $ids ) ), -self::PENDING_ENTRIES_MAX );
		$this->write_pending_entries( $ids );
	}

	/**
	 * Form entries this browser sent before it was known (signed cookie; anything else is ignored).
	 *
	 * @return int[]
	 */
	private function pending_entries(): array {
		if ( null !== $this->pending ) {
			return $this->pending;
		}
		$data = json_decode( self::cookie( self::PENDING_ENTRIES_COOKIE ), true );
		if ( ! is_array( $data ) || ! isset( $data['ids'], $data['sig'] ) || ! is_array( $data['ids'] ) || ! is_string( $data['sig'] ) ) {
			return array();
		}
		$ids = array_values( array_filter( array_map( 'absint', $data['ids'] ) ) );
		if ( ! hash_equals( self::sign( 'entries|' . implode( ',', $ids ) ), $data['sig'] ) ) {
			return array();
		}
		return $ids;
	}

	/**
	 * Store (or clear) the pending entries cookie.
	 *
	 * @param int[] $ids Submission ids.
	 * @return void
	 */
	private function write_pending_entries( array $ids ): void {
		$this->pending = $ids;
		if ( headers_sent() ) {
			return;
		}
		if ( ! $ids ) {
			setcookie( self::PENDING_ENTRIES_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
			return;
		}
		$value = wp_json_encode(
			array(
				'ids' => $ids,
				'sig' => self::sign( 'entries|' . implode( ',', $ids ) ),
			)
		);
		setcookie( self::PENDING_ENTRIES_COOKIE, $value, time() + self::COOKIE_EXPIRY_DAYS * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * A cookie's raw value ('' when missing). Callers verify its signature before trusting it.
	 *
	 * @param string $name Cookie name.
	 * @return string
	 */
	private static function cookie( string $name ): string {
		return isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? wp_unslash( $_COOKIE[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON checked by its signature by every caller.
	}

	/**
	 * Hand the browser's waiting form entries to the contact it now belongs to.
	 *
	 * @param int $contact_id Contact id.
	 * @return void
	 */
	private function claim_pending_entries( int $contact_id ): void {
		$ids = $this->pending_entries();
		if ( ! $ids ) {
			return;
		}
		/**
		 * A visitor was identified: these form entries, sent from the same browser before, may
		 * now belong to the contact (native forms decide; ids come from a signed cookie).
		 *
		 * @param int   $contact_id Contact id.
		 * @param int[] $ids        Submission ids.
		 */
		do_action( 'doublescale_tracking_claim_form_entries', $contact_id, $ids );
		$this->write_pending_entries( array() );
	}

	/**
	 * Signature of a cookie value (only this site can make one).
	 *
	 * @param string $payload Value.
	 * @return string
	 */
	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . '|doublescale-tracking' );
	}

	/**
	 * Register REST Api routes for tracking
	 *
	 * @since 1.2.0
	 */
	public function register_rest_routes() {
		register_rest_route(
			'doublescale/v1',
			'/tracking/page-view',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_page_view' ),
				'permission_callback' => '__return_true', // Public endpoint
			)
		);
	}

	/**
	 * REST Api callback for page view tracking
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function rest_page_view( $request ) {
		$contact = $this->get_current_contact();

		$url = $request->get_param( 'url' );
		if ( empty( $url ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'URL is required.',
				),
				400
			);
		}

		if ( ! $contact ) {
			// Store in cookie for later association
			$this->remember_page_visit_in_cookie( $url, false );
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Page visit stored in cookie for later association.',
				),
				200
			);
		}

		$visit = $this->track_page_visit( $url, $contact );

		if ( ! $visit ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Failed to track page visit.',
				),
				500
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'visit'   => $visit->toArray(),
			),
			200
		);
	}

	/**
	 * Track a page visit
	 *
	 * @since 1.2.0
	 *
	 * @param string           $url URL visited.
	 * @param ContactModel|int $contact Contact model or ID.
	 * @param array            $extra_data Additional data.
	 * @return PageVisitModel|false
	 */
	public function track_page_visit( $url, $contact, $extra_data = array() ) {
		if ( is_int( $contact ) ) {
			$contact = ContactModel::find( $contact );
		}

		if ( ! $contact ) {
			return false;
		}

		$parsed_url = wp_parse_url( $url );

		$data = array(
			'contact_id' => $contact->id,
			'path'       => sanitize_text_field( $parsed_url['path'] ?? '/' ),
			'query'      => sanitize_text_field( $parsed_url['query'] ?? '' ),
			'ip_address' => $this->get_client_ip(),
			'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		);

		// Create a new record for each page visit (no duplicate checking)
		$visit = PageVisitModel::create( $data );

		if ( $visit ) {
			do_action( 'doublescale_page_visited', $contact );
		}

		return $visit;
	}

	/**
	 * Get current contact from tracking cookie or logged-in user
	 *
	 * @since 1.2.0
	 * @return ContactModel|null
	 */
	public function get_current_contact() {
		 // First check if user is logged in
		if ( is_user_logged_in() ) {
			$user    = wp_get_current_user();
			$contact = ContactModel::where( 'email', $user->user_email )->first();
			if ( $contact ) {
				return $contact;
			}
			return null;
		}

		// Then check tracking cookie
		if ( $this->current_contact_id ) {
			return ContactModel::find( $this->current_contact_id );
		}

		return null;
	}

	/**
	 * Get current contact ID
	 *
	 * @since 1.2.0
	 * @return int|null
	 */
	public function get_current_contact_id() {
		$contact = $this->get_current_contact();
		return $contact ? $contact->id : null;
	}

	/**
	 * Start tracking a contact
	 *
	 * @since 1.2.0
	 *
	 * @param ContactModel|int $contact Contact model or ID.
	 */
	public function start_tracking( $contact ) {
		if ( is_int( $contact ) ) {
			$contact_id = $contact;
		} else {
			$contact_id = $contact->id;
		}

		$this->current_contact_id = (int) $contact_id;
		$this->build_tracking_cookie();
		$this->claim_pending_entries( (int) $contact_id );
	}

	/**
	 * Deconstruct tracking cookie
	 *
	 * @since 1.2.0
	 */
	private function deconstruct_tracking_cookie() {
		if ( ! isset( $_COOKIE[ self::TRACKING_COOKIE ] ) ) {
			return;
		}

		// Only a cookie this site signed counts: an edited (or old, unsigned) one is ignored, so
		// nobody can make the site treat them as another contact.
		$cookie_data = json_decode( self::cookie( self::TRACKING_COOKIE ), true );
		if ( ! is_array( $cookie_data ) || ! isset( $cookie_data['contact_id'], $cookie_data['created'], $cookie_data['sig'] ) || ! is_string( $cookie_data['sig'] ) ) {
			return;
		}
		$contact_id = absint( $cookie_data['contact_id'] );
		$created    = absint( $cookie_data['created'] );
		if ( ! $contact_id || $created > time() + 300 || $created < time() - self::COOKIE_EXPIRY_DAYS * DAY_IN_SECONDS ) {
			return;
		}
		if ( hash_equals( self::sign( $contact_id . '|' . $created ), $cookie_data['sig'] ) ) {
			$this->current_contact_id = $contact_id;
		}
	}

	/**
	 * Build tracking cookie
	 *
	 * @since 1.2.0
	 */
	private function build_tracking_cookie() {
		if ( headers_sent() ) {
			return;
		}

		$created     = time();
		$cookie_data = array(
			'contact_id' => (int) $this->current_contact_id,
			'created'    => $created,
			'sig'        => self::sign( (int) $this->current_contact_id . '|' . $created ),
		);

		$expiry = time() + ( self::COOKIE_EXPIRY_DAYS * DAY_IN_SECONDS );

		setcookie(
			self::TRACKING_COOKIE,
			wp_json_encode( $cookie_data ),
			$expiry,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true // HttpOnly
		);
	}

	/**
	 * Remember page visit in cookie for anonymous users
	 *
	 * @since 1.2.0
	 *
	 * @param string $url URL visited.
	 * @param bool   $was_tracked Whether it was already tracked to DB.
	 */
	private function remember_page_visit_in_cookie( $url, $was_tracked = false ) {
		if ( headers_sent() ) {
			return;
		}

		$parsed_url    = wp_parse_url( $url );
		$path          = $parsed_url['path'] ?? '/';
		$pages_visited = array();

		if ( isset( $_COOKIE[ self::PAGE_VISITS_COOKIE ] ) ) {
			$pages_visited = json_decode( stripslashes( $_COOKIE[ self::PAGE_VISITS_COOKIE ] ), true ) ?: array();
		}

		// Limit cookie size - max 50 pages
		if ( count( $pages_visited ) >= 50 ) {
			array_shift( $pages_visited );
		}

		$pages_visited[] = array(
			'path'      => $path,
			'timestamp' => time(),
			'tracked'   => $was_tracked ? 1 : 0,
		);

		$expiry = time() + HOUR_IN_SECONDS;

		setcookie(
			self::PAGE_VISITS_COOKIE,
			wp_json_encode( $pages_visited ),
			$expiry,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			false // Need JavaScript access
		);
	}

	/**
	 * Track anonymous visits after signup
	 *
	 * @since 1.2.0
	 *
	 * @param ContactModel|int $contact Contact model or ID.
	 */
	public function track_anonymous_visits_after_signup( $contact ) {
		if ( is_int( $contact ) ) {
			$contact = ContactModel::find( $contact );
		}

		if ( ! $contact ) {
			return;
		}

		// Start tracking this contact
		$this->start_tracking( $contact );

		// Get pages from cookie
		if ( ! isset( $_COOKIE[ self::PAGE_VISITS_COOKIE ] ) ) {
			return;
		}

		$pages_visited = json_decode( stripslashes( $_COOKIE[ self::PAGE_VISITS_COOKIE ] ), true );
		if ( empty( $pages_visited ) || ! is_array( $pages_visited ) ) {
			return;
		}

		foreach ( $pages_visited as $key => $page ) {
			if ( ! empty( $page['tracked'] ) ) {
				continue;
			}

			$tracked = $this->track_page_visit(
				// The browser kept the whole path (a site in a folder keeps its
				// folder in it): after the host, not after home_url(), or the
				// folder comes twice.
				self::site_origin() . '/' . ltrim( (string) $page['path'], '/' ),
				$contact
			);

			if ( $tracked ) {
				$pages_visited[ $key ]['tracked'] = 1;
			}
		}

		$all_tracked = true;
		foreach ( $pages_visited as $page ) {
			if ( empty( $page['tracked'] ) ) {
				$all_tracked = false;
				break;
			}
		}

		if ( headers_sent() ) {
			// Too late to change the browser's cookie (a page already printing).
			return;
		}

		if ( $all_tracked ) {
			setcookie(
				self::PAGE_VISITS_COOKIE,
				'',
				time() - 3600,
				COOKIEPATH,
				COOKIE_DOMAIN
			);
			unset( $_COOKIE[ self::PAGE_VISITS_COOKIE ] );
		} else {
			// partial update
			setcookie(
				self::PAGE_VISITS_COOKIE,
				wp_json_encode( $pages_visited ),
				time() + HOUR_IN_SECONDS,
				COOKIEPATH,
				COOKIE_DOMAIN
			);
		}
	}

	/**
	 * Scheme, host and port of the site, without its folder.
	 *
	 * @return string
	 */
	private static function site_origin(): string {
		$home = wp_parse_url( home_url() );

		return ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	}

	/**
	 * Handle user login
	 *
	 * @since 1.2.0
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user User object.
	 */
	public function on_user_login( $user_login, $user ) {
		$contact = ContactModel::where( 'email', $user->user_email )->first();
		if ( $contact ) {
			$this->start_tracking( $contact );
			$this->track_anonymous_visits_after_signup( $contact );
		}
	}

	/**
	 * Handle user logout
	 *
	 * @since 1.2.0
	 */
	public function on_user_logout() {
		setcookie( self::TRACKING_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
		unset( $_COOKIE[ self::TRACKING_COOKIE ] );
		$this->current_contact_id = null;
	}

	/**
	 * Enqueue tracking scripts on frontend
	 *
	 * @since 1.2.0
	 */
	public function enqueue_tracking_scripts() {
		// Skip wp-admin; front end and wp-login.php both have is_admin() === false.
		if ( is_admin() ) {
			return;
		}

		// Check if tracking is enabled (Settings → Website Tracking)
		if ( ! \DoubleScale\Pro\Core\UserRoles\PermissionsCompat::website_tracking_enabled() ) {
			return;
		}

		$tracking_js_path = \DOUBLESCALE_PRO_PLUGIN_DIR . 'assets/js/tracking.js';
		$tracking_ver     = is_readable( $tracking_js_path ) ? (string) filemtime( $tracking_js_path ) : \DOUBLESCALE_VERSION;

		wp_enqueue_script(
			'doublescale-tracking',
			\DOUBLESCALE_PRO_PLUGIN_URL . 'assets/js/tracking.js',
			array(),
			$tracking_ver,
			false
		);

		wp_localize_script(
			'doublescale-tracking',
			'doublescale_tracking_config',
			array(
				'rest_url'              => rest_url( 'doublescale/v1/tracking/' ),
				'nonce'                 => wp_create_nonce( 'wp_rest' ),
				'tracking_cookie'       => self::TRACKING_COOKIE,
				'page_visits_cookie'    => self::PAGE_VISITS_COOKIE,
				'cookie_path'           => COOKIEPATH,
				'is_logged_in'          => is_user_logged_in(),
				'has_tracking_cookie'   => (bool) $this->current_contact_id,
				'can_store_page_visits' => ! is_user_logged_in() && ! $this->current_contact_id,
				'disable_tracking'      => apply_filters( 'doublescale_disable_page_tracking', false ),
			)
		);
	}

	/**
	 * Get client IP address in binary format for VARBINARY storage
	 *
	 * @since 1.2.0
	 * @return string Binary IP address
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // CloudFlare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					// Convert IP to binary format for VARBINARY(16) storage
					return inet_pton( $ip );
				}
			}
		}

		// Default IP in binary format
		return inet_pton( '0.0.0.0' );
	}


	/**
	 * Cleanup old page visits based on retention settings
	 *
	 * @since 1.2.0
	 */
	public function cleanup_old_page_visits() {
		 $settings = get_option( 'doublescale_settings', array() );

		// Retention applies only while tracking is on.
		$tracking_settings = isset( $settings['website_tracking'] ) ? $settings['website_tracking'] : array();
		if ( ! \DoubleScale\Pro\Core\UserRoles\PermissionsCompat::website_tracking_enabled() ) {
			return;
		}

		$retention_type = isset( $tracking_settings['retention_type'] ) ? $tracking_settings['retention_type'] : 'days';

		// If retention type is 'never', don't delete anything
		if ( 'never' === $retention_type ) {
			return;
		}

		$retention_days = isset( $tracking_settings['retention_days'] ) ? absint( $tracking_settings['retention_days'] ) : 30;

		if ( $retention_days <= 0 ) {
			return;
		}

		// Calculate the cutoff date
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		try {
			PageVisitModel::where( 'created_at', '<', $cutoff_date )->delete();
			doublescale_get_logger()->info(
				'Cleaned up old page visits',
				array(
					'retention_days' => $retention_days,
					'cutoff_date'    => $cutoff_date,
				)
			);
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				'Error cleaning up old page visits',
				array(
					'error' => $e->getMessage(),
					'code'  => 'cleanup_old_page_visits_error',
				)
			);
		}
	}
}
