<?php
/*
 * Shared by the plugin and the advanced-cache.php drop-in.
 * This file is copied to wp-content/cache/perfmatters/runtime.php.
 * Keep it free of WordPress functions. The drop-in loads before they exist.
 */

if(!defined('ABSPATH')) {
	exit;
}

if(!function_exists('perfmatters_cache_host')) {

	//hostname from the current request, without a port
	function perfmatters_cache_host() {
		$host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
		$host = strtolower(preg_replace('/:\d+$/', '', $host));
		$host = trim($host, '.');

		if($host === '' || strpos($host, '/') !== false || strpos($host, '\\') !== false || strpos($host, '..') !== false) {
			return '';
		}

		return $host;
	}
}

if(!function_exists('perfmatters_cache_request_path')) {

	//path only, so ignored query strings do not create extra cache files
	function perfmatters_cache_request_path() {
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = parse_url($uri, PHP_URL_PATH);

		if(!is_string($path) || $path === '') {
			$path = '/';
		}

		$path = rawurldecode($path);

		if(strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
			return '';
		}

		return $path;
	}
}

if(!function_exists('perfmatters_cache_query_is_cacheable')) {

	//unknown query args stay dynamic. marketing tags reuse the path file. listed names get their own file
	function perfmatters_cache_query_is_cacheable($ignored, $allowed = array()) {
		$args = perfmatters_cache_query_args();

		if($args === null) {
			return false;
		}

		if(empty($args)) {
			return true;
		}

		$ignored = array_map('strtolower', (array) $ignored);
		$allowed = array_map('strtolower', (array) $allowed);

		foreach(array_keys($args) as $key) {
			$name = strtolower((string) $key);

			if(!in_array($name, $ignored, true) && !in_array($name, $allowed, true)) {
				return false;
			}
		}

		return true;
	}
}

if(!function_exists('perfmatters_cache_query_args_from_string')) {

	//decoded query args from a query string, or null when the string is not a flat list we can store
	function perfmatters_cache_query_args_from_string($query) {
		if(!is_string($query) || $query === '') {
			return array();
		}

		if(strlen($query) > 2048) {
			return null;
		}

		$args = array();
		parse_str($query, $args);

		foreach($args as $value) {
			if(is_array($value) || strlen((string) $value) > 200) {
				return null;
			}
		}

		return $args;
	}
}

if(!function_exists('perfmatters_cache_query_args')) {

	//decoded query args for this request, or null when the string is not a flat list we can store
	function perfmatters_cache_query_args() {
		$query = parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_QUERY);

		return perfmatters_cache_query_args_from_string(is_string($query) ? $query : '');
	}
}

if(!function_exists('perfmatters_cache_filter_allowed_query_args')) {

	//allowed query args only (sorted). ignored/marketing tags and unknown keys are dropped
	function perfmatters_cache_filter_allowed_query_args($args, $ignored, $allowed) {
		if(!is_array($args) || empty($args)) {
			return array();
		}

		$ignored = array_map('strtolower', (array) $ignored);
		$allowed = array_map('strtolower', (array) $allowed);
		$kept = array();

		foreach($args as $key => $value) {
			$name = strtolower((string) $key);

			if(in_array($name, $ignored, true) || !in_array($name, $allowed, true)) {
				continue;
			}

			$kept[$name] = (string) $value;
		}

		ksort($kept);

		return $kept;
	}
}

if(!function_exists('perfmatters_cache_query_suffix')) {

	//stable file suffix for allowed query args and vary cookies. marketing tags are left out
	function perfmatters_cache_query_suffix($ignored, $allowed, $vary_cookies = array()) {
		$kept = perfmatters_cache_filter_allowed_query_args(perfmatters_cache_query_args(), $ignored, $allowed);

		$cookies = perfmatters_cache_vary_cookie_values($vary_cookies);

		if(!is_array($cookies)) {
			$cookies = array();
		}

		if(empty($kept) && empty($cookies)) {
			return '';
		}

		if(empty($cookies)) {
			return 'q-' . md5(http_build_query($kept, '', '&', PHP_QUERY_RFC3986));
		}

		$identity = array(
			'cookie' => http_build_query($cookies, '', '&', PHP_QUERY_RFC3986)
		);

		if(!empty($kept)) {
			$identity['query'] = http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
		}

		return 'q-' . md5(http_build_query($identity, '', '&', PHP_QUERY_RFC3986));
	}
}

if(!function_exists('perfmatters_cache_vary_cookie_values')) {

	//listed cookie values that belong in the file hash. null when a value is not safe to store
	function perfmatters_cache_vary_cookie_values($names) {
		$names = array_values(array_filter(array_map('strval', (array) $names), function($name) {
			return $name !== '';
		}));

		if(empty($names) || empty($_COOKIE)) {
			return array();
		}

		$kept = array();

		foreach($names as $name) {
			if(!isset($_COOKIE[$name])) {
				continue;
			}

			if(is_array($_COOKIE[$name])) {
				return null;
			}

			$value = (string) $_COOKIE[$name];

			if($value === '') {
				continue;
			}

			if(strlen($value) > 200) {
				return null;
			}

			$kept[$name] = $value;
		}

		ksort($kept);

		return $kept;
	}
}

if(!function_exists('perfmatters_cache_has_blocked_cookie')) {

	//logged-in users, commenters, password-protected posts, and carts
	function perfmatters_cache_has_blocked_cookie($prefixes) {
		if(empty($_COOKIE) || empty($prefixes)) {
			return false;
		}

		foreach(array_keys($_COOKIE) as $name) {
			foreach((array) $prefixes as $prefix) {
				if($prefix !== '' && stripos((string) $name, $prefix) === 0) {
					return true;
				}
			}
		}

		return false;
	}
}

if(!function_exists('perfmatters_cache_has_blocked_agent')) {

	//partial, case-insensitive match against the request user agent
	function perfmatters_cache_has_blocked_agent($needles) {
		if(empty($needles)) {
			return false;
		}

		$agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		if($agent === '') {
			return false;
		}

		foreach((array) $needles as $needle) {
			$needle = (string) $needle;

			if($needle !== '' && stripos($agent, $needle) !== false) {
				return true;
			}
		}

		return false;
	}
}

if(!function_exists('perfmatters_cache_path_excluded')) {

	//excluded urls are partial, case-insensitive matches against the path
	function perfmatters_cache_path_excluded($path, $excluded) {
		$path = strtolower((string) $path);

		foreach((array) $excluded as $rule) {
			$rule = strtolower(trim((string) $rule));

			if($rule !== '' && strpos($path, $rule) !== false) {
				return true;
			}
		}

		return false;
	}
}

if(!function_exists('perfmatters_cache_uri_blocked')) {

	//requests that should never be served from a static html file
	function perfmatters_cache_uri_blocked($path) {
		$path = strtolower((string) $path);
		$needles = array(
			'wp-admin',
			'wp-login.php',
			'wp-cron.php',
			'admin-ajax.php',
			'wp-json',
			'xmlrpc.php',
			'wp-comments-post.php'
		);

		foreach($needles as $needle) {
			if(strpos($path, $needle) !== false) {
				return true;
			}
		}

		return (bool) preg_match('/\.(xml|xsl|txt|json|js|css|map|jpg|jpeg|png|gif|webp|svg|ico|php|gz)$/', $path);
	}
}

if(!function_exists('perfmatters_cache_needs_canonical_redirect')) {

	//wrong trailing slash → miss so redirect_canonical can 301 (htaccess must not HIT those either).
	//mixed-case → miss/do not store (core still serves 200; we do not invent a lowercase 301)
	//consecutive slashes (/foo//, //foo/) → miss; otherwise empty segments collapse onto the same index.html
	function perfmatters_cache_needs_canonical_redirect($config) {
		$path = perfmatters_cache_request_path();

		if($path === '' || $path === '/') {
			return false;
		}

		//static-looking paths are not pretty permalinks
		if(preg_match('/\.[a-zA-Z0-9]{1,10}$/', $path)) {
			return false;
		}

		if(strpos($path, '//') !== false) {
			return true;
		}

		//do not create Sample-Page/ beside sample-page/
		if(preg_match('/[A-Z]/', $path)) {
			return true;
		}

		//plain permalinks leave trailing_slash null — no slash policy to enforce
		if(!is_array($config) || !array_key_exists('trailing_slash', $config) || $config['trailing_slash'] === null) {
			return false;
		}

		$want_slash = !empty($config['trailing_slash']);
		$home = isset($config['home_path']) ? rtrim((string) $config['home_path'], '/') : '';

		//subdir home (/blog vs /blog/) — domain root "/" already returned above
		if($home !== '' && ($path === $home || $path === $home . '/')) {
			$has_slash = substr($path, -1) === '/';

			return $want_slash ? !$has_slash : $has_slash;
		}

		$has_slash = substr($path, -1) === '/';

		return $want_slash ? !$has_slash : $has_slash;
	}
}

if(!function_exists('perfmatters_cache_is_mobile')) {

	//match wp_is_mobile() so Script Manager device rules and cache files stay aligned.
	//advanced-cache runs before WordPress, so filters on wp_is_mobile are not applied here.
	function perfmatters_cache_is_mobile() {
		if(function_exists('wp_is_mobile')) {
			return (bool) wp_is_mobile();
		}

		if(isset($_SERVER['HTTP_SEC_CH_UA_MOBILE'])) {
			return $_SERVER['HTTP_SEC_CH_UA_MOBILE'] === '?1';
		}

		if(empty($_SERVER['HTTP_USER_AGENT']) || !is_string($_SERVER['HTTP_USER_AGENT'])) {
			return false;
		}

		$ua = $_SERVER['HTTP_USER_AGENT'];

		return strpos($ua, 'Mobile') !== false
			|| strpos($ua, 'Android') !== false
			|| strpos($ua, 'Silk/') !== false
			|| strpos($ua, 'Kindle') !== false
			|| strpos($ua, 'BlackBerry') !== false
			|| strpos($ua, 'Opera Mini') !== false
			|| strpos($ua, 'Opera Mobi') !== false;
	}
}

if(!function_exists('perfmatters_cache_strip_home_path')) {

	//path under the site home (/blog/about → /about). empty home_path leaves the request path alone
	function perfmatters_cache_strip_home_path($path, $home_path = '') {
		$path = is_string($path) ? $path : '';
		$home_path = is_string($home_path) ? rtrim($home_path, '/') : '';

		if($path === '') {
			return '/';
		}

		if($home_path === '' || $home_path === '/') {
			return $path;
		}

		if($path === $home_path || $path === $home_path . '/') {
			return '/';
		}

		if(strpos($path, $home_path . '/') === 0) {
			$stripped = substr($path, strlen($home_path));

			return (is_string($stripped) && $stripped !== '') ? $stripped : '/';
		}

		return $path;
	}
}

if(!function_exists('perfmatters_cache_file_path')) {

	//mirror the url under the site page/ directory (no host segment). reject traversal
	function perfmatters_cache_file_path($cache_dir, $path, $suffix = '', $mobile = false) {
		if($cache_dir === '' || $path === '') {
			return '';
		}

		$safe = array();

		foreach(explode('/', (string) $path) as $segment) {
			if($segment === '' || $segment === '.') {
				continue;
			}

			if($segment === '..' || strpos($segment, "\0") !== false) {
				return '';
			}

			$safe[] = $segment;
		}

		$relative = $safe ? implode('/', $safe) . '/' : '';
		$name = 'index.html';

		if(is_string($suffix) && preg_match('/^q-[a-f0-9]{32}$/', $suffix)) {
			$name = $suffix . '.html';
		}

		if($mobile) {
			$name = substr($name, 0, -5) . '-mobile.html';
		}

		return rtrim((string) $cache_dir, '/\\') . '/' . $relative . $name;
	}
}

if(!function_exists('perfmatters_cache_request_file')) {

	//file for this request, including a suffix when an allowed query arg is present
	function perfmatters_cache_request_file($config) {
		$ignored = isset($config['ignored_queries']) ? $config['ignored_queries'] : array();
		$allowed = isset($config['query_strings']) ? $config['query_strings'] : array();
		$vary = isset($config['vary_cookies']) ? $config['vary_cookies'] : array();
		$suffix = perfmatters_cache_query_suffix($ignored, $allowed, $vary);
		$mobile = !empty($config['page_mobile']) && perfmatters_cache_is_mobile();
		$home = isset($config['home_path']) ? $config['home_path'] : '';
		$path = perfmatters_cache_strip_home_path(perfmatters_cache_request_path(), $home);

		return perfmatters_cache_file_path($config['cache_dir'], $path, $suffix, $mobile);
	}
}

if(!function_exists('perfmatters_cache_request_is_blocked')) {

	//true when this request must not read or write the page cache
	function perfmatters_cache_request_is_blocked($config) {
		if(PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI)) {
			return true;
		}

		$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');

		if($method !== 'GET' && $method !== 'HEAD') {
			return true;
		}

		$path = perfmatters_cache_request_path();
		$host = perfmatters_cache_host();

		if($path === '' || $host === '') {
			return true;
		}

		//only this site's host may read/write the page/ tree (www vs apex stays uncached)
		if(!empty($config['host']) && $host !== strtolower((string) $config['host'])) {
			return true;
		}

		if(perfmatters_cache_uri_blocked($path)) {
			return true;
		}

		if(!perfmatters_cache_query_is_cacheable(
			isset($config['ignored_queries']) ? $config['ignored_queries'] : array(),
			isset($config['query_strings']) ? $config['query_strings'] : array()
		)) {
			return true;
		}

		if(perfmatters_cache_has_blocked_cookie(isset($config['cookies']) ? $config['cookies'] : array())) {
			return true;
		}

		if(perfmatters_cache_vary_cookie_values(isset($config['vary_cookies']) ? $config['vary_cookies'] : array()) === null) {
			return true;
		}

		if(perfmatters_cache_has_blocked_agent(isset($config['bypass_agents']) ? $config['bypass_agents'] : array())) {
			return true;
		}

		if(perfmatters_cache_path_excluded($path, isset($config['excluded']) ? $config['excluded'] : array())) {
			return true;
		}

		//wrong slash or mixed-case — miss (slash: redirect_canonical; case: do not store a second file)
		if(perfmatters_cache_needs_canonical_redirect($config)) {
			return true;
		}

		return false;
	}
}

if(!function_exists('perfmatters_cache_accepts_gzip')) {

	//true when the browser can decode a gzip body
	function perfmatters_cache_accepts_gzip() {
		$encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';

		return $encoding !== '' && strpos($encoding, 'gzip') !== false;
	}
}

if(!function_exists('perfmatters_cache_response_file')) {

	//gzip sibling when enabled, the browser asked for it, and the file stays inside the cache directory
	function perfmatters_cache_response_file($file, $cache_dir, $gzip_enabled = true) {
		if(!$gzip_enabled || !perfmatters_cache_accepts_gzip()) {
			return array($file, false);
		}

		$gzip = $file . '.gz';

		if(!is_file($gzip) || !is_readable($gzip) || filesize($gzip) === 0) {
			return array($file, false);
		}

		$real = realpath($gzip);
		$root = realpath($cache_dir);

		if($real === false || $root === false) {
			return array($file, false);
		}

		$root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;

		if(strpos($real, $root) !== 0) {
			return array($file, false);
		}

		return array($real, true);
	}
}

if(!function_exists('perfmatters_cache_should_serve')) {

	//absolute file path when this request can be served from disk
	function perfmatters_cache_should_serve($config) {
		if(empty($config['page']) || empty($config['cache_dir']) || perfmatters_cache_request_is_blocked($config)) {
			return '';
		}

		$file = perfmatters_cache_request_file($config);

		if($file === '' || !is_file($file) || !is_readable($file) || filesize($file) === 0) {
			return '';
		}

		$real = realpath($file);
		$root = realpath($config['cache_dir']);

		if($real === false || $root === false) {
			return '';
		}

		$root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;

		if(strpos($real, $root) !== 0) {
			return '';
		}

		if(perfmatters_cache_expired($real, isset($config['page_ttl']) ? $config['page_ttl'] : 0)) {
			return '';
		}

		return $real;
	}
}

if(!function_exists('perfmatters_cache_expired')) {

	//true when the file is older than the lifespan. the stale copy is removed so the next response can replace it
	function perfmatters_cache_expired($file, $lifespan) {
		$lifespan = (int) $lifespan;

		if($lifespan < 1) {
			return false;
		}

		$modified = filemtime($file);

		if(!$modified || $modified >= time() - $lifespan) {
			return false;
		}

		if(is_file($file)) {
			unlink($file);
		}

		$gzip = $file . '.gz';

		if(is_file($gzip)) {
			unlink($gzip);
		}

		return true;
	}
}
