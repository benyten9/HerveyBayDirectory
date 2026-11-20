<?php
/**
 * Tracking Service
 *
 * Handles detailed view tracking, click tracking, and search appearance tracking.
 *
 * @package DirectoristAnalytics
 * @subpackage Services
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Services;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Models\View;
use DirectoristAnalytics\App\Models\Click;
use DirectoristAnalytics\App\Models\Search;

/**
 * Tracking Service Class
 *
 * @since 1.0.0
 */
class TrackingService {

	/**
	 * Stats service instance
	 *
	 * @var StatsService
	 */
	private $stats_service;

	/**
	 * Privacy service instance
	 *
	 * @var PrivacyService
	 */
	private $privacy_service;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->stats_service = new StatsService();
		$this->privacy_service = new PrivacyService();
	}

	/**
	 * Track detailed view metadata
	 *
	 * Hooked into core's 'directorist_listing_views_count_updated' action.
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	public function track_detailed_view( $listing_id ) {
		$listing_id = absint( $listing_id );

		if ( ! $this->privacy_service->should_track() ) {
			return false;
		}

		if ( ! $listing_id ) {
			return false;
		}

		// Skip if bot
		if ( $this->is_bot() ) {
			return false;
		}

		// Get session ID
		$session_id = $this->get_session_id();

		// Check if unique view
		$is_unique = $this->is_unique_view( $listing_id, $session_id );

		// Get visitor data
		$ip_address   = $this->get_client_ip();
		$user_agent   = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$referrer_url = $this->get_referrer_url();

		// Parse visitor data
		$country  = $this->get_country_from_ip( $ip_address );
		$ua_data  = $this->parse_user_agent( $user_agent );
		$referrer = $this->detect_referrer_type( $referrer_url );

		// Insert view record
		View::query()->insert(
			array(
				'listing_id'        => $listing_id,
				'session_id'        => $session_id,
				'ip_address'        => $this->privacy_service->should_anonymize_ip() ? $this->anonymize_ip( $ip_address ) : $ip_address,
				'country_code'      => $country['code'],
				'country_name'      => $country['name'],
				'referrer_url'      => $this->get_privacy_safe_referrer( $referrer_url ),
				'referrer_type'     => $referrer['type'],
				'search_engine'     => $referrer['engine'],
				'user_agent'        => sanitize_text_field( $user_agent ),
				'browser'           => $ua_data['browser'],
				'browser_version'   => $ua_data['browser_version'],
				'os'                => $ua_data['os'],
				'os_version'        => $ua_data['os_version'],
				'device_type'       => $ua_data['device_type'],
				'screen_resolution' => '', // Can be sent from frontend if needed
				'created_at'        => $this->get_privacy_safe_timestamp(),
			)
		);

		// Update daily stats
		$this->stats_service->increment_view_count( $listing_id, $is_unique );

		// Update aggregated stats
		$stat_date = current_time( 'Y-m-d' );

		// Tech stats aggregation
		$this->stats_service->upsert_tech_stat(
			$stat_date,
			$ua_data['browser'],
			$ua_data['os'],
			$ua_data['device_type'],
			'' // Screen resolution (updated via frontend)
		);

		// Country stats aggregation (only if country detected)
		if ( ! empty( $country['code'] ) ) {
			$this->stats_service->upsert_country_stat(
				$stat_date,
				$country['code'],
				$country['name']
			);
		}

		// Search engine stats aggregation (only if from search engine)
		if ( 'search_engine' === $referrer['type'] && ! empty( $referrer['engine'] ) ) {
			$this->stats_service->upsert_search_engine_stat(
				$stat_date,
				$referrer['engine']
			);
		}

		// Referral stats aggregation
		if ( ! empty( $referrer_url ) ) {
			$referrer_domain = $this->extract_domain( $referrer_url );
			$this->stats_service->upsert_referral_stat(
				$stat_date,
				$referrer_domain,
				$referrer['type']
			);
		}

		return true;
	}

	/**
	 * Track click event
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $click_type Click type (contact_form, website, phone, email).
	 * @return bool
	 */
	public function track_click( $listing_id, $click_type ) {
		$listing_id = absint( $listing_id );

		if ( ! $this->privacy_service->should_track() ) {
			return false;
		}

		$allowed_types = array( 'contact_form', 'website', 'phone', 'email' );
		if ( ! in_array( $click_type, $allowed_types, true ) ) {
			return false;
		}

		$session_id = $this->get_session_id();

		// Insert click record
		Click::query()->insert(
			array(
				'listing_id' => $listing_id,
				'click_type' => $click_type,
				'session_id' => $session_id,
				'created_at' => current_time( 'mysql' ),
			)
		);

		// Update daily stats
		$this->stats_service->increment_click_count( $listing_id, $click_type );

		return true;
	}

	/**
	 * Track search appearance
	 *
	 * @since 1.0.0
	 * @param int   $listing_id    Listing ID.
	 * @param array $search_data   Search data (query, filters, position, total_results).
	 * @return bool
	 */
	public function track_search_appearance( $listing_id, $search_data = array() ) {
		$listing_id = absint( $listing_id );

		if ( ! $this->privacy_service->should_track() ) {
			return false;
		}

		Search::query()->insert(
			array(
				'listing_id'     => $listing_id,
				'search_query'   => sanitize_text_field( $search_data['query'] ?? '' ),
				'search_filters' => wp_json_encode( $search_data['filters'] ?? array() ),
				'position'       => absint( $search_data['position'] ?? 0 ),
				'total_results'  => absint( $search_data['total_results'] ?? 0 ),
				'clicked'        => 0,
				'created_at'     => current_time( 'mysql' ),
			)
		);

		// Update daily stats
		$this->stats_service->increment_search_appearance( $listing_id );

		return true;
	}

		/**
	 * Get or create session ID
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_session_id() {
		$cookie_name = 'directorist_analytics_session';

		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			return sanitize_text_field( $_COOKIE[ $cookie_name ] );
		}

		// Generate new session ID
		$session_id = hash( 'sha256', wp_generate_password( 64, true, true ) . time() . wp_rand() );

		// Set cookie for 7 days (only if headers not sent)
		if ( ! headers_sent() ) {
			setcookie( $cookie_name, $session_id, time() + ( 7 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		return $session_id;
	}

	/**
	 * Check if view is unique for this session
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $session_id Session ID.
	 * @return bool
	 */
	private function is_unique_view( $listing_id, $session_id ) {
		$existing = View::query()
			->where( 'listing_id', $listing_id )
			->where( 'session_id', $session_id )
			->first();

		return ! $existing;
	}

	/**
	 * Get client IP address
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Anonymize IP address (GDPR compliance)
	 *
	 * @since 1.0.0
	 * @param string $ip IP address.
	 * @return string
	 */
	private function anonymize_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// IPv4: Mask last octet
			return preg_replace( '/\.\d+$/', '.0', $ip );
		} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// IPv6: Mask last 80 bits
			return preg_replace( '/(.*):.*:.*:.*:.*/', '$1::', $ip );
		}

		return $ip;
	}

	/**
	 * Get country from IP using free GeoIP API
	 *
	 * @since 1.0.0
	 * @param string $ip IP address.
	 * @return array
	 */
	private function get_country_from_ip( $ip ) {
		// Check transient cache
		$cache_key = 'directorist_geoip_' . md5( $ip );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// Default
		$country = array(
			'code' => null,
			'name' => null,
		);

		// Skip local IPs
		if ( in_array( $ip, array( '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return $country;
		}

		// Call free API (ip-api.com - 45 req/min limit)
		$response = wp_remote_get( "http://ip-api.com/json/{$ip}?fields=status,countryCode,country", array( 'timeout' => 2 ) );

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $data['status'] ) && 'success' === $data['status'] ) {
				$country = array(
					'code' => $data['countryCode'] ?? null,
					'name' => $data['country'] ?? null,
				);
			}
		}

		// Cache for 1 hour
		set_transient( $cache_key, $country, HOUR_IN_SECONDS );

		return $country;
	}

	/**
	 * Parse user agent
	 *
	 * @since 1.0.0
	 * @param string $user_agent User agent string.
	 * @return array
	 */
	private function parse_user_agent( $user_agent ) {
		$data = array(
			'browser'         => null,
			'browser_version' => null,
			'os'              => null,
			'os_version'      => null,
			'device_type'     => 'Desktop',
		);

		if ( empty( $user_agent ) ) {
			return $data;
		}

        // Detect browser
        $browsers = array(
            'Edge'    => 'Edg',
            'Opera'   => 'OPR',
            'Chrome'  => 'Chrome',
            'Firefox' => 'Firefox',
        );

        foreach ( $browsers as $name => $pattern ) {
            if ( preg_match( "/{$pattern}\/([0-9.]+)/", $user_agent, $matches ) ) {
                $data['browser']         = $name;
                $data['browser_version'] = $matches[1];
                break;
            }
        }

        // Safari detection (must check AFTER Chrome/Edge since their UA contains "Safari")
        if ( ! $data['browser'] && false !== stripos( $user_agent, 'Safari' ) && false === stripos( $user_agent, 'Chrome' ) ) {
            if ( preg_match( '/Version\/([0-9.]+)/', $user_agent, $matches ) ) {
                $data['browser']         = 'Safari';
                $data['browser_version'] = $matches[1];
            }
        }

		// Detect OS
		$os_patterns = array(
			'Windows' => 'Windows NT ([0-9.]+)',
			'Android' => 'Android ([0-9.]+)',     // ← Check Android BEFORE Linux
			'iOS'     => 'iPhone OS ([0-9_]+)',
			'macOS'   => 'Mac OS X ([0-9_]+)',
			'Linux'   => 'Linux',                 // ← Check Linux LAST
		);

		foreach ( $os_patterns as $name => $pattern ) {
			if ( preg_match( "/{$pattern}/", $user_agent, $matches ) ) {
				$data['os']         = $name;
				$data['os_version'] = isset( $matches[1] ) ? str_replace( '_', '.', $matches[1] ) : null;
				break;
			}
		}

		// Detect device type
		if ( preg_match( '/(tablet|ipad|playbook)|(android(?!.*mobile))/i', $user_agent ) ) {
			$data['device_type'] = 'Tablet';
		} elseif ( preg_match( '/mobile|android|iphone|ipod|blackberry|windows phone/i', $user_agent ) ) {
			$data['device_type'] = 'Mobile';
		}

		return $data;
	}

	/**
	 * Get referrer URL from UTM parameters or HTTP header
	 *
	 * Follows Google Analytics standard:
	 * 1. UTM parameters (most reliable)
	 * 2. Click IDs (for paid ads)
	 * 3. HTTP_REFERER (fallback)
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function get_referrer_url() {
		// Priority 1: Check UTM/Click ID cookie (set by JavaScript)
		if ( isset( $_COOKIE['directorist_attribution'] ) ) {
			$cookie_value = sanitize_text_field( wp_unslash( $_COOKIE['directorist_attribution'] ) );
			$attribution = array();
			parse_str( $cookie_value, $attribution );
			$attribution = array_map( 'sanitize_text_field', $attribution );
			
			// UTM Source
			if ( ! empty( $attribution['utm_source'] ) ) {
				return $this->construct_url_from_utm_source( $attribution['utm_source'] );
			}
			
			// Google Ads Click ID
			if ( ! empty( $attribution['gclid'] ) ) {
				return 'https://www.google.com/search';
			}
			
			// Facebook Ads Click ID
			if ( ! empty( $attribution['fbclid'] ) ) {
				return 'https://www.facebook.com';
			}
			
			// Bing Ads Click ID
			if ( ! empty( $attribution['msclkid'] ) ) {
				return 'https://www.bing.com/search';
			}
		}
		
		// Priority 2: Check JavaScript referrer (more reliable than HTTP_REFERER)
		if ( isset( $_COOKIE['directorist_js_referrer'] ) ) {
			$js_referrer = sanitize_text_field( wp_unslash( $_COOKIE['directorist_js_referrer'] ) );
			if ( ! empty( $js_referrer ) ) {
				// Construct URL from hostname
				return 'https://' . $js_referrer;
			}
		}
	
		// Priority 3: Fallback to HTTP_REFERER (least reliable)
		return isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
	}

	/**
	 * Construct referrer URL from UTM source
	 *
	 * Maps common UTM source values to actual URLs.
	 *
	 * @since 1.0.0
	 * @param string $utm_source UTM source value.
	 * @return string
	 */
	private function construct_url_from_utm_source( $utm_source ) {
		$utm_source = strtolower( trim( $utm_source ) );
		
		// Map common sources to URLs
		$source_map = array(
			// Social Media
			'youtube'   => 'https://www.youtube.com',
			'facebook'  => 'https://www.facebook.com',
			'twitter'   => 'https://twitter.com',
			'x'         => 'https://twitter.com',
			'instagram' => 'https://www.instagram.com',
			'linkedin'  => 'https://www.linkedin.com',
			'pinterest' => 'https://www.pinterest.com',
			'tiktok'    => 'https://www.tiktok.com',
			'reddit'    => 'https://www.reddit.com',
			'snapchat'  => 'https://www.snapchat.com',
			
			// Messaging Apps
			'whatsapp'  => 'https://www.whatsapp.com',
			'telegram'  => 'https://t.me',
			'messenger' => 'https://www.messenger.com',
			'wechat'    => 'https://www.wechat.com',
			
			// Search Engines
			'google'     => 'https://www.google.com/search',
			'bing'       => 'https://www.bing.com/search',
			'yahoo'      => 'https://search.yahoo.com',
			'duckduckgo' => 'https://duckduckgo.com',
			'baidu'      => 'https://www.baidu.com',
			
			// Email
			'email'      => 'email',
			'newsletter' => 'newsletter',
			'mailchimp'  => 'mailchimp',
			'gmail'      => 'gmail',
		);
		
		// Return mapped URL if found
		if ( isset( $source_map[ $utm_source ] ) ) {
			return $source_map[ $utm_source ];
		}
		
		// For unknown sources, try to construct a URL
		if ( ! empty( $utm_source ) ) {
			// If it looks like a domain, use it
			if ( preg_match( '/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $utm_source ) ) {
				return 'https://' . $utm_source;
			}
			
			// Otherwise, append .com
			return 'https://' . $utm_source . '.com';
		}
		
		return '';
	}

	/**
	 * Detect referrer type
	 *
	 * @since 1.0.0
	 * @param string $referrer_url Referrer URL.
	 * @return array
	 */
	private function detect_referrer_type( $referrer_url ) {
		$data = array(
			'type'   => 'direct',
			'engine' => null,
		);

		if ( empty( $referrer_url ) ) {
			return $data;
		}

		// Check if same domain (direct)
		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$referrer_host = wp_parse_url( $referrer_url, PHP_URL_HOST );

		if ( $site_host === $referrer_host ) {
			return $data;
		}

		// Search engines
		$search_engines = array(
			'google'     => 'google',
			'bing'       => 'bing',
			'yahoo'      => 'yahoo',
			'duckduckgo' => 'duckduckgo',
			'baidu'      => 'baidu',
		);

		foreach ( $search_engines as $engine => $pattern ) {
			if ( false !== stripos( $referrer_host, $pattern ) ) {
				return array(
					'type'   => 'search_engine',
					'engine' => ucfirst( $engine ),
				);
			}
		}

		$social_platforms = array( 'facebook', 'twitter', 'linkedin', 'instagram', 'pinterest', 'reddit', 'tiktok', 'youtube', 'snapchat', 'whatsapp', 'telegram', 'messenger', 't.me' );

		foreach ( $social_platforms as $platform ) {
			if ( false !== stripos( $referrer_host, $platform ) ) {
				return array(
					'type'   => 'social',
					'engine' => null,
				);
			}
		}

		// Everything else is referral
		return array(
			'type'   => 'referral',
			'engine' => null,
		);
	}

	/**
	 * Check if request is from a bot
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_bot() {
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

		$bot_patterns = array(
			'bot',
			'crawler',
			'spider',
			'slurp',
			'scraper',
			'mediapartners',
		);

		foreach ( $bot_patterns as $pattern ) {
			if ( false !== stripos( $user_agent, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get privacy-safe referrer URL
	 *
	 * @since 1.0.0
	 * @param string $url Referrer URL.
	 * @return string
	 */
	private function get_privacy_safe_referrer( $url ) {
		$settings = $this->privacy_service->get_settings();
		
		if ( ! empty( $settings['enable_data_protection'] ) ) {
			return $this->privacy_service->strip_query_params( $url );
		}
		
		return esc_url_raw( $url );
	}

	/**
	 * Get privacy-safe timestamp
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function get_privacy_safe_timestamp() {
		$settings = $this->privacy_service->get_settings();
		$timestamp = current_time( 'mysql' );
		
		if ( ! empty( $settings['enable_data_protection'] ) ) {
			return $this->privacy_service->round_to_hour( $timestamp );
		}
		
		return $timestamp;
	}

	/**
	 * Extract domain from URL
	 *
	 * @since 1.0.0
	 * @param string $url URL to extract domain from.
	 * @return string
	 */
	private function extract_domain( $url ) {
		$parsed = wp_parse_url( $url );
		
		if ( ! isset( $parsed['host'] ) ) {
			return '';
		}
		
		return sanitize_text_field( $parsed['host'] );
	}
}