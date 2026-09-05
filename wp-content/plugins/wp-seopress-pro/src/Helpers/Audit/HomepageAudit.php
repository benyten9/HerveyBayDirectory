<?php
/**
 * Homepage technical audit collector.
 *
 * Runs a small, bounded set of site-wide technical checks that make sense
 * once for the whole site (not per post): the homepage <head>, the HTTP
 * response headers, and a handful of well-known endpoints. Unlike the
 * content audit (which scales with the number of posts), this produces a
 * fixed checklist the webmaster reviews once.
 *
 * All network access goes through wp_safe_remote_get(); the result is
 * cached in a transient so revisiting the tab is instant, and the "Analyze
 * homepage" button forces a fresh run.
 *
 * @package    SEOPress PRO
 * @subpackage Helpers\Audit
 */

namespace SEOPressPro\Helpers\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the homepage / site-wide technical checks.
 *
 * @since 10.1.0
 */
class HomepageAudit {

	/**
	 * Transient key holding the last computed result.
	 */
	const TRANSIENT = 'seopress_pro_homepage_audit';

	/**
	 * How long the computed result stays cached.
	 */
	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Timeout (seconds) for every outbound request.
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * Return the checks, using the cached result unless a refresh is forced.
	 *
	 * @param bool $force_refresh When true, bypass the transient and re-run.
	 *
	 * @return array
	 */
	public function getResults( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = $this->run();
		set_transient( self::TRANSIENT, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the cached result without ever recomputing. Used by the
	 * Overview summary so opening that tab never triggers the outbound
	 * homepage fetch — it only reflects the last analysis the user ran.
	 *
	 * @return array|null
	 */
	public function getCachedResults() {
		$cached = get_transient( self::TRANSIENT );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Run every check against a fresh homepage fetch.
	 *
	 * @return array
	 */
	private function run() {
		$home     = home_url( '/' );
		$response = $this->request( $home );

		if ( is_wp_error( $response ) ) {
			return array(
				'checkedUrl'  => $home,
				'generatedAt' => current_time( 'mysql' ),
				'reachable'   => false,
				'error'       => $response->get_error_message(),
				'summary'     => array(
					'good'    => 0,
					'warning' => 0,
					'bad'     => 0,
				),
				'checks'      => array(),
			);
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$is_https = ( 'https' === wp_parse_url( $home, PHP_URL_SCHEME ) );

		$dom = $this->loadDom( $body );

		$checks = array();

		// --- Foundations (homepage <head>) ---------------------------------
		$checks[] = $this->checkHtmlLang( $dom );
		$checks[] = $this->checkCharset( $dom );
		$checks[] = $this->checkViewport( $dom );
		$checks[] = $this->checkTitle( $dom );
		$checks[] = $this->checkFavicon( $dom );

		// --- Indexability --------------------------------------------------
		$checks[] = $this->checkHttps( $is_https );
		$checks[] = $this->checkCanonical( $dom );
		$checks[] = $this->checkMetaRobots( $dom );
		$checks[] = $this->checkRobotsTxt();
		$checks[] = $this->checkSitemap();
		$checks[] = $this->checkIndexNow();

		// --- Social --------------------------------------------------------
		$checks[] = $this->checkOpenGraph( $dom );
		$checks[] = $this->checkTwitterCard( $dom );

		// --- Security (response headers) -----------------------------------
		$checks[] = $this->checkHeaderPresent( $response, 'strict-transport-security', 'security_hsts', __( 'HSTS header', 'wp-seopress-pro' ), __( 'Strict-Transport-Security tells browsers to always use HTTPS.', 'wp-seopress-pro' ) );
		$checks[] = $this->checkHeaderPresent( $response, 'x-content-type-options', 'security_nosniff', __( 'X-Content-Type-Options', 'wp-seopress-pro' ), __( 'Prevents browsers from MIME-sniffing a response away from the declared content type.', 'wp-seopress-pro' ) );
		$checks[] = $this->checkHeaderPresent( $response, 'referrer-policy', 'security_referrer', __( 'Referrer-Policy header', 'wp-seopress-pro' ), __( 'Controls how much referrer information is sent with requests.', 'wp-seopress-pro' ) );
		$checks[] = $this->checkClickjacking( $response );

		// --- Well-known / resilience ---------------------------------------
		$checks[] = $this->checkSecurityTxt();
		$checks[] = $this->check404();
		$checks[] = $this->checkLlmsTxt();

		$checks = array_values( array_filter( $checks ) );

		return array(
			'checkedUrl'  => $home,
			'generatedAt' => current_time( 'mysql' ),
			'reachable'   => true,
			'status'      => $status,
			'summary'     => $this->summarize( $checks ),
			'checks'      => $checks,
		);
	}

	/**
	 * Shared GET wrapper with a sane UA + timeout.
	 *
	 * @param string $url    URL to fetch.
	 * @param string $method HTTP method (GET|HEAD).
	 *
	 * @return array|\WP_Error
	 */
	private function request( $url, $method = 'GET' ) {
		return wp_safe_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 3,
				'sslverify'   => true,
				'user-agent'  => 'SEOPress Site Audit',
			)
		);
	}

	/**
	 * Parse an HTML string into a DOMDocument, tolerating malformed markup.
	 *
	 * @param string $html Raw HTML.
	 *
	 * @return \DOMDocument|null
	 */
	private function loadDom( $html ) {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// Force UTF-8 so accented titles/descriptions parse correctly.
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $dom;
	}

	/**
	 * Aggregate good/warning/bad counts (na is excluded from the summary).
	 *
	 * @param array $checks Check rows.
	 *
	 * @return array{good:int,warning:int,bad:int}
	 */
	private function summarize( array $checks ) {
		$summary = array(
			'good'    => 0,
			'warning' => 0,
			'bad'     => 0,
		);

		foreach ( $checks as $check ) {
			$status = isset( $check['status'] ) ? $check['status'] : '';
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}

		return $summary;
	}

	/**
	 * Build a single check row.
	 *
	 * @param string $id          Stable slug.
	 * @param string $group       Group slug (foundations|indexability|social|security|resilience).
	 * @param string $label       Human label.
	 * @param string $status      good|warning|bad|na.
	 * @param string $description Advice / explanation.
	 * @param array  $extra       Optional keys: value, fixUrl.
	 *
	 * @return array
	 */
	private function row( $id, $group, $label, $status, $description, $extra = array() ) {
		return array_merge(
			array(
				'id'          => $id,
				'group'       => $group,
				'label'       => $label,
				'status'      => $status,
				'description' => $description,
			),
			$extra
		);
	}

	/**
	 * Return the first matching element via XPath, or null.
	 *
	 * @param \DOMDocument|null $dom   Parsed document.
	 * @param string            $query XPath query.
	 *
	 * @return \DOMElement|null
	 */
	private function xpathFirst( $dom, $query ) {
		if ( null === $dom ) {
			return null;
		}

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( $query );

		return ( $nodes && $nodes->length > 0 ) ? $nodes->item( 0 ) : null;
	}

	/* ---------------------------------------------------------------------
	 * Foundations
	 * ------------------------------------------------------------------- */

	private function checkHtmlLang( $dom ) {
		$html = $this->xpathFirst( $dom, '//html[@lang]' );
		$lang = $html ? trim( $html->getAttribute( 'lang' ) ) : '';

		return $this->row(
			'html_lang',
			'foundations',
			__( 'Language attribute', 'wp-seopress-pro' ),
			'' !== $lang ? 'good' : 'warning',
			__( 'The <html> tag should declare the page language via a lang attribute for accessibility and search engines.', 'wp-seopress-pro' ),
			array( 'value' => $lang )
		);
	}

	private function checkCharset( $dom ) {
		$meta = $this->xpathFirst( $dom, '//meta[@charset] | //meta[translate(@http-equiv,"CONTENT-TYPE","content-type")="content-type"]' );

		return $this->row(
			'charset',
			'foundations',
			__( 'Character encoding', 'wp-seopress-pro' ),
			$meta ? 'good' : 'warning',
			__( 'A <meta charset> declaration avoids text rendering issues. UTF-8 is recommended.', 'wp-seopress-pro' )
		);
	}

	private function checkViewport( $dom ) {
		$meta = $this->xpathFirst( $dom, '//meta[translate(@name,"VIEWPORT","viewport")="viewport"]' );

		return $this->row(
			'viewport',
			'foundations',
			__( 'Responsive viewport', 'wp-seopress-pro' ),
			$meta ? 'good' : 'warning',
			__( 'A viewport meta tag is required for mobile-friendly rendering, a ranking factor.', 'wp-seopress-pro' )
		);
	}

	private function checkTitle( $dom ) {
		$title = $this->xpathFirst( $dom, '//head/title' );
		$text  = $title ? trim( $title->textContent ) : '';

		return $this->row(
			'title',
			'foundations',
			__( 'Title tag', 'wp-seopress-pro' ),
			'' !== $text ? 'good' : 'bad',
			__( 'The homepage must have a non-empty <title>. It is the single most important on-page SEO element.', 'wp-seopress-pro' ),
			array(
				'value'  => $text,
				'fixUrl' => admin_url( 'admin.php?page=seopress-titles' ),
			)
		);
	}

	private function checkFavicon( $dom ) {
		$has_icon = function_exists( 'has_site_icon' ) && has_site_icon();
		if ( ! $has_icon ) {
			$has_icon = (bool) $this->xpathFirst( $dom, '//link[contains(translate(@rel,"ICON","icon"),"icon")]' );
		}

		return $this->row(
			'favicon',
			'foundations',
			__( 'Favicon', 'wp-seopress-pro' ),
			$has_icon ? 'good' : 'warning',
			__( 'A favicon reinforces your brand in browser tabs and search results.', 'wp-seopress-pro' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Indexability
	 * ------------------------------------------------------------------- */

	private function checkHttps( $is_https ) {
		return $this->row(
			'https',
			'indexability',
			__( 'HTTPS', 'wp-seopress-pro' ),
			$is_https ? 'good' : 'bad',
			__( 'Serving the site over HTTPS is expected by browsers and search engines.', 'wp-seopress-pro' )
		);
	}

	private function checkCanonical( $dom ) {
		$link = $this->xpathFirst( $dom, '//link[translate(@rel,"CANONICAL","canonical")="canonical"]' );
		$href = $link ? trim( $link->getAttribute( 'href' ) ) : '';

		return $this->row(
			'canonical',
			'indexability',
			__( 'Canonical URL', 'wp-seopress-pro' ),
			'' !== $href ? 'good' : 'warning',
			__( 'A canonical tag on the homepage prevents duplicate-content dilution across URL variants.', 'wp-seopress-pro' ),
			array( 'value' => $href )
		);
	}

	private function checkMetaRobots( $dom ) {
		$meta    = $this->xpathFirst( $dom, '//meta[translate(@name,"ROBOTS","robots")="robots"]' );
		$content = $meta ? strtolower( trim( $meta->getAttribute( 'content' ) ) ) : '';
		$noindex = ( false !== strpos( $content, 'noindex' ) );

		return $this->row(
			'meta_robots',
			'indexability',
			__( 'Homepage is indexable', 'wp-seopress-pro' ),
			$noindex ? 'bad' : 'good',
			__( 'The homepage should not carry a noindex directive, or it will be dropped from search results.', 'wp-seopress-pro' ),
			array(
				'value'  => $content,
				'fixUrl' => admin_url( 'admin.php?page=seopress-titles' ),
			)
		);
	}

	private function checkRobotsTxt() {
		$url      = home_url( '/robots.txt' );
		$response = $this->request( $url );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->row(
				'robots_txt',
				'indexability',
				__( 'robots.txt', 'wp-seopress-pro' ),
				'warning',
				__( 'A robots.txt file was not reachable. Search engines expect one at the site root.', 'wp-seopress-pro' )
			);
		}

		$body       = (string) wp_remote_retrieve_body( $response );
		$blocks_all = self::robotsBlocksEveryone( $body );

		return $this->row(
			'robots_txt',
			'indexability',
			__( 'robots.txt', 'wp-seopress-pro' ),
			$blocks_all ? 'bad' : 'good',
			$blocks_all
				? __( 'robots.txt blocks the entire site (Disallow: /). Search engines cannot crawl it.', 'wp-seopress-pro' )
				: __( 'robots.txt is reachable and does not block the whole site.', 'wp-seopress-pro' )
		);
	}

	/**
	 * Whether a robots.txt shuts the whole site to every crawler.
	 *
	 * The directive only counts inside a group that applies to `*`. A
	 * `Disallow: /` under `User-agent: AhrefsBot` blocks that one crawler and
	 * says nothing about search engines, which is the whole point of writing
	 * it. Matching the two tokens anywhere in the file reported "search
	 * engines cannot crawl this site" to anyone who blocks an SEO crawler or
	 * an AI bot — a common, deliberate configuration.
	 *
	 * Parsing follows the robots.txt grammar: consecutive `User-agent` lines
	 * open one group sharing the rules that follow, and the group ends at the
	 * next `User-agent` line that comes after a rule. Only a bare `/` counts:
	 * `Disallow: /*?*` or `Disallow: /wp-admin/` restrict paths, not the site.
	 *
	 * @since 10.2.0
	 *
	 * @param string $body Raw robots.txt contents.
	 *
	 * @return bool
	 */
	public static function robotsBlocksEveryone( $body ) { // phpcs:ignore -- camelCase matches the surrounding methods.
		$agents         = array();
		$collecting     = false;
		$applies_to_all = false;

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			// Strip comments, whatever their position on the line.
			$line = trim( preg_replace( '/#.*$/', '', $line ) );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = explode( ':', $line, 2 );

			$field = strtolower( trim( $field ) );
			$value = trim( $value );

			if ( 'user-agent' === $field ) {
				// A User-agent line following a rule starts a new group.
				if ( $collecting ) {
					$agents         = array();
					$collecting     = false;
					$applies_to_all = false;
				}

				$agents[] = $value;

				if ( '*' === $value ) {
					$applies_to_all = true;
				}

				continue;
			}

			if ( empty( $agents ) ) {
				// A rule before any User-agent line belongs to no group; every
				// parser ignores it, and so do we.
				continue;
			}

			$collecting = true;

			if ( 'disallow' === $field && '/' === $value && $applies_to_all ) {
				return true;
			}
		}

		return false;
	}

	private function checkSitemap() {
		$enabled = function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'xml-sitemap' );

		return $this->row(
			'sitemap',
			'indexability',
			__( 'XML sitemap', 'wp-seopress-pro' ),
			$enabled ? 'good' : 'warning',
			__( 'An XML sitemap helps search engines discover your content.', 'wp-seopress-pro' ),
			array( 'fixUrl' => admin_url( 'admin.php?page=seopress-xml-sitemap' ) )
		);
	}

	private function checkIndexNow() {
		$enabled = function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'instant-indexing' );

		return $this->row(
			'indexnow',
			'indexability',
			__( 'Instant indexing (IndexNow)', 'wp-seopress-pro' ),
			$enabled ? 'good' : 'warning',
			__( 'IndexNow notifies search engines the moment your content changes.', 'wp-seopress-pro' ),
			array( 'fixUrl' => admin_url( 'admin.php?page=seopress-instant-indexing' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Social
	 * ------------------------------------------------------------------- */

	private function checkOpenGraph( $dom ) {
		$og_title = $this->xpathFirst( $dom, '//meta[translate(@property,"OG:TILE","og:tile")="og:title"]' );
		$og_image = $this->xpathFirst( $dom, '//meta[translate(@property,"OG:IMAGE","og:image")="og:image"]' );
		$ok       = ( $og_title && $og_image );

		return $this->row(
			'open_graph',
			'social',
			__( 'Open Graph tags', 'wp-seopress-pro' ),
			$ok ? 'good' : 'warning',
			__( 'Open Graph title and image control how the homepage looks when shared on social networks.', 'wp-seopress-pro' ),
			array( 'fixUrl' => admin_url( 'admin.php?page=seopress-social' ) )
		);
	}

	private function checkTwitterCard( $dom ) {
		$card = $this->xpathFirst( $dom, '//meta[translate(@name,"TWIER:CARD","twier:card")="twitter:card"]' );

		return $this->row(
			'twitter_card',
			'social',
			__( 'X (Twitter) Card', 'wp-seopress-pro' ),
			$card ? 'good' : 'warning',
			__( 'A twitter:card meta tag enables rich previews when the homepage is shared on X.', 'wp-seopress-pro' ),
			array( 'fixUrl' => admin_url( 'admin.php?page=seopress-social' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Security (response headers)
	 * ------------------------------------------------------------------- */

	/**
	 * Generic "is this response header present" check. Absence is a warning
	 * (recommended hardening), never a hard failure.
	 *
	 * @param array|\WP_Error $response    The homepage response.
	 * @param string          $header      Header name (lowercase).
	 * @param string          $id          Check slug.
	 * @param string          $label       Human label.
	 * @param string          $description Advice.
	 *
	 * @return array
	 */
	private function checkHeaderPresent( $response, $header, $id, $label, $description ) {
		$value = wp_remote_retrieve_header( $response, $header );

		return $this->row(
			$id,
			'security',
			$label,
			'' !== (string) $value ? 'good' : 'warning',
			$description
		);
	}

	private function checkClickjacking( $response ) {
		$xfo = (string) wp_remote_retrieve_header( $response, 'x-frame-options' );
		$csp = (string) wp_remote_retrieve_header( $response, 'content-security-policy' );
		$has = ( '' !== $xfo || false !== stripos( $csp, 'frame-ancestors' ) );

		return $this->row(
			'security_clickjacking',
			'security',
			__( 'Clickjacking protection', 'wp-seopress-pro' ),
			$has ? 'good' : 'warning',
			__( 'An X-Frame-Options header (or CSP frame-ancestors) stops your site being embedded in a malicious iframe.', 'wp-seopress-pro' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Well-known / resilience
	 * ------------------------------------------------------------------- */

	private function checkSecurityTxt() {
		$response = $this->request( home_url( '/.well-known/security.txt' ), 'HEAD' );
		$found    = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );

		return $this->row(
			'security_txt',
			'resilience',
			__( 'security.txt', 'wp-seopress-pro' ),
			$found ? 'good' : 'warning',
			__( 'A /.well-known/security.txt file tells researchers how to report vulnerabilities. Optional but recommended.', 'wp-seopress-pro' )
		);
	}

	private function check404() {
		// Deterministic, unlikely-to-exist path so we never hit a real page.
		$probe    = home_url( '/seopress-audit-404-probe-' . substr( md5( home_url() ), 0, 8 ) );
		$response = $this->request( $probe );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $this->row(
			'error_404',
			'resilience',
			__( 'Custom 404 returns 404', 'wp-seopress-pro' ),
			404 === $code ? 'good' : 'bad',
			404 === $code
				? __( 'Missing pages correctly return an HTTP 404 status.', 'wp-seopress-pro' )
				: __( 'A missing page did not return a 404 status (soft 404). Search engines may waste crawl budget or index empty pages.', 'wp-seopress-pro' ),
			array( 'value' => (string) $code )
		);
	}

	private function checkLlmsTxt() {
		$response = $this->request( home_url( '/llms.txt' ), 'HEAD' );
		$found    = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );

		return $this->row(
			'llms_txt',
			'resilience',
			__( 'llms.txt', 'wp-seopress-pro' ),
			$found ? 'good' : 'warning',
			__( 'An llms.txt file helps AI assistants understand and surface your content. Optional but recommended.', 'wp-seopress-pro' )
		);
	}
}
