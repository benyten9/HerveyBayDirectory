<?php
/**
 * REST controller for the Site Audit admin page.
 *
 * @package    SEOPress PRO
 * @subpackage Actions\Api
 */

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Helpers\Audit\SEOIssues;
use SEOPressPro\Helpers\Audit\SEOIssueName;
use SEOPressPro\Helpers\Audit\SEOIssueDesc;
use SEOPressPro\Helpers\Audit\HomepageAudit;

/**
 * REST controller powering the React "Site Audit" admin page (seopress-bot-batch).
 *
 * Namespace: seopress/v1
 * Routes:
 *   - GET    /site-audit/overview
 *   - GET    /site-audit/issues
 *   - GET    /site-audit/issues/{type}
 *   - GET    /site-audit/settings
 *   - POST   /site-audit/settings
 *   - GET    /site-audit/view-preferences
 *   - POST   /site-audit/view-preferences
 *
 * @since 9.8.0
 */
class SiteAudit implements ExecuteHooks {

	/**
	 * Capability required to read and manage the Site Audit screen.
	 * Mirrors what the legacy menu uses (`seopress-bot-batch`).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Capability scope passed to seopress_capability() so Pro can remap
	 * to a custom role capability when user roles are enabled.
	 */
	const CAPABILITY_SCOPE = 'bot';

	/**
	 * Allowed issue types. Built from SEOIssues::getData() on demand
	 * so we never trust the input type blindly.
	 *
	 * @var string[]|null
	 */
	private $allowed_types = null;

	/**
	 * Sortable DataViews field ids, and where their value comes from.
	 *
	 * Doubles as the allowlist for the `orderby` parameter: nothing the
	 * request supplies ever reaches the query, only the expression that
	 * orderByExpression() builds for a key of this map.
	 *
	 * A `meta` entry is read through a correlated subquery rather than a
	 * LEFT JOIN, so a post carrying the same meta_key twice cannot duplicate
	 * its issue rows in the result set.
	 *
	 * @var array<string, array{source: string, key?: string, numeric?: bool}>
	 */
	const SORTABLE_FIELDS = array(
		'priority'       => array( 'source' => 'priority' ),
		'postTitle'      => array( 'source' => 'column' ),
		'issueName'      => array( 'source' => 'column' ),
		'targetKeyword'  => array(
			'source' => 'meta',
			'key'    => '_seopress_analysis_target_kw',
		),
		'gscClicks'      => array(
			'source'  => 'meta',
			'key'     => '_seopress_search_console_analysis_clicks',
			'numeric' => true,
		),
		'gscImpressions' => array(
			'source'  => 'meta',
			'key'     => '_seopress_search_console_analysis_impressions',
			'numeric' => true,
		),
		'gscPosition'    => array(
			'source'  => 'meta',
			'key'     => '_seopress_search_console_analysis_position',
			'numeric' => true,
		),
	);

	/**
	 * Plain columns behind the sortable fields whose source is `column`.
	 *
	 * @var array<string, string>
	 */
	const SORTABLE_COLUMNS = array(
		'postTitle' => 'posts.post_title',
		'issueName' => 'issues.issue_name',
	);

	/**
	 * Bind REST registration to the WP REST bootstrap.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		$permission = array( $this, 'permissionCallback' );

		register_rest_route(
			'seopress/v1',
			'/site-audit/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processOverview' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetIssues' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/issues/(?P<type>[a-z0-9_]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetIssueDetails' ),
				'permission_callback' => $permission,
				'args'                => array(
					'type'       => array(
						'validate_callback' => array( $this, 'validateIssueType' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page'   => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0 && (int) $param <= 100;
						},
						'sanitize_callback' => 'absint',
						'default'           => 25,
					),
					'page'       => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
					'ignored'    => array(
						'validate_callback' => function ( $param ) {
							return in_array( (string) $param, array( '0', '1' ), true );
						},
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					// Search, sort and filters are resolved in SQL, not on the
					// page that was already sent: with 25 rows per page and
					// thousands of issues, applying them client-side only ever
					// searched the slice the user happened to be looking at.
					'search'     => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'orderby'    => array(
						'validate_callback' => function ( $param ) {
							return array_key_exists( (string) $param, self::SORTABLE_FIELDS );
						},
						// Not sanitize_key(): field ids are camelCase on the
						// DataViews side, and lowercasing them here would turn
						// every sort into the default one.
						'sanitize_callback' => function ( $param ) {
							$param = (string) $param;

							return array_key_exists( $param, self::SORTABLE_FIELDS ) ? $param : 'priority';
						},
						'default'           => 'priority',
					),
					'order'      => array(
						'validate_callback' => function ( $param ) {
							return in_array( strtolower( (string) $param ), array( 'asc', 'desc' ), true );
						},
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'asc',
					),
					// Comma-separated lists, mirroring the `isAny` operator of
					// the matching DataViews column filters.
					'issue_name' => array(
						'sanitize_callback' => array( $this, 'sanitizeSlugList' ),
						'default'           => '',
					),
					'priority'   => array(
						'sanitize_callback' => array( $this, 'sanitizeSlugList' ),
						'default'           => '',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGetSettings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processSaveSettings' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/settings-meta',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetSettingsMeta' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/issues/bulk-ignore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processBulkIgnoreIssues' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/posts/(?P<post_id>\d+)/rescan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processRescanPost' ),
				'permission_callback' => $permission,
				'args'                => array(
					'post_id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/issues/(?P<id>\d+)/generate-alt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processGenerateAlt' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/issues/(?P<type>[a-z0-9_]+)/(?P<id>\d+)/ignore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processIgnoreIssue' ),
				'permission_callback' => $permission,
				'args'                => array(
					'type'    => array(
						'validate_callback' => array( $this, 'validateIssueType' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'id'      => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'ignored' => array(
						'validate_callback' => function ( $param ) {
							return is_bool( $param ) || in_array( (string) $param, array( '0', '1', 'true', 'false' ), true );
						},
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processScan' ),
				'permission_callback' => $permission,
				'args'                => array(
					'action' => array(
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'start', 'cancel' ), true );
						},
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'start',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetHistory' ),
				'permission_callback' => $permission,
				'args'                => array(
					'days' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0 && (int) $param <= 3650;
						},
						'sanitize_callback' => 'absint',
						'default'           => 30,
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/scan-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processScanStatus' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/homepage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processHomepage' ),
				'permission_callback' => $permission,
				'args'                => array(
					'refresh' => array(
						'validate_callback' => function ( $param ) {
							return in_array( (string) $param, array( '0', '1' ), true );
						},
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'cached'  => array(
						'validate_callback' => function ( $param ) {
							return in_array( (string) $param, array( '0', '1' ), true );
						},
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/priority-urls',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetPriorityUrls' ),
				'permission_callback' => $permission,
				'args'                => array(
					'limit' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0 && (int) $param <= 50;
						},
						'sanitize_callback' => 'absint',
						'default'           => 10,
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/site-audit/view-preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGetViewPreferences' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processSaveViewPreferences' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * Shared permission callback. Honours SEOPress custom role capabilities
	 * via seopress_capability().
	 *
	 * @return bool
	 */
	public function permissionCallback() {
		$cap = function_exists( 'seopress_capability' )
			? seopress_capability( self::CAPABILITY, self::CAPABILITY_SCOPE )
			: self::CAPABILITY;

		return current_user_can( $cap );
	}

	/**
	 * Validate that the requested issue type is one we know about.
	 *
	 * @param string $param The candidate issue type slug.
	 *
	 * @return bool
	 */
	public function validateIssueType( $param ) {
		return in_array( (string) $param, $this->getAllowedTypes(), true );
	}

	/**
	 * Return the allowed issue type slugs (keys of SEOIssues::getData()).
	 *
	 * @return string[]
	 */
	private function getAllowedTypes() {
		if ( null === $this->allowed_types ) {
			$this->allowed_types = array_keys( SEOIssues::getData() );
		}

		return $this->allowed_types;
	}

	/**
	 * Overview payload: crawled URLs, total issues, per-type counts, last scan.
	 *
	 * @return \WP_REST_Response
	 */
	public function processOverview() {
		$service = $this->getSiteAuditService();

		$total_crawled = (int) ( $service ? $service->countTotalCrawledURL() : 0 );
		$counts        = $this->getOverviewIssueCounts();
		$total_issues  = $counts['total'];
		$total_ignored = $counts['ignored'];
		$by_priority   = $counts['by_priority'];

		// Severity-weighted average issues per crawled URL. Each URL starts
		// at 100; one High issue costs 3 pts, Medium 2, Low 1, averaged across
		// the site. Rationale: keeps the score interpretable (no arbitrary
		// multiplier) so a mildly unoptimized site lands in the 60–80 range
		// rather than bottoming out at 0.
		$denominator = max( 1, $total_crawled );
		$penalty     = ( $by_priority['high'] * 3 + $by_priority['medium'] * 2 + $by_priority['low'] * 1 ) / $denominator;
		$score       = (int) max( 0, min( 100, round( 100 - $penalty ) ) );

		$payload = array(
			'totalCrawled' => $total_crawled,
			'totalIssues'  => $total_issues,
			'totalIgnored' => $total_ignored,
			'byPriority'   => $by_priority,
			'healthScore'  => $score,
			'topIssues'    => $this->getTopIssues( 3 ),
			'lastScan'     => $this->getLastScanDate(),
			'scanStatus'   => $this->getScanStatus(),
		);

		return new \WP_REST_Response( $payload );
	}

	/**
	 * Date of the last completed site audit, for the Overview header.
	 *
	 * Read from the audit history table, which is written once a scan reaches
	 * its end, so the date shown is a scan that actually finished. This used to
	 * read the `seopress_bot_log` option, which the Broken Links scan writes and
	 * the site audit never touches: the header displayed the date of the last
	 * broken-links scan, or claimed no scan had ever run on a site where none
	 * had, both while the audit history sat right below it listing real scans.
	 *
	 * `scan_date` is stored with `current_time( 'mysql' )`, so it already is in
	 * the site's timezone. It is trimmed to the minute rather than run through
	 * a date function, so the header cannot end up an hour away from the same
	 * scan listed in the history below it.
	 *
	 * @return string Date as `Y-m-d H:i`, or an empty string when no audit has
	 *                completed yet.
	 */
	private function getLastScanDate() { // phpcs:ignore -- camelCase matches the surrounding methods.
		global $wpdb;

		$table = $wpdb->prefix . 'seopress_site_audit_history';

		// Direct query, uncached: this backs an admin screen that has to show a
		// freshly finished audit straight away.
		$scan_date = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT scan_date FROM {$table} ORDER BY scan_date DESC LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( empty( $scan_date ) ) {
			return '';
		}

		return sanitize_text_field( substr( (string) $scan_date, 0, 16 ) );
	}

	/**
	 * Aggregate the counters the Overview card needs in a single GROUP BY
	 * query instead of 6 COUNT() round-trips. Covered by the composite
	 * idx_ignore_priority index added in site-audit-table-migrations.
	 *
	 * Behavioural parity with the legacy countTotalIssues() helpers is
	 * deliberate: the `total` and `by_priority` totals include ignored
	 * rows because the original $ignore=0 param was `! empty()`-checked
	 * and therefore never filtered. Changing that semantics would shift
	 * every user's displayed counters (and their health score), so the
	 * consolidation keeps them identical for now.
	 *
	 * @return array{total:int,ignored:int,by_priority:array<string,int>}
	 */
	private function getOverviewIssueCounts() {
		global $wpdb;

		$table = $wpdb->prefix . 'seopress_seo_issues';

		// Joined on published posts: rows whose post is gone or unpublished
		// are invisible in every list, so they must not be counted either.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT issues.issue_priority, issues.issue_ignore, COUNT(*) AS cnt FROM {$table} AS issues INNER JOIN {$wpdb->posts} AS posts ON posts.ID = issues.post_id AND posts.post_status = 'publish' GROUP BY issues.issue_priority, issues.issue_ignore" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$total       = 0;
		$ignored     = 0;
		$by_priority = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
			'good'   => 0,
		);

		foreach ( (array) $rows as $row ) {
			$cnt        = (int) $row->cnt;
			$priority   = (string) $row->issue_priority;
			$is_ignored = 1 === (int) $row->issue_ignore;

			$total += $cnt;
			if ( $is_ignored ) {
				$ignored += $cnt;
			}
			if ( isset( $by_priority[ $priority ] ) ) {
				$by_priority[ $priority ] += $cnt;
			}
		}

		return array(
			'total'       => $total,
			'ignored'     => $ignored,
			'by_priority' => $by_priority,
		);
	}

	/**
	 * Return the top N issue types to surface as "Next steps" on the
	 * Overview screen. Ranked by severity-weighted active count
	 * (high=3, medium=2, low=1) so the picks line up with what hurts the
	 * score most. One GROUP BY query keeps this cheap regardless of how
	 * many types we define.
	 *
	 * @param int $limit Max number of entries to return.
	 *
	 * @return array<int, array{type:string,title:string,count:int,priority:string}>
	 */
	private function getTopIssues( $limit = 3 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'seopress_seo_issues';
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT issues.issue_type, issues.issue_priority, COUNT(*) AS cnt FROM {$table} AS issues INNER JOIN {$wpdb->posts} AS posts ON posts.ID = issues.post_id AND posts.post_status = 'publish' WHERE issues.issue_ignore = 0 AND issues.issue_priority IN ('high','medium','low') GROUP BY issues.issue_type, issues.issue_priority" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$weights = array(
			'high'   => 3,
			'medium' => 2,
			'low'    => 1,
		);

		// Aggregate per type: total count, weighted score, dominant priority.
		$agg = array();
		foreach ( $rows as $row ) {
			$type     = (string) $row->issue_type;
			$priority = (string) $row->issue_priority;
			$cnt      = (int) $row->cnt;
			$weight   = isset( $weights[ $priority ] ) ? $weights[ $priority ] : 0;

			if ( ! isset( $agg[ $type ] ) ) {
				$agg[ $type ] = array(
					'count' => 0,
					'score' => 0,
					'topW'  => 0,
					'topP'  => 'low',
				);
			}

			$agg[ $type ]['count'] += $cnt;
			$agg[ $type ]['score'] += $cnt * $weight;

			// Dominant priority = the highest-weighted one seen for this type.
			if ( $weight > $agg[ $type ]['topW'] ) {
				$agg[ $type ]['topW'] = $weight;
				$agg[ $type ]['topP'] = $priority;
			}
		}

		// Sort by weighted score desc; tie-break on raw count desc.
		uasort(
			$agg,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $b['count'] - $a['count'];
				}
				return $b['score'] - $a['score'];
			}
		);

		$meta = SEOIssues::getData();
		$out  = array();
		foreach ( $agg as $type => $info ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$title = isset( $meta[ $type ]['title'] ) ? wp_strip_all_tags( $meta[ $type ]['title'] ) : $type;
			$out[] = array(
				'type'     => $type,
				'title'    => $title,
				'count'    => $info['count'],
				'priority' => $info['topP'],
			);
		}

		return $out;
	}

	/**
	 * List all issue types with counts. Lighter than /overview:
	 * no crawled-URL aggregate, no scan status — just the table data.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetIssues() {
		$service = $this->getSiteAuditService();
		$types   = SEOIssues::getData();
		$rows    = array();

		foreach ( $types as $type => $meta ) {
			$active  = (int) ( $service ? $service->countTotalIssues( $type, '', 0 ) : 0 );
			$ignored = (int) ( $service ? $service->countTotalIssues( $type, '', 1 ) : 0 );

			$rows[] = array(
				'type'    => $type,
				'title'   => isset( $meta['title'] ) ? wp_strip_all_tags( $meta['title'] ) : $type,
				'desc'    => isset( $meta['desc'] ) && $meta['desc'] ? wp_strip_all_tags( $meta['desc'] ) : null,
				'active'  => $active,
				'ignored' => $ignored,
				'total'   => $active + $ignored,
			);
		}

		return new \WP_REST_Response(
			array(
				'data'  => $rows,
				'total' => count( $rows ),
			)
		);
	}

	/**
	 * List issues of a given type, paginated, one row per entry in the
	 * {prefix}seopress_seo_issues table. Each item carries enough context
	 * (issue name + priority + translated description + target keyword)
	 * for the React DataViews to render without a second round-trip.
	 *
	 * Search, sort, filters and pagination are all resolved by the query, so
	 * `total` describes the same set of rows as `data` whatever the view is.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetIssueDetails( \WP_REST_Request $request ) {
		$type     = $request->get_param( 'type' );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$ignored  = (int) $request->get_param( 'ignored' );

		$query = $this->queryIssuesForType(
			$type,
			$ignored,
			array(
				'search'     => (string) $request->get_param( 'search' ),
				'orderby'    => (string) $request->get_param( 'orderby' ),
				'order'      => (string) $request->get_param( 'order' ),
				'issue_name' => (array) $request->get_param( 'issue_name' ),
				'priority'   => (array) $request->get_param( 'priority' ),
				'per_page'   => $per_page,
				'page'       => $page,
			)
		);

		$slice = $query['rows'];
		$total = $query['total'];

		// GSC sync is only meaningful when the toggle is ON and an API key
		// exists; otherwise post meta will be empty/stale and we hide the
		// columns client-side.
		$gsc_enabled = false;
		if ( function_exists( 'seopress_get_service' ) ) {
			$toggle_service = seopress_get_service( 'ToggleOption' );
			if ( $toggle_service && '1' === $toggle_service->getToggleInspectUrl() ) {
				$options        = get_option( 'seopress_instant_indexing_option_name' );
				$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';
				$gsc_enabled    = ! empty( $google_api_key );
			}
		}

		$data = array();
		foreach ( $slice as $row ) {
			$post_id = (int) $row->post_id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$issue_name = sanitize_text_field( (string) $row->issue_name );
			$priority   = sanitize_key( (string) $row->issue_priority );

			$raw_desc      = $row->issue_desc;
			$desc_rendered = SEOIssueDesc::getIssueDescI18n( $issue_name, $raw_desc );
			// Defense-in-depth: SEOIssueDesc already escapes every value it
			// inlines, but we run the whole render through a strict allowlist
			// before shipping it to React so any future case that forgets to
			// escape stays inert when rendered via RawHTML on the client.
			$desc_html = wp_kses(
				(string) $desc_rendered,
				array(
					'ul'     => array(),
					'li'     => array(),
					'br'     => array(),
					'strong' => array(),
					'em'     => array(),
					'code'   => array(),
					'a'      => array(
						'href'   => true,
						'target' => array( '_blank' ),
						'rel'    => array( 'noreferrer noopener' ),
					),
				)
			);

			$target_keyword = get_post_meta( $post_id, '_seopress_analysis_target_kw', true );

			// GSC metrics come from the post meta populated by
			// seopress_get_insights_gsc_cron. Missing keys → 0 across the
			// board, which renders as "—" on the client.
			$gsc_clicks      = (int) round( (float) get_post_meta( $post_id, '_seopress_search_console_analysis_clicks', true ) );
			$gsc_impressions = (int) round( (float) get_post_meta( $post_id, '_seopress_search_console_analysis_impressions', true ) );
			$gsc_position    = round( (float) get_post_meta( $post_id, '_seopress_search_console_analysis_position', true ), 1 );

			$data[] = array(
				'id'             => (int) $row->id,
				'postId'         => $post_id,
				'postTitle'      => wp_strip_all_tags( (string) $post->post_title ),
				'postType'       => sanitize_key( (string) $post->post_type ),
				'permalink'      => esc_url_raw( (string) get_permalink( $post_id ) ),
				'editLink'       => esc_url_raw( (string) get_edit_post_link( $post_id, 'raw' ) ),
				'issueName'      => $issue_name,
				'issueNameLabel' => (string) SEOIssueName::getIssueNameI18n( $issue_name ),
				'description'    => $desc_html,
				'priority'       => in_array( $priority, array( 'low', 'medium', 'high', 'good' ), true ) ? $priority : '',
				'ignored'        => 1 === (int) $row->issue_ignore,
				'targetKeyword'  => $target_keyword ? sanitize_text_field( (string) $target_keyword ) : '',
				'gscClicks'      => $gsc_clicks,
				'gscImpressions' => $gsc_impressions,
				'gscPosition'    => $gsc_position,
			);
		}

		return new \WP_REST_Response(
			array(
				'data'       => $data,
				'total'      => $total,
				'totalPages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
				'gscEnabled' => $gsc_enabled,
				// Every issue_name this type holds, not just the ones on the
				// current page: the column filter is useless if its options
				// change as you paginate.
				'issueNames' => $this->issueNamesForType( $type, $ignored ),
			)
		);
	}

	/**
	 * Distinct issue_name values stored for a type, with their translated
	 * labels, sorted by label. Feeds the "Issue" column filter.
	 *
	 * @param string $type    Issue type slug.
	 * @param int    $ignored 1 to include ignored rows, 0 for active only.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private function issueNamesForType( $type, $ignored ) {
		global $wpdb;

		$table = $wpdb->prefix . 'seopress_seo_issues';

		// Same published-posts join as the list itself: an option that matches
		// nothing but rows the list cannot render is a dead end in the filter.
		$join = "INNER JOIN {$wpdb->posts} AS posts ON posts.ID = issues.post_id AND posts.post_status = 'publish'";

		if ( 1 === (int) $ignored ) {
			$sql = $wpdb->prepare(
				"SELECT DISTINCT issues.issue_name FROM {$table} AS issues {$join} WHERE issues.issue_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT DISTINCT issues.issue_name FROM {$table} AS issues {$join} WHERE issues.issue_type = %s AND issues.issue_ignore = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$names = $wpdb->get_col( $sql );

		if ( ! is_array( $names ) ) {
			return array();
		}

		$labels = SEOIssueName::getIssueNames();
		$out    = array();

		foreach ( $names as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'value' => $name,
				'label' => isset( $labels[ $name ] ) ? $labels[ $name ] : $name,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $out;
	}

	/**
	 * Turn a comma-separated list of slugs into a clean array.
	 *
	 * Used as the sanitize_callback of the column filter parameters, which
	 * carry the values of a DataViews `isAny` operator.
	 *
	 * @param mixed $value Raw parameter value.
	 *
	 * @return string[]
	 */
	public function sanitizeSlugList( $value ) {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = explode( ',', (string) $value );
		}

		$parts = array_map( 'sanitize_key', $parts );
		$parts = array_filter(
			$parts,
			function ( $part ) {
				return '' !== $part;
			}
		);

		return array_values( array_unique( $parts ) );
	}

	/**
	 * Get audit + bot option groups. Kept as a flat object so the React
	 * form can render fields without probing WordPress settings internals.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetSettings() {
		$audit = get_option( 'seopress_pro_audit_option_name', array() );
		$bot   = get_option( 'seopress_bot_option_name', array() );

		return new \WP_REST_Response(
			array(
				'audit' => is_array( $audit ) ? $audit : array(),
				'bot'   => is_array( $bot ) ? $bot : array(),
			)
		);
	}

	/**
	 * Persist audit + bot option groups. Accepts a partial payload: only
	 * the provided groups are updated, untouched groups are left alone.
	 *
	 * Each value is passed through wp_unslash() + sanitize_text_field() at
	 * the leaf level. Nested arrays are walked recursively.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processSaveSettings( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'seopress_audit_invalid_payload',
				__( 'Invalid payload.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$updated = array();

		if ( isset( $payload['audit'] ) && is_array( $payload['audit'] ) ) {
			$clean = $this->sanitizeSettingsTree( $payload['audit'] );
			update_option( 'seopress_pro_audit_option_name', $clean );
			$updated['audit'] = $clean;
		}

		if ( isset( $payload['bot'] ) && is_array( $payload['bot'] ) ) {
			$clean = $this->sanitizeSettingsTree( $payload['bot'] );
			update_option( 'seopress_bot_option_name', $clean );
			$updated['bot'] = $clean;
		}

		return new \WP_REST_Response( $updated );
	}

	/**
	 * Return the metadata needed by the React Settings form: public post types
	 * available for audit scoping, with their labels. Excludes builder/utility
	 * post types the legacy UI also excludes.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetSettingsMeta() {
		$excluded = array(
			'elementor_library',
			'fl-builder-template',
			'editor-template',
			'editor-form-entry',
			'breakdance_form_res',
			'customer_discount',
			'cuar_private_file',
			'cuar_private_page',
			'vc_grid_item',
			'zion_template',
			'tbuilder_layout',
			'tbuilder_layout_part',
			'tb_cf',
			'ct_template',
			'oxy_user_library',
			'bricks_template',
		);

		$post_types = get_post_types(
			array(
				'show_ui' => true,
				'public'  => true,
			),
			'objects'
		);

		$rows = array();
		foreach ( $post_types as $slug => $pt ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			$rows[] = array(
				'slug'  => sanitize_key( $slug ),
				'label' => isset( $pt->labels->name ) ? wp_strip_all_tags( $pt->labels->name ) : $slug,
			);
		}

		return new \WP_REST_Response(
			array(
				'postTypes' => $rows,
			)
		);
	}

	/**
	 * Re-run the audit against a single post, synchronously. Mirrors the
	 * per-post branch of seopress_site_audit_run_task_fn() — same skips
	 * (noindex, redirection), same services (RequestPreview,
	 * DomFilterContent, DomAnalysis, ContentAnalysisDatabase,
	 * GetContentAnalysis) — so seopress_seo_issues ends up in the same
	 * state it would after a full scan, just for this post.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processRescanPost( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'seopress_audit_post_not_found',
				__( 'Post not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		$option_bot      = function_exists( 'seopress_pro_get_service' )
			? seopress_pro_get_service( 'OptionBot' )
			: null;
		$noindex_setting = is_object( $option_bot )
			? $option_bot->getBotScanSettingsAuditNoindex()
			: '';

		if ( 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true ) && '1' !== $noindex_setting ) {
			return new \WP_Error(
				'seopress_audit_post_noindex',
				__( 'This post is marked as noindex and excluded from audit.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( 'yes' === get_post_meta( $post_id, '_seopress_redirections_enabled', true ) ) {
			return new \WP_Error(
				'seopress_audit_post_redirected',
				__( 'This post has an active redirection and is excluded from audit.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$request_preview = function_exists( 'seopress_get_service' )
			? seopress_get_service( 'RequestPreview' )
			: null;

		if ( ! is_object( $request_preview ) ) {
			return new \WP_Error(
				'seopress_audit_engine_unavailable',
				__( 'The audit engine is unavailable.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		$dom_result = $request_preview->getDomById( $post_id, null );
		if ( ! is_array( $dom_result ) || empty( $dom_result['success'] ) ) {
			return new \WP_Error(
				'seopress_audit_fetch_failed',
				__( 'Could not fetch the page content.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		$dom_filter    = seopress_get_service( 'DomFilterContent' );
		$dom_analysis  = seopress_get_service( 'DomAnalysis' );
		$ca_database   = seopress_get_service( 'ContentAnalysisDatabase' );
		$get_analysis  = seopress_get_service( 'GetContentAnalysis' );

		$data          = $dom_filter->getData( $dom_result['body'], $post_id );
		$data          = $dom_analysis->getDataAnalyze( $data, array( 'id' => $post_id ) );
		$data['score'] = $dom_analysis->getScore( $post );
		$keywords      = $dom_analysis->getKeywords( array( 'id' => $post_id ) );

		$ca_database->saveData( $post_id, $data, $keywords );
		$get_analysis->getAnalyzes( $post );

		return new \WP_REST_Response(
			array(
				'postId' => $post_id,
			)
		);
	}

	/**
	 * Trigger AI alt-text generation for every image referenced by an
	 * `img_alt_missing` issue row. Walks the image URL list stored in
	 * `issue_desc`, resolves each URL to attachment ids via the free
	 * plugin's SearchAttachment service, then delegates to
	 * Completions::generateImgAltText() for the actual OpenAI call.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processGenerateAlt( \WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$table = $wpdb->prefix . 'seopress_seo_issues';

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id, issue_type, issue_name, issue_desc FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		if ( ! $row ) {
			return new \WP_Error(
				'seopress_audit_issue_not_found',
				__( 'Issue not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( 'img_alt' !== $row->issue_type || 'img_alt_missing' !== $row->issue_name ) {
			return new \WP_Error(
				'seopress_audit_alt_only',
				__( 'This action is only available for missing alt text issues.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$urls = maybe_unserialize( $row->issue_desc );
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			return new \WP_Error(
				'seopress_audit_no_images',
				__( 'No images to process for this issue.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$search_service = function_exists( 'seopress_get_service' )
			? seopress_get_service( 'SearchAttachment' )
			: null;
		$completions    = function_exists( 'seopress_pro_get_service' )
			? seopress_pro_get_service( 'Completions' )
			: null;

		if ( ! is_object( $search_service ) || ! is_object( $completions ) ) {
			return new \WP_Error(
				'seopress_audit_ai_unavailable',
				__( 'The AI service is unavailable. Check your SEOPress AI configuration.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		$generated = 0;
		$errors    = array();

		foreach ( $urls as $url ) {
			$url = is_string( $url ) ? esc_url_raw( $url ) : '';
			if ( empty( $url ) ) {
				continue;
			}

			$attachment_ids = $search_service->searchByUrl( $url );
			if ( empty( $attachment_ids ) ) {
				$errors[] = sprintf(
					/* translators: %s: image URL */
					__( 'No attachment found for %s.', 'wp-seopress-pro' ),
					$url
				);
				continue;
			}

			foreach ( (array) $attachment_ids as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( ! $attachment_id ) {
					continue;
				}

				$result = $completions->generateImgAltText( $attachment_id, 'alt_text' );

				if ( is_wp_error( $result ) ) {
					$errors[] = sanitize_text_field( $result->get_error_message() );
					continue;
				}

				if ( is_array( $result ) && ! empty( $result['alt_text'] ) ) {
					++$generated;
				} elseif ( is_array( $result ) && ! empty( $result['message'] ) ) {
					$errors[] = sanitize_text_field( $result['message'] );
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'generated' => $generated,
				'errors'    => $errors,
			)
		);
	}

	/**
	 * Flip the ignore flag on many seopress_seo_issues rows at once.
	 *
	 * Body:
	 *   ids     int[]   Row primary keys to update.
	 *   ignored bool    Target state, applied to every row in the batch.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processBulkIgnoreIssues( \WP_REST_Request $request ) {
		global $wpdb;

		$data    = $request->get_json_params();
		$raw_ids = isset( $data['ids'] ) && is_array( $data['ids'] ) ? $data['ids'] : array();
		$ids     = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'seopress_audit_no_issues',
				__( 'No issues specified.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$raw     = isset( $data['ignored'] ) ? $data['ignored'] : false;
		$ignored = true === $raw || 1 === $raw || '1' === $raw || 'true' === $raw ? 1 : 0;

		$table        = $wpdb->prefix . 'seopress_seo_issues';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table} SET issue_ignore = %d WHERE id IN ({$placeholders})",
			array_merge( array( $ignored ), $ids )
		);

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $updated ) {
			return new \WP_Error(
				'seopress_audit_update_failed',
				__( 'Failed to update issues.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'updated' => (int) $updated,
				'ignored' => 1 === $ignored,
				'ids'     => $ids,
			)
		);
	}

	/**
	 * Toggle the ignore flag of a single seopress_seo_issues row.
	 *
	 * Targets the row by its primary key so the same (post_id, issue_name)
	 * pair can host several distinct entries without collateral damage
	 * (legacy updateSEOIssue() matches on post_id+issue_name only).
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processIgnoreIssue( \WP_REST_Request $request ) {
		global $wpdb;

		$id      = (int) $request->get_param( 'id' );
		$raw     = $request->get_param( 'ignored' );
		$ignored = true === $raw || 1 === $raw || '1' === $raw || 'true' === $raw ? 1 : 0;

		$table = $wpdb->prefix . 'seopress_seo_issues';

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array( 'issue_ignore' => $ignored ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'seopress_audit_issue_update_failed',
				__( 'Failed to update issue status.', 'wp-seopress-pro' ),
				array( 'status' => 500 )
			);
		}

		if ( 0 === $updated ) {
			return new \WP_Error(
				'seopress_audit_issue_not_found',
				__( 'Issue not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response(
			array(
				'id'      => $id,
				'ignored' => 1 === $ignored,
			)
		);
	}

	/**
	 * Return the last N days of scan history snapshots from
	 * {prefix}seopress_site_audit_history, oldest first (chronological
	 * order) so the React chart can feed it straight to Recharts.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetHistory( \WP_REST_Request $request ) {
		global $wpdb;

		$days  = (int) $request->get_param( 'days' );
		$table = $wpdb->prefix . 'seopress_site_audit_history';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, scan_date, duration_seconds, total_crawled, total_issues, total_ignored, priority_high, priority_medium, priority_low, priority_good, health_score FROM {$table} WHERE scan_date >= ( NOW() - INTERVAL %d DAY ) ORDER BY scan_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			)
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'id'              => (int) $row->id,
				'scanDate'        => sanitize_text_field( (string) $row->scan_date ),
				'durationSeconds' => (int) $row->duration_seconds,
				'totalCrawled'    => (int) $row->total_crawled,
				'totalIssues'     => (int) $row->total_issues,
				'totalIgnored'    => (int) $row->total_ignored,
				'priorityHigh'    => (int) $row->priority_high,
				'priorityMedium'  => (int) $row->priority_medium,
				'priorityLow'     => (int) $row->priority_low,
				'priorityGood'    => (int) $row->priority_good,
				'healthScore'     => (int) $row->health_score,
			);
		}

		return new \WP_REST_Response(
			array(
				'data'  => $data,
				'total' => count( $data ),
				'days'  => $days,
			)
		);
	}

	/**
	 * Return the N URLs with the most (severity-weighted) active issues,
	 * enriched with Google Search Console metrics when the GSC sync cron
	 * has populated the post meta (`_seopress_search_console_analysis_*`).
	 *
	 * The goal is to let beginners prioritise *by business impact* — a
	 * page with 5 issues but 10k impressions matters far more than a
	 * page with 10 issues and zero traffic. When GSC data is absent we
	 * fall back to raw weighted issue counts, which is still useful.
	 *
	 * A single LEFT JOIN query keeps this O(1) round-trip regardless of
	 * how many posts the site has.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetPriorityUrls( \WP_REST_Request $request ) {
		global $wpdb;

		$limit        = (int) $request->get_param( 'limit' );
		$issues_table = $wpdb->prefix . 'seopress_seo_issues';
		$meta_table   = $wpdb->prefix . 'postmeta';
		$posts_table  = $wpdb->prefix . 'posts';

		// GSC sync is only meaningful when the toggle is ON and an API key
		// exists; otherwise post meta will be empty/stale and we hide the
		// columns on the UI.
		$gsc_enabled = false;
		if ( function_exists( 'seopress_get_service' ) ) {
			$toggle_service = seopress_get_service( 'ToggleOption' );
			if ( $toggle_service && '1' === $toggle_service->getToggleInspectUrl() ) {
				$options        = get_option( 'seopress_instant_indexing_option_name' );
				$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';
				$gsc_enabled    = ! empty( $google_api_key );
			}
		}

		// Severity weighting mirrors getTopIssues() and the health-score
		// formula — keeping one source of truth makes the Overview
		// internally consistent (high × 3, medium × 2, low × 1). Table
		// names come from $wpdb->prefix so interpolation is safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT i.post_id,
				COUNT(*) AS issue_count,
				SUM(CASE
					WHEN i.issue_priority = 'high'   THEN 3
					WHEN i.issue_priority = 'medium' THEN 2
					WHEN i.issue_priority = 'low'    THEN 1
					ELSE 0
				END) AS weighted_score,
				COALESCE(CAST(clicks.meta_value AS DECIMAL(20,4)), 0) AS clicks,
				COALESCE(CAST(impressions.meta_value AS DECIMAL(20,4)), 0) AS impressions,
				COALESCE(CAST(position.meta_value AS DECIMAL(20,4)), 0) AS position
			FROM {$issues_table} i
			INNER JOIN {$posts_table} p ON p.ID = i.post_id AND p.post_status = 'publish'
			LEFT JOIN {$meta_table} clicks      ON clicks.post_id      = i.post_id AND clicks.meta_key      = '_seopress_search_console_analysis_clicks'
			LEFT JOIN {$meta_table} impressions ON impressions.post_id = i.post_id AND impressions.meta_key = '_seopress_search_console_analysis_impressions'
			LEFT JOIN {$meta_table} position    ON position.post_id    = i.post_id AND position.meta_key    = '_seopress_search_console_analysis_position'
			WHERE i.issue_ignore = 0 AND i.issue_priority IN ('high','medium','low')
			GROUP BY i.post_id
			ORDER BY weighted_score DESC, clicks DESC, impressions DESC
			LIMIT %d",
			$limit
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$data[] = array(
				'postId'        => $post_id,
				'title'         => wp_strip_all_tags( get_the_title( $post ) ),
				'permalink'     => get_permalink( $post_id ),
				'editLink'      => get_edit_post_link( $post_id, 'raw' ),
				'issueCount'    => (int) $row->issue_count,
				'weightedScore' => (int) $row->weighted_score,
				'clicks'        => (int) round( (float) $row->clicks ),
				'impressions'   => (int) round( (float) $row->impressions ),
				'position'      => round( (float) $row->position, 1 ),
			);
		}

		return new \WP_REST_Response(
			array(
				'data'       => $data,
				'gscEnabled' => $gsc_enabled,
			)
		);
	}

	/**
	 * Start or cancel a Site Audit scan.
	 *
	 * Start  — flips the running flag, primes the counters, schedules the
	 *          first batch via WP-Cron (time() = due now), and fires
	 *          spawn_cron() so wp-cron.php is triggered asynchronously.
	 *          Returns immediately so the UI can flip to "running" state
	 *          without waiting for the first batch to complete.
	 * Cancel — flips the running flag off and clears any scheduled
	 *          seopress_site_audit_run_task_cron events.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processScan( \WP_REST_Request $request ) {
		$action = $request->get_param( 'action' );

		if ( 'cancel' === $action ) {
			update_option( 'seopress_pro_site_audit_running', 0, false );

			$crons = _get_cron_array();
			if ( ! empty( $crons ) ) {
				foreach ( $crons as $timestamp => $cron ) {
					if ( ! isset( $cron['seopress_site_audit_run_task_cron'] ) ) {
						continue;
					}
					foreach ( $cron['seopress_site_audit_run_task_cron'] as $details ) {
						wp_unschedule_event( $timestamp, 'seopress_site_audit_run_task_cron', $details['args'] );
					}
				}
			}
			wp_clear_scheduled_hook( 'seopress_site_audit_run_task_cron' );

			// Stop the self-heal watchdog too, otherwise it would keep re-driving
			// the scan after a cancel.
			if ( function_exists( 'seopress_site_audit_unschedule_watchdog' ) ) {
				seopress_site_audit_unschedule_watchdog();
			} else {
				wp_clear_scheduled_hook( 'seopress_site_audit_watchdog_cron' );
			}

			delete_transient( 'seopress_site_audit_progress' );
			delete_transient( 'seopress_site_audit_last_run' );
			delete_option( 'seopress_pro_site_audit_current_step' );
			delete_option( 'seopress_pro_site_audit_total_steps' );

			return new \WP_REST_Response( array( 'status' => 'canceled' ) );
		}

		// Prime the scanner state exactly like run_task_fn() does when $new === true,
		// so we can hand off control asynchronously with $new === false.
		update_option( 'seopress_pro_site_audit_running', 1, false );
		delete_option( 'seopress_pro_site_audit_offset' );
		delete_option( 'seopress_pro_site_audit_count_posts' );
		delete_option( 'seopress_pro_site_audit_post_count' );
		delete_option( 'seopress_pro_site_audit_log' );
		delete_option( 'seopress_pro_site_audit_last_error' );
		delete_option( 'seopress_pro_site_audit_heartbeat' );

		// The stuck-offset bookkeeping belongs to the run that wrote it. Left
		// behind, a scan that died twice at the same offset makes the *next*
		// scan step over the page sitting there on its very first batch, even
		// though that page has not failed once in this run.
		delete_option( 'seopress_pro_site_audit_stuck_offset' );
		delete_option( 'seopress_pro_site_audit_stuck_count' );
		delete_option( 'seopress_pro_site_audit_current_post' );

		update_option( 'seopress_pro_site_audit_last_scan', time(), false );

		// Queue the first batch. The cron hook re-enters run_task_fn() and
		// schedules every subsequent batch via wp_schedule_single_event().
		wp_schedule_single_event( time(), 'seopress_site_audit_run_task_cron', array( 0 ) );

		// Start the self-heal watchdog now, at kickoff, so the scan can recover
		// even if this very first batch never runs (e.g. a broken cron setup).
		if ( function_exists( 'seopress_site_audit_schedule_watchdog' ) ) {
			seopress_site_audit_schedule_watchdog();
		}

		// Fire wp-cron.php in the background so the batch starts within seconds
		// instead of waiting for the next random admin request to trip WP-Cron.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return new \WP_REST_Response( array( 'status' => 'started' ) );
	}

	/**
	 * Read the Site Audit scan status (progress, log, duration, running flag).
	 *
	 * @return \WP_REST_Response
	 */
	public function processScanStatus() {
		return new \WP_REST_Response( $this->getScanStatus() );
	}

	/**
	 * Build the scan status payload from the plugin options set by the crawler.
	 *
	 * @return array<string, int|string|bool>
	 */
	private function getScanStatus() {
		$running   = (int) get_option( 'seopress_pro_site_audit_running', 0 );
		$processed = (int) get_option( 'seopress_pro_site_audit_post_count', 0 );
		$total     = (int) get_option( 'seopress_pro_site_audit_count_posts', 0 );
		$log       = sanitize_text_field( (string) get_option( 'seopress_pro_site_audit_log', '' ) );
		$duration  = (int) get_option( 'seopress_pro_site_audit_scan_duration', 0 );

		$percent = 0;
		if ( $total > 0 ) {
			$percent = min( 100, (int) floor( ( $processed / $total ) * 100 ) );
		}

		return array(
			'running'   => 1 === $running,
			'processed' => $processed,
			'total'     => $total,
			'percent'   => $percent,
			'log'       => $log,
			'duration'  => $duration,
		);
	}

	/**
	 * Read DataViews layout preferences for the current user.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetViewPreferences( \WP_REST_Request $request ) {
		$prefs = get_user_meta(
			get_current_user_id(),
			$this->getViewPrefsMetaKey( $request ),
			true
		);
		return new \WP_REST_Response( $prefs ? $prefs : new \stdClass() );
	}

	/**
	 * Save DataViews layout preferences for the current user.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processSaveViewPreferences( \WP_REST_Request $request ) {
		$data    = $request->get_json_params();
		$scope   = sanitize_key( (string) $request->get_param( 'scope' ) );
		$allowed = $this->getAllowedViewPrefKeys( $scope );

		// Merge into existing prefs so partial updates don't wipe siblings
		// (e.g. toggling the Trends panel must not reset History).
		$current = get_user_meta(
			get_current_user_id(),
			$this->getViewPrefsMetaKey( $request ),
			true
		);
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			// Overview panel state is strictly boolean; coerce now so we
			// don't persist "0" / "1" strings that React can't compare.
			if ( 'overview' === $scope ) {
				$current[ $key ] = (bool) $data[ $key ];
			} else {
				$current[ $key ] = $data[ $key ];
			}
		}

		update_user_meta(
			get_current_user_id(),
			$this->getViewPrefsMetaKey( $request ),
			$current
		);
		return new \WP_REST_Response( $current );
	}

	/**
	 * Allowlist of pref keys per scope — keeps user-meta tight and
	 * prevents arbitrary payload keys from leaking into storage.
	 *
	 * @param string $scope The `?scope=` query param (issues | details | overview).
	 *
	 * @return string[]
	 */
	private function getAllowedViewPrefKeys( $scope ) {
		if ( 'overview' === $scope ) {
			return array( 'priorityUrlsOpen', 'trendsOpen', 'historyOpen' );
		}
		// Default: DataViews layout + our view-specific extras.
		return array(
			'type',
			'perPage',
			'fields',
			'filters',
			'sort',
			'density',
			'layout',
			'titleField',
			'mediaField',
			'descriptionField',
			'showMedia',
			'groupByField',
			'includeIgnored',
		);
	}

	/**
	 * Resolve the user-meta key where view preferences are stored, based on
	 * the ?scope= query param. Different DataViews screens (issue types,
	 * issue details…) keep their own prefs so fields from one screen do not
	 * wipe out the other when DataViews saves.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return string
	 */
	private function getViewPrefsMetaKey( \WP_REST_Request $request ) {
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		if ( 'details' === $scope ) {
			return 'seopress_site_audit_details_view_prefs';
		}
		if ( 'overview' === $scope ) {
			return 'seopress_site_audit_overview_view_prefs';
		}
		return 'seopress_site_audit_view_prefs';
	}

	/**
	 * Homepage / site-wide technical checks. Runs a bounded set of checks
	 * against a single homepage fetch + a few well-known endpoints, cached
	 * for 15 minutes. `?refresh=1` forces a fresh run (the "Analyze
	 * homepage" button).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processHomepage( $request ) {
		$auditor = new HomepageAudit();

		// Overview summary: never trigger the outbound fetch, just reflect
		// the last analysis the user ran (or report that none exists yet).
		if ( 1 === (int) $request->get_param( 'cached' ) ) {
			$cached = $auditor->getCachedResults();

			if ( null === $cached ) {
				return new \WP_REST_Response( array( 'available' => false ) );
			}

			return new \WP_REST_Response( array_merge( array( 'available' => true ), $cached ) );
		}

		$force = 1 === (int) $request->get_param( 'refresh' );

		return new \WP_REST_Response( array_merge( array( 'available' => true ), $auditor->getResults( $force ) ) );
	}

	/**
	 * Resolve the legacy SiteAudit service (Services\Audit\SiteAudit) that owns
	 * the queries against the seopress_seo_issues table.
	 *
	 * @return object|null
	 */
	private function getSiteAuditService() {
		if ( ! function_exists( 'seopress_pro_get_service' ) ) {
			return null;
		}

		$service = seopress_pro_get_service( 'SiteAudit' );

		return is_object( $service ) ? $service : null;
	}

	/**
	 * Fetch one page of issue rows for a type, with the search, filters and
	 * ordering of the current view already applied.
	 *
	 * Everything is resolved by the database, including the count: loading
	 * the whole type and slicing in PHP made `total` describe a different
	 * set of rows than `data` as soon as a search or a filter was active,
	 * and it read the entire table into memory on every page view.
	 *
	 * @param string $type    Issue type slug.
	 * @param int    $ignored 1 = include ignored rows, anything else = active only.
	 * @param array  $args    Search / sort / filter / pagination arguments.
	 *
	 * @return array{rows: array<int, object>, total: int}
	 */
	private function queryIssuesForType( $type, $ignored, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'orderby'    => 'priority',
				'order'      => 'asc',
				'issue_name' => array(),
				'priority'   => array(),
				'per_page'   => 25,
				'page'       => 1,
			)
		);

		$table = $wpdb->prefix . 'seopress_seo_issues';

		// Joined on the primary key, so it can never duplicate a row. It reads
		// post_title for the search and the sort, and restricts the list to
		// published posts so pagination happens on exactly the rows that will
		// render: the renderer used to drop rows whose post had been deleted
		// AFTER slicing the page, so the header said "33", page 1 showed 3,
		// and page 2 was empty.
		$join = "INNER JOIN {$wpdb->posts} AS posts ON posts.ID = issues.post_id AND posts.post_status = 'publish'";

		$where = array( $wpdb->prepare( 'issues.issue_type = %s', $type ) );

		if ( 1 !== (int) $ignored ) {
			$where[] = 'issues.issue_ignore = 0';
		}

		$name_filter = $this->prepareInClause( 'issues.issue_name', $args['issue_name'] );
		if ( $name_filter ) {
			$where[] = $name_filter;
		}

		$priority_filter = $this->prepareInClause( 'issues.issue_priority', $args['priority'] );
		if ( $priority_filter ) {
			$where[] = $priority_filter;
		}

		$search = $this->prepareSearchClause( $args['search'] );
		if ( $search ) {
			$where[] = $search;
		}

		$where_sql = implode( ' AND ', $where );
		$order_sql = $this->orderByExpression( $args['orderby'], $args['order'] );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( max( 1, (int) $args['page'] ) - 1 ) * $per_page );

		// The interpolated fragments are either literals built here ($table,
		// $join), an allowlisted ORDER BY expression, or WHERE clauses that
		// went through $wpdb->prepare() already. Only the pagination is left
		// for the outer prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = $wpdb->prepare(
			"SELECT issues.id, issues.post_id, issues.issue_name, issues.issue_desc, issues.issue_priority, issues.issue_ignore
			FROM {$table} AS issues
			{$join}
			WHERE {$where_sql}
			ORDER BY {$order_sql}
			LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);

		$rows = $wpdb->get_results( $sql );

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} AS issues {$join} WHERE {$where_sql}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Build a prepared `column IN (…)` clause, or null when there is nothing
	 * to filter on.
	 *
	 * @param string   $column Fully qualified column name (never user input).
	 * @param string[] $values Already sanitized slugs.
	 *
	 * @return string|null
	 */
	private function prepareInClause( $column, $values ) {
		global $wpdb;

		$values = array_filter( array_map( 'strval', (array) $values ) );

		if ( empty( $values ) ) {
			return null;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

		return $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"{$column} IN ({$placeholders})",
			$values
		);
	}

	/**
	 * Build the WHERE fragment for the global search box.
	 *
	 * Matches what the columns flagged `enableGlobalSearch` display: the post
	 * title, the issue label, and the target keyword. The label lives in a
	 * translation table rather than in the database, so it is resolved to the
	 * matching issue_name slugs before the query runs — searching "noindex is
	 * ON" has to find rows whose stored name is `meta_robots_noindex`.
	 *
	 * @param string $search Raw search term.
	 *
	 * @return string|null
	 */
	private function prepareSearchClause( $search ) {
		global $wpdb;

		$search = trim( (string) $search );

		if ( '' === $search ) {
			return null;
		}

		$like  = '%' . $wpdb->esc_like( $search ) . '%';
		$parts = array(
			$wpdb->prepare( 'posts.post_title LIKE %s', $like ),
			$wpdb->prepare( 'issues.issue_name LIKE %s', $like ),
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS kw WHERE kw.post_id = issues.post_id AND kw.meta_key = '_seopress_analysis_target_kw' AND kw.meta_value LIKE %s )",
				$like
			),
		);

		$matching_names = array();
		foreach ( SEOIssueName::getIssueNames() as $slug => $label ) {
			if ( false !== stripos( $label, $search ) ) {
				$matching_names[] = $slug;
			}
		}

		$by_label = $this->prepareInClause( 'issues.issue_name', $matching_names );
		if ( $by_label ) {
			$parts[] = $by_label;
		}

		return '( ' . implode( ' OR ', $parts ) . ' )';
	}

	/**
	 * Build the ORDER BY fragment for a sortable field.
	 *
	 * Both the expression and the direction come from an allowlist, so no
	 * part of this string originates from the request. post_id then id close
	 * every ordering, so equal values keep a stable page-to-page order —
	 * post_id first to preserve the secondary ordering the PHP sort used.
	 *
	 * @param string $orderby Sortable field id.
	 * @param string $order   'asc' or 'desc'.
	 *
	 * @return string
	 */
	private function orderByExpression( $orderby, $order ) {
		global $wpdb;

		$direction = 'desc' === strtolower( (string) $order ) ? 'DESC' : 'ASC';
		$field     = isset( self::SORTABLE_FIELDS[ $orderby ] ) ? self::SORTABLE_FIELDS[ $orderby ] : self::SORTABLE_FIELDS['priority'];

		switch ( $field['source'] ) {
			case 'column':
				$expression = self::SORTABLE_COLUMNS[ $orderby ];
				break;

			case 'meta':
				$value = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"( SELECT meta.meta_value FROM {$wpdb->postmeta} AS meta WHERE meta.post_id = issues.post_id AND meta.meta_key = %s LIMIT 1 )",
					$field['key']
				);
				// Empty and missing metas must sort as 0, not as the string
				// '', otherwise a site with partial GSC data orders randomly.
				$expression = ! empty( $field['numeric'] )
					? "CAST( COALESCE( {$value}, 0 ) AS DECIMAL(20,4) )"
					: "COALESCE( {$value}, '' )";
				break;

			case 'priority':
			default:
				// Same weights the client-side sort used: high first, then
				// medium, low, good, and anything unknown last.
				$expression = "CASE issues.issue_priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 WHEN 'good' THEN 3 ELSE 9 END";
				break;
		}

		return "{$expression} {$direction}, issues.post_id ASC, issues.id ASC";
	}

	/**
	 * Recursively sanitize a settings tree.
	 *  - strings → wp_unslash + sanitize_text_field
	 *  - numerics → kept as-is
	 *  - bools → kept as-is
	 *  - arrays → walked
	 *  - anything else → dropped
	 *
	 * @param array $tree The settings tree to sanitize.
	 *
	 * @return array
	 */
	private function sanitizeSettingsTree( array $tree ) {
		$clean = array();

		foreach ( $tree as $key => $value ) {
			$k = sanitize_text_field( (string) $key );

			if ( is_array( $value ) ) {
				$clean[ $k ] = $this->sanitizeSettingsTree( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $k ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$clean[ $k ] = sanitize_text_field( wp_unslash( $value ) );
				continue;
			}

			if ( null === $value ) {
				$clean[ $k ] = null;
			}
		}

		return $clean;
	}
}
