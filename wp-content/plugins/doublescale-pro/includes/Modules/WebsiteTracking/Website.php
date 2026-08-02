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

		$this->current_contact_id = $contact_id;
		$this->build_tracking_cookie();
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

		$cookie_data = json_decode( stripslashes( $_COOKIE[ self::TRACKING_COOKIE ] ), true );
		if ( is_array( $cookie_data ) && isset( $cookie_data['contact_id'] ) ) {
			$this->current_contact_id = absint( $cookie_data['contact_id'] );
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

		$cookie_data = array(
			'contact_id' => $this->current_contact_id,
			'created'    => time(),
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
				home_url( $page['path'] ),
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

		// Check if tracking is enabled from settings
		$settings          = get_option( 'doublescale_settings', array() );
		$tracking_settings = isset( $settings['website_tracking'] ) ? $settings['website_tracking'] : array();
		$enabled_raw       = isset( $tracking_settings['enabled'] ) ? $tracking_settings['enabled'] : true;
		$enabled           = filter_var( $enabled_raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $enabled ) {
			$enabled = (bool) $enabled_raw;
		}

		// Check if tracking is enabled
		$tracking_enabled = apply_filters( 'doublescale_website_tracking_enabled', $enabled );
		if ( ! $tracking_enabled ) {
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
				'has_tracking_cookie'   => isset( $_COOKIE[ self::TRACKING_COOKIE ] ),
				'can_store_page_visits' => ! is_user_logged_in() && ! isset( $_COOKIE[ self::TRACKING_COOKIE ] ),
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

		// Check if website tracking is enabled
		$tracking_settings = isset( $settings['website_tracking'] ) ? $settings['website_tracking'] : array();
		$enabled           = isset( $tracking_settings['enabled'] ) ? $tracking_settings['enabled'] : true;

		if ( ! $enabled ) {
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
