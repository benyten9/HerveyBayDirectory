<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class Config
{
	//initialize option hooks
	public static function init()
	{
		add_action('update_option_perfmatters_options', array(__CLASS__, 'options_updated'), 10, 2);
		//drop-in / htaccess read trailing_slash from page-config — refresh when permalinks change
		add_action('permalink_structure_changed', array(__CLASS__, 'permalink_structure_changed'));
	}

	//Settings → Permalinks: rewrite page-config + slash redirect rules when disk cache is on
	public static function permalink_structure_changed()
	{
		if(!\Perfmatters\Cache::disk_enabled()) {
			return;
		}

		self::write();
		Htaccess::update();
	}

	//null when plain permalinks (no slash policy); otherwise match permalink_structure
	public static function uses_trailing_slash()
	{
		$structure = (string) get_option('permalink_structure');

		if($structure === '') {
			return null;
		}

		return substr($structure, -1) === '/';
	}

	//run drop-in / warmer / Cloudflare side effects only when the cache slice changed
	public static function options_updated($old, $value)
	{
		$old_cache = (isset($old['cache']) && is_array($old['cache'])) ? $old['cache'] : array();

		if(!isset($value['cache']) || !is_array($value['cache'])) {
			return;
		}

		$new_cache = $value['cache'];

		//API token lives outside the cache option — a token-only save still needs CF sync
		if($old_cache === $new_cache) {
			if(Cloudflare::token_was_updated()) {
				\Perfmatters\Config::$options['cache'] = $new_cache;
				Cloudflare::after_settings_save($old_cache, $new_cache);
			}

			return;
		}

		self::updated($old_cache, $new_cache);
	}

	//Cache Expires presets. keys are TTL seconds; '' means age-based expiry is off
	public static function cache_ttl_choices()
	{
		return array(
			'' => __('Never', 'perfmatters') . ' (' . __('Default', 'perfmatters') . ')',
			(2 * HOUR_IN_SECONDS) => __('2 hours', 'perfmatters'),
			(6 * HOUR_IN_SECONDS) => __('6 hours', 'perfmatters'),
			(12 * HOUR_IN_SECONDS) => __('12 hours', 'perfmatters'),
			DAY_IN_SECONDS => __('24 hours', 'perfmatters'),
			WEEK_IN_SECONDS => __('7 days', 'perfmatters'),
			(30 * DAY_IN_SECONDS) => __('30 days', 'perfmatters'),
			YEAR_IN_SECONDS => __('1 year', 'perfmatters')
		);
	}

	//public custom post types the warmer can expose as their own sources
	public static function warmer_post_types()
	{
		$types = get_post_types(array(
			'public' => true,
			'_builtin' => false
		), 'objects');

		//variations are not standalone URLs; products are warmed like other public CPTs
		unset($types['product_variation']);

		return $types;
	}

	//pre-compressed html files. filter off to leave plain files for the host or CDN
	public static function is_gzip_enabled()
	{
		return (bool) apply_filters('perfmatters_cache_gzip', true);
	}

	//shared root next to host folders: page-config.php + runtime.php for the drop-in
	//parent of every PERFMATTERS_CACHE_DIR site folder (wp-content/cache/perfmatters)
	public static function bootstrap_dir()
	{
		$path = trim(str_replace('\\', '/', (string) apply_filters('perfmatters_cache_path', 'cache')), '/');

		if($path === '') {
			$path = 'cache';
		}

		return rtrim(WP_CONTENT_DIR . '/' . $path . '/perfmatters', '/\\');
	}

	//same host+path segment PERFMATTERS_CACHE_DIR uses (get_site_url). may contain slashes (/blog)
	public static function site_cache_segment()
	{
		$parsed = wp_parse_url(get_site_url());
		$host = (isset($parsed['host']) ? $parsed['host'] : '') . (isset($parsed['path']) ? $parsed['path'] : '');

		return is_string($host) ? $host : '';
	}

	//html files only — sibling of minify/css/fonts
	public static function cache_dir()
	{
		//prefer the main plugin constant when plugins have loaded (subdir-safe: constant already includes host+path)
		if(defined('PERFMATTERS_CACHE_DIR')) {
			$dir = rtrim(PERFMATTERS_CACHE_DIR, '/\\') . '/page';
		}
		else {
			$segment = self::site_cache_segment();
			$dir = self::bootstrap_dir() . ($segment !== '' ? '/' . $segment : '') . '/page';
		}

		return rtrim((string) apply_filters('perfmatters_cache_dir', $dir), '/\\');
	}

	//request host that may read/write this site's page files
	public static function site_host()
	{
		$host = wp_parse_url(home_url(), PHP_URL_HOST);

		return is_string($host) ? strtolower($host) : '';
	}

	//home URL path without trailing slash (/blog). empty when WordPress is at domain root
	public static function home_path()
	{
		$path = wp_parse_url(home_url('/'), PHP_URL_PATH);

		if(!is_string($path) || $path === '' || $path === '/') {
			return '';
		}

		return untrailingslashit($path);
	}

	//query args that reuse the cached file for the path
	public static function ignored_queries()
	{
		$ignored = array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'utm_id',
			'fbclid',
			'gclid',
			'gbraid',
			'wbraid',
			'msclkid',
			'mc_cid',
			'mc_eid',
			'_ga',
			'_gl',
			'gad_source',
			'gad_campaignid',
			'ref',
			'yclid',
			'perfmatters_warmer'
		);

		$ignored = apply_filters('perfmatters_cache_ignored_queries', $ignored);

		return is_array($ignored) ? array_values($ignored) : array();
	}

	//built-in prefixes plus the ones saved on the settings screen
	public static function cookie_prefixes($options = null)
	{
		$cookies = array(
			'wordpress_logged_in_',
			'wp-postpass_',
			'comment_author_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			//session exists before cart cookies; skip so personalized HTML is not stored or served
			'wp_woocommerce_session_'
		);

		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		if(!empty($options['bypass_cookies']) && is_array($options['bypass_cookies'])) {
			$cookies = array_merge($cookies, $options['bypass_cookies']);
		}

		$merged = $cookies;
		$cookies = apply_filters('perfmatters_cache_bypass_cookies', $cookies);

		if(!is_array($cookies)) {
			$cookies = $merged;
		}

		$prefixes = array();

		foreach($cookies as $prefix) {
			$prefix = trim((string) $prefix);

			if($prefix !== '') {
				$prefixes[] = $prefix;
			}
		}

		return array_values(array_unique($prefixes));
	}

	//paths and urls that are purged with every post, limited to this site
	public static function purge_urls($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		$lines = (!empty($options['purge_urls']) && is_array($options['purge_urls'])) ? $options['purge_urls'] : array();
		$urls = array();

		foreach($lines as $line) {
			$line = trim((string) $line);

			if($line === '') {
				continue;
			}

			if(strpos($line, '://') !== false) {
				$urls[] = $line;
			}
			else {
				$urls[] = home_url($line);
			}
		}

		$host = self::site_host();

		return array_values(array_filter(Warmer::normalize_urls($urls), function($url) use ($host) {
			return strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $host;
		}));
	}

	//user agent fragments that skip the cache
	public static function bypass_agents()
	{
		$agents = apply_filters('perfmatters_cache_bypass_agents', array());

		if(!is_array($agents)) {
			return array();
		}

		$kept = array();

		foreach($agents as $agent) {
			$agent = trim((string) $agent);

			if($agent !== '') {
				$kept[] = $agent;
			}
		}

		return array_values(array_unique($kept));
	}

	//parts for the Pages cached card meta row (situational status only; size is separate)
	public static function pages_cached_meta_parts($page_count)
	{
		$enabled = \Perfmatters\Cache::disk_enabled();

		if(!$enabled && $page_count > 0) {
			return array(
				'status' => __('Files still on disk', 'perfmatters')
			);
		}

		if($enabled && $page_count < 1) {
			return array(
				'status' => __('Nothing stored yet', 'perfmatters')
			);
		}

		return array(
			'status' => ''
		);
	}

	//effective Cache Expires in seconds. 0 means never expire by age
	public static function cache_ttl($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		$expires = '';

		if(isset($options['page_ttl']) && $options['page_ttl'] !== '') {
			$expires = is_numeric($options['page_ttl']) ? (int) $options['page_ttl'] : $options['page_ttl'];
		}

		$expires = apply_filters('perfmatters_cache_page_ttl', $expires);

		if(!is_numeric($expires)) {
			return 0;
		}

		$expires = (int) $expires;

		return $expires < 0 ? 0 : $expires;
	}

	//cookie names whose values get their own file. bypass cookies are left out
	public static function vary_cookies($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		$names = apply_filters('perfmatters_cache_vary_cookies', array());

		if(!is_array($names)) {
			return array();
		}

		$prefixes = self::cookie_prefixes($options);
		$kept = array();

		foreach($names as $name) {
			$name = trim((string) $name);

			if($name === '') {
				continue;
			}

			foreach($prefixes as $prefix) {
				if($prefix !== '' && stripos($name, $prefix) === 0) {
					continue 2;
				}
			}

			$kept[] = $name;
		}

		$kept = array_values(array_unique($kept));
		sort($kept, SORT_STRING);

		return $kept;
	}

	//parameter names that are stored as their own file. other args still skip the cache
	public static function cache_queries($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		$names = (!empty($options['query_strings']) && is_array($options['query_strings'])) ? $options['query_strings'] : array();
		$names = apply_filters('perfmatters_cache_query_strings', $names);

		if(!is_array($names)) {
			return array();
		}

		$kept = array();

		foreach($names as $name) {
			$name = trim((string) $name);

			if($name !== '') {
				$kept[] = $name;
			}
		}

		return array_values(array_unique($kept));
	}

	//user exclusions plus the commerce paths that are never shared
	public static function excluded_paths($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		$paths = (!empty($options['excluded_urls']) && is_array($options['excluded_urls'])) ? $options['excluded_urls'] : array();

		foreach(array('cart', 'checkout', 'myaccount') as $page) {
			$slug = self::woocommerce_slug($page);

			if($slug !== '') {
				$paths[] = '/' . $slug . '/';
			}
		}

		if(empty($paths) && class_exists('WooCommerce')) {
			$paths = array('/cart/', '/checkout/', '/my-account/');
		}

		return array_values(array_unique($paths));
	}

	//config array read by the drop-in
	public static function to_file_array($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		//only bless the edge when credentials exist — orphan Cache Rules must not keep getting CDN-Cache-Control
		$cloudflare = !empty($options['cloudflare']) && Cloudflare::has_token();

		return array(
			'page' => !empty($options['page']),
			'page_mobile' => !empty($options['page_mobile']),
			//drop-in / htaccess HIT paths need this — plugins are not loaded yet
			'cloudflare' => $cloudflare,
			'cf_edge_ttl' => $cloudflare ? Cloudflare::edge_ttl() : 0,
			'gzip' => self::is_gzip_enabled(),
			'cache_dir' => self::cache_dir(),
			'host' => self::site_host(),
			'home_path' => self::home_path(),
			//null = plain permalinks (skip slash gate); bool = pretty permalink slash policy
			'trailing_slash' => self::uses_trailing_slash(),
			'excluded' => self::excluded_paths($options),
			'cookies' => self::cookie_prefixes($options),
			'ignored_queries' => self::ignored_queries(),
			'query_strings' => self::cache_queries($options),
			'bypass_agents' => self::bypass_agents(),
			'vary_cookies' => self::vary_cookies($options),
			'page_ttl' => self::cache_ttl($options)
		);
	}

	//drop-in bootstrap file (outside the per-site page/ html tree)
	public static function config_file()
	{
		return self::bootstrap_dir() . '/page-config.php';
	}

	//write the drop-in config (settings save, install/sync, uninstall). not on the request hot path
	public static function write($options = null)
	{
		$bootstrap = self::bootstrap_dir();
		$dir = self::cache_dir();

		if((!is_dir($bootstrap) && !wp_mkdir_p($bootstrap)) || (!is_dir($dir) && !wp_mkdir_p($dir))) {
			return false;
		}

		$file = self::config_file();
		$next = self::to_file_array($options);
		$previous = self::read_file_array($file);
		$previous_cookies = (isset($previous['vary_cookies']) && is_array($previous['vary_cookies'])) ? $previous['vary_cookies'] : array();
		$previous_mobile = !empty($previous['page_mobile']);
		$previous_dir = isset($previous['cache_dir']) ? (string) $previous['cache_dir'] : '';

		//a stored page may already contain html rendered with the old cookie, device, or path layout
		if(is_file($file) && (
			$previous_cookies !== $next['vary_cookies']
			|| $previous_mobile !== !empty($next['page_mobile'])
			|| ($previous_dir !== '' && $previous_dir !== $next['cache_dir'])
		)) {
			Purge::clear();
		}

		$contents = "<?php\nif(!defined('ABSPATH')) {\n\texit;\n}\n\nreturn " . var_export($next, true) . ";\n";

		return self::put($file, $contents);
	}

	//write a disabled config so a leftover drop-in does not serve files
	public static function write_disabled()
	{
		$options = \Perfmatters\Config::$options['cache'] ?? array();
		unset($options['page']);

		return self::write($options);
	}

	//sanitize the settings screen (CF token peels to its own option; rest mirrors other sections)
	public static function sanitize($input)
	{
		$input = is_array($input) ? $input : array();

		//password field name only — blank keeps Cloudflare::TOKEN_OPTION; a new value replaces it
		//wp-config PERFMATTERS_CLOUDFLARE_API_TOKEN wins; ignore posted values when it is defined
		$incoming_token = isset($input['cf_api_token']) ? trim((string) $input['cf_api_token']) : '';

		if($incoming_token !== '' && !Cloudflare::token_from_constant()) {
			Cloudflare::store_token($incoming_token);
		}

		unset($input['cf_api_token']);

		//Edge Cache Expires select — '' is the 30-day default; unknown values reset to ''
		if(array_key_exists('cf_edge_ttl', $input)) {
			if($input['cf_edge_ttl'] === '' || $input['cf_edge_ttl'] === null) {
				$input['cf_edge_ttl'] = '';
			}
			else {
				$ttl = is_numeric($input['cf_edge_ttl']) ? (int) $input['cf_edge_ttl'] : 0;
				$choices = Cloudflare::edge_ttl_choices();
				$input['cf_edge_ttl'] = ($ttl > 0 && isset($choices[$ttl])) ? $ttl : '';
			}
		}

		//AJAX saves skip perfmatters_sanitize_options — still turn textareas into line arrays here
		foreach(array('excluded_urls', 'bypass_cookies', 'purge_urls', 'query_strings') as $key) {
			if(!empty($input[$key]) && function_exists('perfmatters_sanitize_one_per_line')) {
				perfmatters_sanitize_one_per_line($input[$key]);
			}
		}

		//unchecked boxes omit the key — store [] so a later migrator does not treat it as "never set"
		if(!array_key_exists('warmer_sources', $input) || !is_array($input['warmer_sources'])) {
			$input['warmer_sources'] = array();
		}

		//host / multisite / foreign drop-in — disk cannot run; clear disk fields on save
		if(!\Perfmatters\Cache::disk_available()) {
			unset($input['page'], $input['page_mobile'], $input['page_ttl'], $input['query_strings']);
		}

		return $input;
	}

	//install or remove the drop-in when the toggle changes. warmer is independent of disk cache
	public static function updated($old, $value)
	{
		//settings save still has the pre-save Config::$options in memory; Htaccess / Dropin read that
		\Perfmatters\Config::$options['cache'] = $value;

		$page_on = !empty($value['page']);
		$page_was_on = !empty($old['page']);

		//only tear down when page turns off — not on every cache-slice change while already off
		//(e.g. 2.7.0 warmer_sources migration must not mkdir / create the warmer table fleet-wide)
		if(!$page_on) {
			if($page_was_on) {
				Purge::unschedule_expire();
				Dropin::uninstall();
				Storage::delete_all();

				if(empty($value['warmer'])) {
					Warmer::cancel();
					Queue::clear_rows();
				}
			}
		}
		elseif(!\Perfmatters\Cache::disk_enabled($value)) {
			//host page cache or multisite soft-lock — never install the drop-in
			\Perfmatters\Cache::stand_aside();
		}
		else {
			$result = Dropin::install($value);

			if(is_wp_error($result)) {
				add_settings_error('perfmatters_cache', $result->get_error_code(), $result->get_error_message(), 'error');
				self::sync_warmer($old, $value);
				Cloudflare::after_settings_save($old, $value);
				return;
			}

			Dropin::mark_files_current();

			//these rules decide which stored pages are still safe to serve
			$rules_changed = (($old['excluded_urls'] ?? array()) !== ($value['excluded_urls'] ?? array()))
				|| (($old['bypass_cookies'] ?? array()) !== ($value['bypass_cookies'] ?? array()))
				|| (!empty($old['page_mobile']) !== !empty($value['page_mobile']));

			if($rules_changed) {
				Purge::clear();
			}
		}

		self::sync_warmer($old, $value);

		//edge rules sync after disk/warmer so a failed drop-in still updates Cloudflare status
		Cloudflare::after_settings_save($old, $value);
	}

	//schedule and start/stop the crawler after any settings save
	private static function sync_warmer($old, $value)
	{
		if(empty($value['warmer'])) {
			//already off — skip cancel (Queue::release_in_progress installs the warmer table)
			if(!empty($old['warmer'])) {
				Warmer::cancel();
				Warmer::unschedule_recurring();
			}

			return;
		}

		Warmer::schedule_recurring();

		$sources_changed = ($old['warmer_sources'] ?? array()) !== ($value['warmer_sources'] ?? array());
		$mobile_changed = (!empty($old['page_mobile']) !== !empty($value['page_mobile']));

		if(empty($old['warmer']) || $sources_changed || $mobile_changed) {
			Warmer::start();
		}
	}

	//woocommerce page slug, available before rewrite rules are ready
	private static function woocommerce_slug($page)
	{
		if(!function_exists('wc_get_page_id')) {
			return '';
		}

		$page_id = wc_get_page_id($page);

		if($page_id < 1) {
			return '';
		}

		$slug = get_post_field('post_name', $page_id);

		return is_string($slug) ? $slug : '';
	}

	//config array already on disk, or an empty array when the file is missing
	private static function read_file_array($file)
	{
		if(!is_file($file)) {
			return array();
		}

		$loaded = include $file;

		return is_array($loaded) ? $loaded : array();
	}

	//write only when the file changed, then drop it from opcache
	private static function put($file, $contents)
	{
		if(is_file($file) && file_get_contents($file) === $contents) {
			return true;
		}

		$written = file_put_contents($file, $contents, LOCK_EX);

		if($written === false) {
			return false;
		}

		if(function_exists('opcache_invalidate')) {
			opcache_invalidate($file, true);
		}

		return true;
	}
}
