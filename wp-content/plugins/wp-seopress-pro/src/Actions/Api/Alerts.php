<?php // phpcs:ignore

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * REST API endpoint: GET /wp-json/seopress/v1/alerts
 *
 * Returns real-time status of the three SEO alert checks
 * (homepage noindex, robots.txt reachability, XML sitemap reachability).
 * Results are cached for 5 minutes via a transient.
 *
 * @package SEOPress PRO
 * @subpackage Actions/Api
 * @since 9.7.0
 */
class Alerts implements ExecuteHooks {

	const TRANSIENT_KEY    = 'seopress_api_alerts_cache';
	const CACHE_TTL        = 300; // 5 minutes.
	const FORCE_RATE_LIMIT = 60;  // Min seconds between forced refreshes per user.

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/alerts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'process' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'force' => array(
						'description'       => 'Bypass cache and force a fresh check.',
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
				'schema'              => array( $this, 'getSchema' ),
			)
		);
	}

	/**
	 * Permission callback — requires manage_options.
	 *
	 * @return bool|\WP_Error
	 */
	public function checkPermission() {
		if ( ! current_user_can( seopress_capability( 'manage_options', 'seo_alerts' ) ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access SEO alerts.', 'wp-seopress-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * JSON Schema for the endpoint response (used by the REST API index).
	 *
	 * @return array
	 */
	public function getSchema() {
		$check_schema = array(
			'type'       => 'object',
			'properties' => array(
				'status'      => array(
					'type'        => 'string',
					'enum'        => array( 'ok', 'error' ),
					'description' => 'Result of the check.',
				),
				'checked_url' => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'URL that was checked.',
				),
			),
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'seopress-alerts',
			'type'       => 'object',
			'properties' => array(
				'homepage_noindex' => array_merge_recursive(
					$check_schema,
					array(
						'properties' => array(
							'noindex_found' => array(
								'type'        => 'boolean',
								'description' => 'Whether a noindex directive was found on the homepage.',
							),
						),
					)
				),
				'robots_txt'       => array_merge_recursive(
					$check_schema,
					array(
						'properties' => array(
							'http_code' => array(
								'type'        => 'integer',
								'description' => 'HTTP status code returned by the robots.txt URL.',
							),
						),
					)
				),
				'xml_sitemap'      => array_merge_recursive(
					$check_schema,
					array(
						'properties' => array(
							'errors'   => array(
								'type'        => 'array',
								'description' => 'Failing diagnostic checks (label, message, doc).',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'label'   => array( 'type' => 'string' ),
										'message' => array( 'type' => 'string' ),
										'doc'     => array( 'type' => 'string' ),
									),
								),
							),
							'disabled' => array(
								'type'        => 'boolean',
								'description' => 'Whether the XML sitemap feature is disabled (check skipped).',
							),
						),
					)
				),
				'timestamp'        => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'ISO 8601 UTC timestamp of when the checks were run.',
				),
			),
		);
	}

	/**
	 * Endpoint callback.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process( \WP_REST_Request $request ) {
		$force    = $request->get_param( 'force' );
		$rate_key = 'seopress_api_alerts_force_' . get_current_user_id();

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( false !== $cached ) {
				return rest_ensure_response( $cached );
			}
		} else {
			// Rate-limit forced refreshes: one per user per FORCE_RATE_LIMIT seconds.
			if ( get_transient( $rate_key ) ) {
				return new \WP_Error(
					'too_many_requests',
					sprintf(
						/* translators: %d: number of seconds to wait before retrying */
						__( 'Please wait %d seconds before forcing a new alert check.', 'wp-seopress-pro' ),
						self::FORCE_RATE_LIMIT
					),
					array( 'status' => 429 )
				);
			}
			set_transient( $rate_key, 1, self::FORCE_RATE_LIMIT );
		}

		$data = $this->runChecks();

		set_transient( self::TRANSIENT_KEY, $data, self::CACHE_TTL );

		return rest_ensure_response( $data );
	}

	/**
	 * Resolve the robots.txt URL to check.
	 *
	 * Delegates to the shared helper so the REST dashboard indicator and the
	 * email/Slack alert cron resolve the exact same URL, accounting for
	 * subdirectory multisite (network root) and language plugins such as WPML.
	 *
	 * @return string
	 */
	private function getRobotsTxtUrl() {
		return seopress_pro_get_robots_txt_alert_url();
	}

	/**
	 * Run the three SEO alert checks in parallel and return the result array.
	 *
	 * Uses the Requests library bundled with WordPress so all enabled checks
	 * fire concurrently, reducing worst-case latency from N×timeout to one timeout.
	 * Falls back to sequential wp_remote_get when the library is unavailable.
	 *
	 * @return array
	 */
	private function runChecks() {
		$option = seopress_pro_get_service( 'OptionPro' );

		// Build the list of URLs to check, keyed by result slot.
		$checks = array();
		if ( $option->getSEOAlertsNoIndex() === '1' ) {
			$checks['homepage_noindex'] = get_home_url();
		}
		if ( $option->getSEOAlertsRobotsTxt() === '1' ) {
			$checks['robots_txt'] = $this->getRobotsTxtUrl();
		}
		// The XML sitemap is evaluated separately through the comprehensive
		// diagnostic, which runs its own requests — it is not part of the
		// parallel HTTP batch.

		$responses = $this->fetchParallel( $checks );

		$result = array(
			'homepage_noindex' => null,
			'robots_txt'       => null,
			'xml_sitemap'      => null,
			'timestamp'        => ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM ),
		);

		// Homepage noindex — requires DOM parsing.
		if ( isset( $responses['homepage_noindex'] ) ) {
			$body          = $responses['homepage_noindex']['body'];
			$noindex_found = false;
			if ( '' !== $body ) {
				$dom = new \DOMDocument();
				libxml_use_internal_errors( true );
				if ( $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $body ) ) {
					$xpath       = new \DOMXPath( $dom );
					$meta_robots = $xpath->query( '//meta[@name="robots"]' );
					if ( $meta_robots->length > 0 ) {
						$content = $meta_robots->item( 0 )->getAttribute( 'content' );
						if ( strpos( $content, 'noindex' ) !== false ) {
							$noindex_found = true;
						}
					}
				}
				libxml_clear_errors();
			}
			$result['homepage_noindex'] = array(
				'status'        => $noindex_found ? 'error' : 'ok',
				'checked_url'   => $checks['homepage_noindex'],
				'noindex_found' => $noindex_found,
			);
		}

		// robots.txt — HTTP status only.
		if ( isset( $responses['robots_txt'] ) ) {
			$code                 = $responses['robots_txt']['code'];
			$result['robots_txt'] = array(
				'status'      => 200 === $code ? 'ok' : 'error',
				'checked_url' => $checks['robots_txt'],
				'http_code'   => $code,
			);
		}

		// XML sitemap — comprehensive diagnostic (free plugin). Only error-level
		// issues mark the check as failing; a disabled sitemap stays "ok".
		if ( $option->getSEOAlertsXMLSitemaps() === '1' ) {
			$sitemap               = seopress_pro_get_service( 'SitemapAlert' )->evaluate();
			$result['xml_sitemap'] = array(
				'status'      => $sitemap['has_error'] ? 'error' : 'ok',
				'checked_url' => $sitemap['url'],
				'errors'      => $sitemap['errors'],
				'disabled'    => $sitemap['disabled'],
			);
		}

		return $result;
	}

	/**
	 * Fetch multiple URLs in parallel using the Requests library bundled with WordPress.
	 *
	 * WordPress 6.2+ ships WpOrg\Requests\Requests; older versions ship the Requests class.
	 * Both expose a static request_multiple() method that runs all requests concurrently,
	 * reducing worst-case latency from N×timeout to one timeout.
	 * Falls back to sequential wp_remote_get when neither class is available.
	 *
	 * @param array $urls Associative array of [ key => url ].
	 * @return array      Associative array of [ key => [ 'code' => int, 'body' => string ] ].
	 */
	private function fetchParallel( array $urls ) {
		if ( empty( $urls ) ) {
			return array();
		}

		$requests_class = null;
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$requests_class = '\WpOrg\Requests\Requests';
		} elseif ( class_exists( 'Requests' ) ) {
			$requests_class = 'Requests';
		}

		if ( null === $requests_class ) {
			return $this->fetchSequential( $urls );
		}

		$requests = array();
		foreach ( $urls as $key => $url ) {
			$requests[ $key ] = array(
				'url'     => $url,
				'headers' => array(),
				'data'    => array(),
				'type'    => $requests_class::GET,
				'options' => array(
					'timeout'   => 10,
					'redirects' => 1,
					'useragent' => 'SEOPress/' . SEOPRESS_PRO_VERSION . ' (alerts)',
				),
			);
		}

		try {
			$responses = $requests_class::request_multiple( $requests );
		} catch ( \Exception $e ) {
			return $this->fetchSequential( $urls );
		}

		$results = array();
		foreach ( $responses as $key => $response ) {
			$is_error        = ( $response instanceof \Exception );
			$results[ $key ] = array(
				'code' => $is_error ? 0 : (int) $response->status_code,
				'body' => $is_error ? '' : (string) $response->body,
			);
		}

		return $results;
	}

	/**
	 * Sequential fallback using wp_remote_get.
	 *
	 * @param array $urls Associative array of [ key => url ].
	 * @return array      Associative array of [ key => [ 'code' => int, 'body' => string ] ].
	 */
	private function fetchSequential( array $urls ) {
		$results = array();
		foreach ( $urls as $key => $url ) {
			$response        = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 1,
				)
			);
			$results[ $key ] = array(
				'code' => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ),
				'body' => is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response ),
			);
		}
		return $results;
	}
}
