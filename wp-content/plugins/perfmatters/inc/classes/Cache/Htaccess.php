<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class Htaccess
{
	const MARKER = 'Perfmatters';

	//write browser cache + gzip rules when disk cache is on apache, otherwise remove our block
	public static function update()
	{
		//explicitly off: strip rules. unknown/non-apache contexts must not wipe a good .htaccess
		if(!\Perfmatters\Cache::disk_enabled()) {
			return self::remove();
		}

		/**
		 * Whether Perfmatters writes browser-cache / gzip rules to root .htaccess.
		 *
		 * @param bool $write Default true on Apache when disk page cache is enabled.
		 */
		if(!(bool) apply_filters('perfmatters_cache_htaccess', true)) {
			return self::remove();
		}

		if(!self::is_apache()) {
			return false;
		}

		$path = self::path();

		if($path === '' || !self::ensure_file($path)) {
			return false;
		}

		$rules = self::rules();

		if($rules === '') {
			return self::remove();
		}

		self::load_markers();

		$written = insert_with_markers($path, self::MARKER, explode("\n", $rules));

		//WordPress rewrite-to-index.php must not run first or page-cache rules never match
		if($written) {
			self::move_before_wordpress($path);
			self::separate_from_wordpress($path);
		}

		//apache rewrites need to read cache files; deny-all would 403 those hits
		self::protect_cache_dir(self::should_rewrite());

		return $written;
	}

	//strip the # BEGIN/END Perfmatters block from root .htaccess
	public static function remove()
	{
		$path = self::path();

		if($path !== '' && is_file($path) && is_writable($path)) {
			self::load_markers();
			insert_with_markers($path, self::MARKER, array());
		}

		self::protect_cache_dir(false);

		return true;
	}

	//page-cache rewrites only when mobile files are off (drop-in handles device split)
	private static function should_rewrite()
	{
		if(!\Perfmatters\Cache::disk_enabled() || !empty(\Perfmatters\Config::$options['cache']['page_mobile'])) {
			return false;
		}

		if(!self::is_apache() || self::cache_web_path() === '') {
			return false;
		}

		/**
		 * Whether Perfmatters adds Apache rewrite rules that serve cached HTML before PHP.
		 *
		 * @param bool $rewrite Default true when disk cache is on Apache and separate mobile cache is off.
		 */
		return (bool) apply_filters('perfmatters_cache_htaccess_rewrite', true);
	}

	//expires + deflate, plus optional rewrite-to-cache section
	private static function rules()
	{
		$rules = self::browser_rules();

		if(self::should_rewrite()) {
			$rewrite = self::rewrite_rules();

			if($rewrite !== '') {
				$rules .= "\n\n" . $rewrite;
			}
		}

		/**
		 * Filter the .htaccess rules written between the Perfmatters markers.
		 *
		 * @param string $rules Rules without the BEGIN/END markers.
		 */
		$rules = apply_filters('perfmatters_cache_htaccess_rules', $rules);

		return is_string($rules) ? trim($rules) : '';
	}

	private static function browser_rules()
	{
		return <<<'HTACCESS'
# Browser caching for static assets. HTML stays at 0 so purges stay visible.
<IfModule mod_expires.c>
	ExpiresActive on
	ExpiresByType text/html "access plus 0 seconds"
	ExpiresByType text/xml "access plus 0 seconds"
	ExpiresByType application/xml "access plus 0 seconds"
	ExpiresByType application/json "access plus 0 seconds"
	ExpiresByType image/x-icon "access plus 1 year"
	ExpiresByType image/gif "access plus 1 year"
	ExpiresByType image/png "access plus 1 year"
	ExpiresByType image/jpeg "access plus 1 year"
	ExpiresByType image/webp "access plus 1 year"
	ExpiresByType image/avif "access plus 1 year"
	ExpiresByType image/svg+xml "access plus 1 year"
	ExpiresByType video/ogg "access plus 1 year"
	ExpiresByType audio/ogg "access plus 1 year"
	ExpiresByType video/mp4 "access plus 1 year"
	ExpiresByType video/webm "access plus 1 year"
	ExpiresByType font/ttf "access plus 1 year"
	ExpiresByType font/otf "access plus 1 year"
	ExpiresByType font/woff "access plus 1 year"
	ExpiresByType font/woff2 "access plus 1 year"
	ExpiresByType application/vnd.ms-fontobject "access plus 1 year"
	ExpiresByType application/font-woff2 "access plus 1 year"
	ExpiresByType text/css "access plus 1 year"
	ExpiresByType text/javascript "access plus 1 year"
	ExpiresByType application/javascript "access plus 1 year"
	ExpiresByType application/x-javascript "access plus 1 year"
</IfModule>

# Gzip compression for text responses. Skip already-compressed binaries.
<IfModule mod_deflate.c>
	<IfModule mod_setenvif.c>
		SetEnvIfNoCase Request_URI \.(?:gif|jpe?g|png|webp|avif|zip|gz|bz2|rar|7z|mp[34]|m4[av]|webm|ogg|ogv|avi|mov|wmv|flv|pdf|exe|woff2?)$ no-gzip dont-vary
	</IfModule>
	<IfModule mod_filter.c>
		AddOutputFilterByType DEFLATE text/css text/javascript text/html text/plain text/xml text/x-component
		AddOutputFilterByType DEFLATE application/javascript application/x-javascript application/json
		AddOutputFilterByType DEFLATE application/xml application/xhtml+xml application/rss+xml application/atom+xml
		AddOutputFilterByType DEFLATE application/vnd.ms-fontobject application/x-font-ttf font/opentype font/ttf font/otf font/woff font/woff2
		AddOutputFilterByType DEFLATE image/svg+xml image/x-icon
	</IfModule>
	<IfModule mod_headers.c>
		Header append Vary Accept-Encoding
	</IfModule>
</IfModule>
HTACCESS;
	}

	//serve index.html / index.html.gz from disk before PHP when the request is anonymous and simple
	private static function rewrite_rules()
	{
		$web = self::cache_web_path();
		$base = self::rewrite_base();

		if($web === '' || $base === '') {
			return '';
		}

		$web_quoted = preg_quote($web, '/');
		$cookies = self::cookie_condition();
		$excluded = self::excluded_conditions();
		//OLS can treat rewritten .html.gz as a download; leave .gz for the PHP drop-in only
		$gzip = Config::is_gzip_enabled() && !self::is_openlitespeed();

		//match page-config: no CDN-Cache-Control without a token (can't purge orphan edge HITs)
		$cf = !empty(\Perfmatters\Config::$options['cache']['cloudflare']) && Cloudflare::has_token();
		$cf_ttl = $cf ? Cloudflare::edge_ttl() : 0;

		$lines = array();
		$lines[] = '# Serve cached HTML from disk before PHP. Drop-in still handles everything else.';
		//slash must match permalink_structure — optional /?$ would HIT before redirect_canonical can 301
		$want_slash = Config::uses_trailing_slash();
		$lines[] = '<IfModule mod_headers.c>';

		foreach(($gzip ? array('index.html', 'index.html.gz') : array('index.html')) as $name) {
			$is_gzip = substr($name, -3) === '.gz';
			$lines[] = '	<FilesMatch "index\\.html' . ($is_gzip ? '\\.gz' : '') . '$">';
			$lines[] = '		Header set X-Perfmatters-Cache "HIT"';
			$lines[] = '		Header set Cache-Control "' . Config::browser_cache_control() . '"';

			if($cf_ttl > 0) {
				$lines[] = '		Header set CDN-Cache-Control "max-age=' . (int) $cf_ttl . '"';
			}

			$lines[] = '		Header set Content-Type "text/html; charset=UTF-8"';

			if($is_gzip) {
				$lines[] = '		Header set Content-Encoding "gzip"';
			}

			$lines[] = '		Header set Vary "Accept-Encoding"';
			$lines[] = '	</FilesMatch>';
		}

		$lines[] = '</IfModule>';

		if($gzip) {
			$lines[] = '<IfModule mod_mime.c>';
			$lines[] = '	AddType text/html .gz';
			$lines[] = '	AddCharset UTF-8 .gz';
			$lines[] = '	AddEncoding gzip .gz';
			$lines[] = '</IfModule>';
			$lines[] = '<IfModule mod_setenvif.c>';
			$lines[] = '	SetEnvIfNoCase Request_URI \\.html\\.gz$ no-gzip';
			$lines[] = '</IfModule>';
		}

		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = '	RewriteEngine On';
		$lines[] = '	RewriteBase ' . $base;

		//block hotlinking the cache directory; internal rewrites are not THE_REQUEST
		$lines[] = '	RewriteCond %{THE_REQUEST} ' . $web_quoted . '/ [NC]';
		$lines[] = '	RewriteRule ^ - [F]';

		$common = array(
			'	RewriteCond %{REQUEST_METHOD} GET|HEAD',
			'	RewriteCond %{QUERY_STRING} =""',
			//leave /foo// and //foo/ to redirect_canonical (same file as /foo/ after empty segments collapse)
			'	RewriteCond %{REQUEST_URI} !//',
			'	RewriteCond %{REQUEST_URI} !/wp-(?:admin|login|register|comments-post|cron|json)/ [NC]',
			'	RewriteCond %{REQUEST_URI} !/(?:admin-ajax|xmlrpc)\\.php$ [NC]'
		);

		if($cookies !== '') {
			$common[] = '	RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]';
		}

		foreach($excluded as $cond) {
			$common[] = $cond;
		}

		//page/ sits under this site's cache segment (sibling of minify). no HTTP_HOST folder.
		//subdir installs: RewriteBase strips the home prefix so $1 matches stored paths.
		foreach(($gzip ? array('index.html.gz', 'index.html') : array('index.html')) as $name) {
			$is_gzip = substr($name, -3) === '.gz';

			if($base === '/') {
				$lines = array_merge($lines, $common);

				if($is_gzip) {
					$lines[] = '	RewriteCond %{HTTP:Accept-Encoding} gzip';
				}

				//pretty permalinks: only the canonical slash may HIT from disk
				if($want_slash === true) {
					$lines[] = '	RewriteCond %{REQUEST_URI} /$';
					$lines[] = '	RewriteCond "%{DOCUMENT_ROOT}/' . $web . '%{REQUEST_URI}' . $name . '" -f';
					$lines[] = '	RewriteRule .* /' . $web . '%{REQUEST_URI}' . $name . ' [L]';
				}
				elseif($want_slash === false) {
					//homepage keeps a trailing slash in REQUEST_URI; other paths must not
					$lines[] = '	RewriteCond %{REQUEST_URI} ^/$ [OR]';
					$lines[] = '	RewriteCond %{REQUEST_URI} !/$';
					$lines[] = '	RewriteCond "%{DOCUMENT_ROOT}/' . $web . '%{REQUEST_URI}/' . $name . '" -f';
					$lines[] = '	RewriteRule .* /' . $web . '%{REQUEST_URI}/' . $name . ' [L]';
				}
				else {
					//plain permalinks — no slash policy
					$lines[] = '	RewriteCond "%{DOCUMENT_ROOT}/' . $web . '%{REQUEST_URI}/' . $name . '" -f';
					$lines[] = '	RewriteRule .* /' . $web . '%{REQUEST_URI}/' . $name . ' [L]';
				}

				continue;
			}

			//homepage under RewriteBase (/blog → page/index.html)
			$lines = array_merge($lines, $common);

			if($is_gzip) {
				$lines[] = '	RewriteCond %{HTTP:Accept-Encoding} gzip';
			}

			$lines[] = '	RewriteCond "%{DOCUMENT_ROOT}/' . $web . '/' . $name . '" -f';
			$lines[] = '	RewriteRule ^$ /' . $web . '/' . $name . ' [L]';

			//inner pages — slash in the pattern must match permalink_structure (no /?$)
			$lines = array_merge($lines, $common);

			if($is_gzip) {
				$lines[] = '	RewriteCond %{HTTP:Accept-Encoding} gzip';
			}

			$lines[] = '	RewriteCond "%{DOCUMENT_ROOT}/' . $web . '/$1/' . $name . '" -f';

			if($want_slash === true) {
				$lines[] = '	RewriteRule ^(.+)/$ /' . $web . '/$1/' . $name . ' [L]';
			}
			elseif($want_slash === false) {
				$lines[] = '	RewriteRule ^(.+[^/])$ /' . $web . '/$1/' . $name . ' [L]';
			}
			else {
				$lines[] = '	RewriteRule ^(.+)/?$ /' . $web . '/$1/' . $name . ' [L]';
			}
		}

		$lines[] = '</IfModule>';

		return implode("\n", $lines);
	}

	//pipe-separated cookie prefixes/names that must fall through to PHP
	private static function cookie_condition()
	{
		$parts = array();

		foreach(Config::cookie_prefixes() as $prefix) {
			$prefix = (string) $prefix;

			if($prefix !== '') {
				$parts[] = preg_quote($prefix, '/');
			}
		}

		foreach(Config::vary_cookies() as $name) {
			$name = (string) $name;

			if($name !== '') {
				$parts[] = preg_quote($name, '/');
			}
		}

		$parts = array_values(array_unique(array_filter($parts)));

		return $parts ? implode('|', $parts) : '';
	}

	//excluded paths from settings / WooCommerce — leave those to the drop-in
	private static function excluded_conditions()
	{
		$conditions = array();

		foreach(Config::excluded_paths() as $path) {
			$path = trim((string) $path);

			if($path === '') {
				continue;
			}

			$conditions[] = '	RewriteCond %{REQUEST_URI} !' . preg_quote($path, '/') . ' [NC]';
		}

		return $conditions;
	}

	//web path to the site page/ directory under the document root
	private static function cache_web_path()
	{
		$cache = Config::cache_dir();
		$roots = array();

		if(!empty($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])) {
			$roots[] = $_SERVER['DOCUMENT_ROOT'];
		}

		$roots[] = ABSPATH;

		foreach($roots as $root) {
			$root = realpath($root);
			$real = realpath($cache);

			if($root === false) {
				continue;
			}

			$root = rtrim(str_replace('\\', '/', $root), '/');
			$candidate = ($real !== false) ? str_replace('\\', '/', $real) : str_replace('\\', '/', $cache);

			if(strpos($candidate, $root . '/') === 0) {
				return ltrim(substr($candidate, strlen($root)), '/');
			}
		}

		//default layout when the absolute path could not be mapped
		$content = defined('WP_CONTENT_DIR') ? str_replace('\\', '/', WP_CONTENT_DIR) : '';
		$abspath = str_replace('\\', '/', ABSPATH);
		$segment = Config::site_cache_segment();
		$fallback = 'cache/perfmatters' . ($segment !== '' ? '/' . $segment : '') . '/page';

		if($content !== '' && strpos($content, $abspath) === 0) {
			$relative = ltrim(substr($content, strlen($abspath)), '/');

			return ($relative !== '' ? $relative : 'wp-content') . '/' . $fallback;
		}

		return 'wp-content/' . $fallback;
	}

	private static function rewrite_base()
	{
		$path = wp_parse_url(home_url('/'), PHP_URL_PATH);

		if(!is_string($path) || $path === '' || $path === '/') {
			return '/';
		}

		return trailingslashit($path);
	}

	//Apache rules that refuse all HTTP to a directory (ignored on nginx)
	public static function deny_http_rules()
	{
		return "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
	}

	//re-apply page/ .htaccess (+ index.php) after a full disk clear recreates an empty tree
	public static function protect_page_dir()
	{
		self::protect_cache_dir(self::should_rewrite());
	}

	//deny HTTP to the cache dir unless Apache rewrite needs to read those files
	private static function protect_cache_dir($allow_rewrite_serve)
	{
		$dir = Config::cache_dir();

		if(!is_dir($dir) || !is_writable($dir)) {
			return;
		}

		$rules = $allow_rewrite_serve ? "Options -Indexes\n" : self::deny_http_rules();

		@file_put_contents($dir . '/.htaccess', $rules, LOCK_EX);
		@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX);
	}

	private static function is_apache()
	{
		global $is_apache;

		if(!empty($is_apache)) {
			return true;
		}

		if(!empty($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE'])) {
			return (bool) preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE']);
		}

		//WP-CLI has no SERVER_SOFTWARE; keep detecting Apache so rebuilds do not wipe rules
		if((defined('WP_CLI') && WP_CLI) || PHP_SAPI === 'cli') {
			return is_dir('/etc/apache2') || is_dir('/usr/local/apache2') || is_dir('/opt/bitnami/apache');
		}

		return false;
	}

	//LSWS_EDITION is the reliable OLS flag (SERVER_SOFTWARE is often just "LiteSpeed" for both)
	private static function is_openlitespeed()
	{
		if(!empty($_SERVER['LSWS_EDITION']) && is_string($_SERVER['LSWS_EDITION'])) {
			return (bool) preg_match('/openlitespeed/i', $_SERVER['LSWS_EDITION']);
		}

		return false;
	}

	private static function path()
	{
		if(!function_exists('get_home_path')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home = get_home_path();

		if(!is_string($home) || $home === '') {
			return '';
		}

		return $home . '.htaccess';
	}

	private static function ensure_file($path)
	{
		$dir = dirname($path);

		if(!is_dir($dir) || !is_writable($dir)) {
			return false;
		}

		if(!is_file($path)) {
			$created = @file_put_contents($path, '');

			if($created === false) {
				return false;
			}

			@chmod($path, 0644);
		}

		return is_writable($path);
	}

	private static function load_markers()
	{
		if(!function_exists('insert_with_markers')) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}

	//cache rewrites have to win before WP hands the request to PHP
	private static function move_before_wordpress($path)
	{
		$contents = @file_get_contents($path);

		if(!is_string($contents) || $contents === '') {
			return false;
		}

		$start = '# BEGIN ' . self::MARKER;
		$end = '# END ' . self::MARKER;
		$wp = '# BEGIN WordPress';

		$start_pos = strpos($contents, $start);
		$end_pos = strpos($contents, $end);
		$wp_pos = strpos($contents, $wp);

		if($start_pos === false || $end_pos === false || $wp_pos === false) {
			return false;
		}

		//already above WordPress
		if($start_pos < $wp_pos) {
			return true;
		}

		$end_pos += strlen($end);
		while(isset($contents[$end_pos]) && ($contents[$end_pos] === "\n" || $contents[$end_pos] === "\r")) {
			$end_pos++;
		}

		$block = rtrim(substr($contents, $start_pos, $end_pos - $start_pos)) . "\n\n";
		$without = substr($contents, 0, $start_pos) . ltrim(substr($contents, $end_pos), "\r\n");
		$wp_pos = strpos($without, $wp);

		if($wp_pos === false) {
			return false;
		}

		$updated = substr($without, 0, $wp_pos) . $block . substr($without, $wp_pos);

		return @file_put_contents($path, $updated, LOCK_EX) !== false;
	}

	//keep a blank line between our marker and WordPress so the file stays readable
	private static function separate_from_wordpress($path)
	{
		$contents = @file_get_contents($path);

		if(!is_string($contents) || $contents === '') {
			return false;
		}

		$updated = preg_replace(
			'/# END ' . preg_quote(self::MARKER, '/') . '\s*# BEGIN WordPress/',
			'# END ' . self::MARKER . "\n\n# BEGIN WordPress",
			$contents,
			1
		);

		if(!is_string($updated) || $updated === $contents) {
			return true;
		}

		return @file_put_contents($path, $updated, LOCK_EX) !== false;
	}
}
