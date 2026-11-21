<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class Storage
{
	const DISK_STATS_TRANSIENT = 'perfmatters_cache_disk_stats';
	//reuse within one request; transient covers warmer AJAX polls a few seconds apart
	const DISK_STATS_TTL = 5;

	private static $disk_stats = null;

	//load the same path rules the drop-in uses
	public static function load_runtime()
	{
		require_once PERFMATTERS_PATH . 'inc/cache-runtime.php';
	}

	//store the finished html and return it unchanged for this response
	public static function capture($html)
	{
		if(!is_string($html)) {
			return $html;
		}

		self::load_runtime();
		$config = Config::to_file_array();
		//warmer may still write disk (ignored query in the path) but must not bless the edge response
		$bless = false;

		if(self::can_store($html, $config)) {
			$file = perfmatters_cache_request_file($config);

			if($file !== '') {
				$now = new \DateTimeImmutable('now', wp_timezone());
				$stored = $html . "\n<!-- Perfmatters | " . $now->format('Y-m-d H:i') . ' UTC' . $now->format('P') . " -->\n";
				//only bless the edge when the file actually landed (unwritable page/ must not CDN-Cache-Control)
				$bless = self::write($file, $stored) && !Warmer::is_request();
			}
		}

		if(Cloudflare::is_enabled()) {
			Cloudflare::send_edge_headers($bless);
		}

		return $html;
	}

	//delete html files for this site. bootstrap config/runtime and the drop-in stay in place
	public static function clear()
	{
		self::delete_tree(Config::cache_dir());

		//recreate the empty page/ shell and restore Apache deny / rewrite-serve rules
		self::ensure_dir(Config::cache_dir());
		Htaccess::protect_page_dir();
		self::bust_disk_stats();
	}

	//remove the site page/ tree and drop-in bootstrap files
	public static function delete_all()
	{
		self::delete_tree(Config::cache_dir());
		self::delete_bootstrap_files();
		self::bust_disk_stats();
	}

	//true when this url already has a stored html file
	//$mobile null = desktop, and also mobile when separate mobile cache is on
	public static function is_cached($url, $mobile = null)
	{
		self::load_runtime();

		$path = self::storage_path_from_url($url);

		if($path === '') {
			return false;
		}

		$dir = Config::cache_dir();
		$suffix = self::query_suffix_from_url($url);

		if($mobile === true) {
			return self::file_ready(perfmatters_cache_file_path($dir, $path, $suffix, true));
		}

		if($mobile === false) {
			return self::file_ready(perfmatters_cache_file_path($dir, $path, $suffix, false));
		}

		if(!self::file_ready(perfmatters_cache_file_path($dir, $path, $suffix, false))) {
			return false;
		}

		if(empty(\Perfmatters\Config::$options['cache']['page_mobile'])) {
			return true;
		}

		return self::file_ready(perfmatters_cache_file_path($dir, $path, $suffix, true));
	}

	//q- suffix for allowed query args on this url (warmer/is_cached). ignore cookies — warmer fetches anonymously
	private static function query_suffix_from_url($url)
	{
		$query = wp_parse_url($url, PHP_URL_QUERY);

		if(!is_string($query) || $query === '') {
			return '';
		}

		$args = perfmatters_cache_query_args_from_string($query);

		if(!is_array($args) || empty($args)) {
			return '';
		}

		$kept = perfmatters_cache_filter_allowed_query_args($args, Config::ignored_queries(), Config::cache_queries());

		if(empty($kept)) {
			return '';
		}

		return 'q-' . md5(http_build_query($kept, '', '&', PHP_QUERY_RFC3986));
	}

	//one tree walk for page count + disk size. cached per request + short transient for status polls
	public static function disk_stats($fresh = false)
	{
		if(!$fresh && self::$disk_stats !== null) {
			return self::$disk_stats;
		}

		if(!$fresh) {
			$cached = get_transient(self::DISK_STATS_TRANSIENT);

			if(is_array($cached) && isset($cached['pages'], $cached['bytes'])) {
				self::$disk_stats = array(
					'pages' => (int) $cached['pages'],
					'bytes' => (int) $cached['bytes']
				);

				return self::$disk_stats;
			}
		}

		$stats = array(
			'pages' => 0,
			'bytes' => 0
		);

		$dirs = array();
		$root = Config::cache_dir();

		if(is_dir($root)) {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
				);

				foreach($iterator as $file) {
					if(!$file->isFile()) {
						continue;
					}

					$name = $file->getFilename();

					if(preg_match('/^(index|index-mobile|q-[a-f0-9]{32}(?:-mobile)?)\\.html(?:\\.gz)?$/', $name)) {
						$stats['bytes'] += (int) $file->getSize();
					}

					if($name === 'index.html' || $name === 'index.html.gz' || $name === 'index-mobile.html' || $name === 'index-mobile.html.gz') {
						$dirs[$file->getPath()] = true;
					}
				}
			}
			catch(\Exception $e) {
				//unreadable cache dir — leave zeros
			}
		}

		$stats['pages'] = count($dirs);
		self::$disk_stats = $stats;
		set_transient(self::DISK_STATS_TRANSIENT, $stats, self::DISK_STATS_TTL);

		return $stats;
	}

	//path under page/ for a full URL (home prefix stripped)
	private static function storage_path_from_url($url)
	{
		$host = wp_parse_url($url, PHP_URL_HOST);
		$path = wp_parse_url($url, PHP_URL_PATH);

		if(!is_string($host) || $host === '') {
			return '';
		}

		if(strtolower($host) !== Config::site_host()) {
			return '';
		}

		if(!is_string($path) || $path === '') {
			$path = '/';
		}

		return perfmatters_cache_strip_home_path($path, Config::home_path());
	}

	//page-config.php + runtime.php live beside host folders, not inside page/
	private static function delete_bootstrap_files()
	{
		$bootstrap = Config::bootstrap_dir();
		$files = array(
			$bootstrap . '/page-config.php',
			$bootstrap . '/runtime.php'
		);

		foreach($files as $file) {
			if(is_file($file)) {
				@unlink($file);
			}
		}
	}

	//after deletes so the next status read is not stale
	public static function bust_disk_stats()
	{
		self::$disk_stats = null;
		delete_transient(self::DISK_STATS_TRANSIENT);
	}

	private static function file_ready($file)
	{
		return $file !== '' && is_file($file) && filesize($file) > 0;
	}

	//delete specific pages, including their paginated files, and leave the rest of the cache
	public static function delete_urls($urls)
	{
		if(!is_array($urls) || empty($urls)) {
			return;
		}

		self::load_runtime();

		foreach($urls as $url) {
			self::delete_url($url);
		}

		self::bust_disk_stats();
	}

	//disk write gate — active drop-in already implies disk_enabled (no host / multisite)
	private static function can_store($html, $config = null)
	{
		if(!Dropin::is_active()) {
			return false;
		}

		return self::is_cacheable_response($html, $config);
	}

	//shared HTML cacheability for disk writes and Cloudflare CDN-Cache-Control (no drop-in / host checks)
	public static function is_cacheable_response($html, $config = null)
	{
		if(!is_string($html) || stripos($html, '<html') === false || stripos($html, '</body>') === false || stripos($html, '<xsl:stylesheet') !== false) {
			return false;
		}

		if(is_admin() || is_user_logged_in() || is_feed() || is_preview() || is_customize_preview() || is_robots() || is_trackback() || is_404() || is_search() || is_embed()) {
			return false;
		}

		if(wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
			return false;
		}

		if(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
			return false;
		}

		if(function_exists('post_password_required') && post_password_required()) {
			return false;
		}

		//cart/checkout/account stay dynamic; product/shop/category HTML is cached like Rocket / FlyingPress
		if(function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
			return false;
		}

		$status = http_response_code();

		if($status && (int) $status !== 200) {
			return false;
		}

		if(class_exists('\Perfmatters\Utilities')) {
			if(\Perfmatters\Utilities::is_dynamic_request() || \Perfmatters\Utilities::is_perfmatters_off() || \Perfmatters\Utilities::is_page_builder()) {
				return false;
			}
		}

		if(!is_array($config)) {
			self::load_runtime();
			$config = Config::to_file_array();
		}

		if(perfmatters_cache_request_is_blocked($config)) {
			return false;
		}

		//a static file cannot repeat Set-Cookie, and the html may belong to this visitor
		if(self::response_sets_cookie()) {
			return false;
		}

		//nocache_headers() does not define DONOTCACHEPAGE. private and no-store still mean this response
		if(self::response_is_private()) {
			return false;
		}

		return !apply_filters('perfmatters_cache_bypass', false, $html);
	}

	//true when the response sets a cookie that was not explicitly allowed
	private static function response_sets_cookie()
	{
		$allowed = apply_filters('perfmatters_cache_set_cookies', array());

		if(!is_array($allowed)) {
			$allowed = array();
		}

		foreach(headers_list() as $header) {
			if(stripos($header, 'set-cookie:') !== 0) {
				continue;
			}

			$value = trim(substr($header, 11));
			$name = strstr($value, '=', true);

			if(!is_string($name) || $name === '' || !self::cookie_prefix_matches($name, $allowed)) {
				return true;
			}
		}

		return false;
	}

	//true when this response asked not to be stored in a shared cache
	private static function response_is_private()
	{
		foreach(headers_list() as $header) {
			if(stripos($header, 'cache-control:') !== 0) {
				continue;
			}

			$value = strtolower(trim(substr($header, 14)));

			if(strpos($value, 'no-store') !== false || strpos($value, 'private') !== false) {
				return true;
			}
		}

		return false;
	}

	//allowed entries are cookie names or prefixes, same matching as the bypass list
	private static function cookie_prefix_matches($name, $prefixes)
	{
		foreach($prefixes as $prefix) {
			$prefix = (string) $prefix;

			if($prefix !== '' && stripos($name, $prefix) === 0) {
				return true;
			}
		}

		return false;
	}

	//realpath($path) when it resolves inside the cache root; false when missing or outside
	//$allow_root: delete_tree may clear the cache directory itself
	private static function resolve_under_cache($path, $allow_root = false)
	{
		$root = realpath(Config::cache_dir());
		$target = realpath($path);

		if($root === false || $target === false) {
			return false;
		}

		if($allow_root && $target === $root) {
			return $target;
		}

		$root_prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;

		if(strpos($target . DIRECTORY_SEPARATOR, $root_prefix) !== 0) {
			return false;
		}

		return $target;
	}

	//mkdir for cache paths before writing html
	private static function ensure_dir($directory)
	{
		if(!is_string($directory) || $directory === '') {
			return false;
		}

		if(!is_dir($directory) && !wp_mkdir_p($directory)) {
			return false;
		}

		return is_dir($directory);
	}

	//write the html file atomically inside the cache directory
	private static function write($file, $html)
	{
		$directory = dirname($file);

		if(!self::ensure_dir($directory)) {
			return false;
		}

		if(self::resolve_under_cache($directory) === false) {
			return false;
		}

		$tmp = $file . '.' . uniqid('', true) . '.tmp';

		if(file_put_contents($tmp, $html, LOCK_EX) === false) {
			return false;
		}

		if(!rename($tmp, $file)) {
			@unlink($tmp);
			return false;
		}

		//plain html stays readable on its own. gzip sibling is written unless filtered off
		if(substr($file, -3) !== '.gz') {
			self::write_gzip($file, $html);
			//warmer status polls stop on Idle — without a bust the last poll can freeze on a mid-run count
			self::bust_disk_stats();
		}

		return true;
	}

	//index.html.gz for browsers that ask for gzip. a failed encode drops any older copy
	private static function write_gzip($file, $html)
	{
		$gzip = $file . '.gz';

		if(!Config::is_gzip_enabled() || !function_exists('gzencode')) {
			self::unlink_cached($gzip);
			return;
		}

		$level = apply_filters('perfmatters_cache_gzip_level', 6);

		if(!is_numeric($level)) {
			$level = 6;
		}

		$level = (int) $level;

		if($level < 0 || $level > 9) {
			$level = 6;
		}

		$encoded = gzencode($html, $level);

		if(!is_string($encoded) || $encoded === '' || !self::write($gzip, $encoded)) {
			self::unlink_cached($gzip);
		}
	}

	//one cached url. numeric pagination is removed. a real child whose slug is the pagination base is kept
	private static function delete_url($url)
	{
		if(!is_string($url) || $url === '') {
			return;
		}

		$path = self::storage_path_from_url($url);

		if($path === '') {
			return;
		}

		$file = perfmatters_cache_file_path(Config::cache_dir(), $path);

		if($file === '') {
			return;
		}

		$directory = dirname($file);
		self::delete_file($file);
		self::delete_file(perfmatters_cache_file_path(Config::cache_dir(), $path, '', true));
		self::delete_query_variants($directory);
		self::delete_pagination($directory);
		self::delete_comment_pages($directory);
		self::prune_empty_dirs($directory, Config::cache_dir());
	}

	//q- files are separate copies for allowed query args on this same url
	private static function delete_query_variants($directory)
	{
		if(!is_dir($directory)) {
			return;
		}

		$matches = glob($directory . '/q-*.html');

		if(!is_array($matches)) {
			return;
		}

		foreach($matches as $match) {
			$base = basename($match);

			if(preg_match('/^q-[a-f0-9]{32}(-mobile)?\.html$/', $base)) {
				self::delete_file($match);
			}
		}
	}

	//unlink the html file and its gzip sibling
	private static function delete_file($file)
	{
		self::unlink_cached($file);
		self::unlink_cached($file . '.gz');
	}

	//unlink one file inside the cache root
	private static function unlink_cached($file)
	{
		if(!is_file($file)) {
			return;
		}

		$target = self::resolve_under_cache($file);

		if($target === false) {
			return;
		}

		@unlink($target);
	}

	//page 2 and onward live in /page/2/. do not remove /page/index.html, that can be a real page
	private static function delete_pagination($directory)
	{
		$base = 'page';
		global $wp_rewrite;

		if(is_object($wp_rewrite) && !empty($wp_rewrite->pagination_base)) {
			$candidate = (string) $wp_rewrite->pagination_base;

			if($candidate !== '' && $candidate !== '.' && $candidate !== '..' && strpos($candidate, '/') === false && strpos($candidate, '\\') === false) {
				$base = $candidate;
			}
		}

		$paged = $directory . '/' . $base;

		if(!is_dir($paged)) {
			return;
		}

		$target = self::resolve_under_cache($paged);

		if($target === false) {
			return;
		}

		$items = scandir($target);

		if(!is_array($items)) {
			return;
		}

		foreach($items as $item) {
			if($item === '.' || $item === '..' || !ctype_digit($item)) {
				continue;
			}

			self::delete_tree($target . DIRECTORY_SEPARATOR . $item);
		}

		$left = scandir($target);

		if(is_array($left) && count($left) === 2) {
			@rmdir($target);
		}
	}

	//pretty comment pagination is stored beside the post
	private static function delete_comment_pages($directory)
	{
		if(!is_dir($directory)) {
			return;
		}

		$target = self::resolve_under_cache($directory);

		if($target === false) {
			return;
		}

		$items = scandir($target);

		if(!is_array($items)) {
			return;
		}

		foreach($items as $item) {
			if($item === '.' || $item === '..' || strpos($item, 'comment-page-') !== 0) {
				continue;
			}

			$path = $target . DIRECTORY_SEPARATOR . $item;

			if(is_dir($path) && !is_link($path)) {
				self::delete_tree($path);
			}
			else {
				@unlink($path);
			}
		}
	}

	//remove directories that no longer hold a cached page. stop at this host
	private static function prune_empty_dirs($dir, $stop)
	{
		$stop_real = realpath($stop);

		while(is_dir($dir)) {
			$current = self::resolve_under_cache($dir);

			if($current === false) {
				return;
			}

			if($stop_real !== false && $current === $stop_real) {
				return;
			}

			$items = scandir($current);

			if(!is_array($items) || count($items) > 2) {
				return;
			}

			@rmdir($current);
			$dir = dirname($current);
		}
	}

	//unlink html files older than the lifespan, including gzip siblings
	public static function delete_expired($lifespan)
	{
		$lifespan = (int) $lifespan;

		if($lifespan < 1) {
			return;
		}

		$root = Config::cache_dir();

		if(!is_dir($root)) {
			return;
		}

		$limit = time() - $lifespan;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);

		foreach($iterator as $file) {
			if(!$file->isFile() || !preg_match('/\.html(\.gz)?$/', $file->getFilename())) {
				continue;
			}

			if($file->getMTime() >= $limit) {
				continue;
			}

			self::unlink_cached($file->getPathname());
		}

		self::bust_disk_stats();
	}

	//delete a directory we created. refuse anything outside the cache root
	public static function delete_tree($dir)
	{
		if(!is_dir($dir)) {
			return;
		}

		$target = self::resolve_under_cache($dir, true);

		if($target === false) {
			return;
		}

		$items = scandir($target);

		if(!is_array($items)) {
			return;
		}

		foreach($items as $item) {
			if($item === '.' || $item === '..') {
				continue;
			}

			$path = $target . DIRECTORY_SEPARATOR . $item;

			if(is_dir($path) && !is_link($path)) {
				self::delete_tree($path);
			}
			else {
				@unlink($path);
			}
		}

		@rmdir($target);
	}
}
