<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class Dropin
{
	const MARKER = 'Perfmatters Cache Drop-in';
	const CONFIG_LINE = "define('WP_CACHE', true); // Added by Perfmatters Cache";

	//memoized is_ours() for this request; cleared on install/uninstall
	private static $ours = null;

	//re-sync when the plugin version bumps (byte checks are settings-only — see needs_resync())
	//Cache::init() only calls this when disk is already enabled
	public static function maybe_upgrade()
	{
		if(get_option('perfmatters_cache_files_version', '') === PERFMATTERS_VERSION) {
			return true;
		}

		$result = self::sync();

		//leave the stamp alone on failure so the next request can retry
		if($result === true) {
			self::mark_files_current();
		}

		return $result;
	}

	public static function mark_files_current()
	{
		update_option('perfmatters_cache_files_version', PERFMATTERS_VERSION, false);
	}

	//true when drop-in, runtime, page-config contents, or WP_CACHE need reinstall
	//used on the Cache settings screen only — not the front-end hot path
	public static function needs_resync()
	{
		//prefer the live constant; fall back to wp-config on disk when opcache has not
		//revalidated yet after enable_constant() (forced reload right after turning page on)
		if(!defined('WP_CACHE') || !WP_CACHE) {
			if(!self::wp_config_defines_cache()) {
				return true;
			}
		}

		if(!self::files_match()) {
			return true;
		}

		//filter / option bake drift (browser_ttl, cookies, path, etc.) — same Reinstall path
		return Config::is_stale();
	}

	//manual Reinstall from Cache settings (data-pm-action)
	public static function ajax_sync_files()
	{
		\Perfmatters\Ajax::security_check();

		if(function_exists('perfmatters_network_access') && !perfmatters_network_access()) {
			wp_send_json_error(array(
				'message' => __('Permission denied.', 'perfmatters')
			));
		}

		if(!\Perfmatters\Cache::disk_enabled()) {
			wp_send_json_error(array(
				'message' => __('Enable page cache and save before reinstalling cache files.', 'perfmatters')
			));
		}

		if(is_file(self::dropin_path()) && !self::is_ours()) {
			wp_send_json_error(array(
				'message' => __('wp-content/advanced-cache.php already belongs to another plugin, so Perfmatters Cache cannot install its drop-in.', 'perfmatters')
			));
		}

		$result = self::sync();

		if(is_wp_error($result)) {
			wp_send_json_error(array(
				'message' => $result->get_error_message()
			));
		}

		self::mark_files_current();

		wp_send_json_success(array(
			'message' => __('Page cache files reinstalled.', 'perfmatters'),
			'reload' => true
		));
	}

	//refresh copied files when the helper changes. leave wp-config alone unless the constant is missing
	//foreign drop-in is refused inside install() when we need to rewrite advanced-cache.php
	public static function sync()
	{
		$matches = self::files_match();

		if($matches && defined('WP_CACHE') && WP_CACHE) {
			//version bump with unchanged drop-in sources: refresh page-config + browser-cache rules once
			Config::write();
			Htaccess::update();
			return true;
		}

		$result = self::install();

		//a changed drop-in can point at different files, so drop the previous html
		if($result === true && !$matches) {
			Purge::clear();
		}

		return $result;
	}

	//copy the drop-in, write config, and define WP_CACHE
	public static function install($options = null)
	{
		if(is_multisite()) {
			return new \WP_Error(
				'perfmatters_cache_multisite',
				__('Page cache is not available on multisite during beta.', 'perfmatters')
			);
		}

		if(!self::ensure_directory()) {
			return new \WP_Error(
				'perfmatters_cache_directory',
				__('Page cache could not create its cache directories under wp-content/cache/perfmatters. Check that wp-content/cache is writable.', 'perfmatters')
			);
		}

		if(is_file(self::dropin_path()) && !self::is_ours()) {
			return new \WP_Error(
				'perfmatters_cache_dropin_exists',
				__('Page cache could not be installed. wp-content/advanced-cache.php already belongs to another plugin.', 'perfmatters')
			);
		}

		if(!self::copy(PERFMATTERS_PATH . 'inc/cache-runtime.php', self::runtime_path())) {
			return new \WP_Error(
				'perfmatters_cache_runtime',
				__('Page cache could not write its runtime file.', 'perfmatters')
			);
		}

		if(!Config::write($options)) {
			return new \WP_Error(
				'perfmatters_cache_config',
				__('Page cache could not write its config file.', 'perfmatters')
			);
		}

		if(!self::write_advanced_cache()) {
			self::$ours = null;
			return new \WP_Error(
				'perfmatters_cache_dropin',
				__('Page cache could not write wp-content/advanced-cache.php.', 'perfmatters')
			);
		}

		self::$ours = true;

		$constant = self::enable_constant();

		if(is_wp_error($constant)) {
			return $constant;
		}

		//root .htaccess expires + gzip for static assets (separate from page html.gz)
		Htaccess::update();

		return true;
	}

	//remove only files this helper wrote (HTML tree is Storage::delete_all)
	public static function uninstall()
	{
		Config::write_disabled();
		Htaccess::remove();

		if(self::is_ours()) {
			@unlink(self::dropin_path());
		}

		@unlink(self::runtime_path());

		self::$ours = false;
		self::disable_constant();
		delete_option('perfmatters_cache_files_version');
	}

	//true when the installed drop-in came from this helper
	public static function is_ours()
	{
		if(self::$ours !== null) {
			return self::$ours;
		}

		$path = self::dropin_path();

		if(!is_file($path)) {
			self::$ours = false;
			return false;
		}

		$contents = file_get_contents($path);
		self::$ours = is_string($contents) && strpos($contents, self::MARKER) !== false;

		return self::$ours;
	}

	//drop-in is loaded and disk page cache is actually allowed to run
	public static function is_active()
	{
		return \Perfmatters\Cache::disk_enabled() && self::is_ours() && defined('WP_CACHE') && WP_CACHE;
	}

	//create bootstrap + page/ dirs and keep html from being served directly
	//rewrites deny/.index on every install so Reinstall / version sync pick up rule changes
	//bootstrap must NOT deny-all: it parents {host}/minify|css|fonts. page/ may open later for Apache rewrite
	private static function ensure_directory()
	{
		$bootstrap = Config::bootstrap_dir();
		$dir = Config::cache_dir();

		if((!is_dir($bootstrap) && !wp_mkdir_p($bootstrap)) || (!is_dir($dir) && !wp_mkdir_p($dir))) {
			return false;
		}

		if(!is_writable($bootstrap) || !is_writable($dir)) {
			return false;
		}

		$index = "<?php\n// Silence is golden.\n";

		if(!self::protect_bootstrap() || !self::write($dir . '/.htaccess', Htaccess::deny_http_rules()) || !self::write($dir . '/index.php', $index)) {
			return false;
		}

		return true;
	}

	//indexes off + HTTP block for page-config/runtime only (never deny-all — minify/css/fonts live under this tree)
	private static function protect_bootstrap()
	{
		$bootstrap = Config::bootstrap_dir();

		if(!is_dir($bootstrap) || !is_writable($bootstrap)) {
			return false;
		}

		$rules = "Options -Indexes\n"
			. "<FilesMatch \"^(page-config|runtime)\\.php$\">\n"
			. "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n"
			. "\t<IfModule !mod_authz_core.c>\n\t\tDeny from all\n\t</IfModule>\n"
			. "</FilesMatch>\n";

		return self::write($bootstrap . '/.htaccess', $rules)
			&& self::write($bootstrap . '/index.php', "<?php\n// Silence is golden.\n");
	}

	//copied files differ from the helper, or have not been copied yet
	private static function files_match()
	{
		$runtime_source = PERFMATTERS_PATH . 'inc/cache-runtime.php';
		$dropin = self::dropin_path();
		$runtime = self::runtime_path();
		$expected_dropin = self::advanced_cache_contents();

		if($expected_dropin === '' || !is_file($runtime_source) || !is_file($dropin) || !is_file($runtime)) {
			return false;
		}

		if(file_get_contents($dropin) !== $expected_dropin) {
			return false;
		}

		if(file_get_contents($runtime_source) !== file_get_contents($runtime)) {
			return false;
		}

		return is_file(Config::config_file());
	}

	//source advanced-cache.php with bootstrap path baked for this site (perfmatters_cache_path)
	//anchor on // PERFMATTERS_CACHE_BOOTSTRAP so the default RHS can change without breaking install
	private static function advanced_cache_contents()
	{
		$source = PERFMATTERS_PATH . 'inc/advanced-cache.php';

		if(!is_file($source)) {
			return '';
		}

		$contents = file_get_contents($source);

		if($contents === false) {
			return '';
		}

		$replacement = '$perfmatters_cache_bootstrap = ' . var_export(Config::bootstrap_dir(), true) . '; // PERFMATTERS_CACHE_BOOTSTRAP';
		$updated = preg_replace(
			'/^\$perfmatters_cache_bootstrap\s*=\s*.+?;\s*\/\/\s*PERFMATTERS_CACHE_BOOTSTRAP\s*$/m',
			$replacement,
			$contents,
			1,
			$count
		);

		if(!is_string($updated) || (int) $count !== 1) {
			return '';
		}

		return $updated;
	}

	//install advanced-cache.php with the resolved bootstrap directory
	private static function write_advanced_cache()
	{
		$contents = self::advanced_cache_contents();

		if($contents === '') {
			return false;
		}

		return self::write(self::dropin_path(), $contents);
	}

	//copy a helper file into place and clear opcache so the next request sees it
	private static function copy($source, $destination)
	{
		if(!is_file($source)) {
			return false;
		}

		$contents = file_get_contents($source);

		if($contents === false) {
			return false;
		}

		return self::write($destination, $contents);
	}

	private static function write($destination, $contents)
	{
		if(is_file($destination) && file_get_contents($destination) === $contents) {
			return true;
		}

		$dir = dirname($destination);

		if(!is_dir($dir) && !wp_mkdir_p($dir)) {
			return false;
		}

		$written = file_put_contents($destination, $contents, LOCK_EX);

		if($written === false) {
			return false;
		}

		self::invalidate_opcache($destination);

		return true;
	}

	//define WP_CACHE in wp-config.php. written in place so a temp copy of wp-config is never left on disk
	private static function enable_constant()
	{
		if(defined('WP_CACHE') && WP_CACHE) {
			return true;
		}

		$path = self::wp_config_path();

		if($path === '' || !is_readable($path)) {
			return new \WP_Error(
				'perfmatters_cache_wp_config',
				__('Page cache could not find wp-config.php. Add define(\'WP_CACHE\', true); above the stop editing line.', 'perfmatters')
			);
		}

		if(!is_writable($path)) {
			return new \WP_Error(
				'perfmatters_cache_wp_config',
				__('wp-config.php is not writable. Add define(\'WP_CACHE\', true); above the stop editing line.', 'perfmatters')
			);
		}

		$contents = file_get_contents($path);

		if(!is_string($contents)) {
			return new \WP_Error(
				'perfmatters_cache_wp_config',
				__('Page cache could not read wp-config.php.', 'perfmatters')
			);
		}

		if(strpos($contents, self::CONFIG_LINE) !== false) {
			return true;
		}

		if(preg_match('/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)/', $contents)) {
			return true;
		}

		if(preg_match('/define\s*\(\s*[\'"]WP_CACHE[\'"]/', $contents)) {
			return new \WP_Error(
				'perfmatters_cache_wp_config',
				__('WP_CACHE is already defined in wp-config.php. Set it to true so the page cache can load.', 'perfmatters')
			);
		}

		$insertion = self::CONFIG_LINE . "\n\n";
		$anchor = "/* That's all, stop editing!";
		$updated = $contents;

		if(strpos($contents, $anchor) !== false) {
			$updated = str_replace($anchor, $insertion . $anchor, $contents);
		}
		elseif(preg_match('/require(?:_once)?\s*\(?\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]/', $contents, $match, PREG_OFFSET_CAPTURE)) {
			$position = $match[0][1];
			$updated = substr($contents, 0, $position) . $insertion . substr($contents, $position);
		}
		else {
			return new \WP_Error(
				'perfmatters_cache_wp_config',
				__('Page cache could not find a safe place to define WP_CACHE in wp-config.php.', 'perfmatters')
			);
		}

		if(file_put_contents($path, $updated, LOCK_EX) === false) {
			return new \WP_Error(
				'perfmatters_cache_wp_config',
				__('Page cache could not update wp-config.php.', 'perfmatters')
			);
		}

		//same as write() for drop-in/runtime — otherwise the next request can still see a stale
		//opcached wp-config (revalidate_freq) and needs_resync() thinks WP_CACHE is missing
		self::invalidate_opcache($path);

		return true;
	}

	//remove only the line this helper added
	private static function disable_constant()
	{
		$path = self::wp_config_path();

		if($path === '' || !is_readable($path) || !is_writable($path)) {
			return;
		}

		$contents = file_get_contents($path);

		if(!is_string($contents) || strpos($contents, self::CONFIG_LINE) === false) {
			return;
		}

		$updated = str_replace(array(self::CONFIG_LINE . "\n\n", self::CONFIG_LINE . "\n", self::CONFIG_LINE), '', $contents);

		if($updated !== $contents && file_put_contents($path, $updated, LOCK_EX) !== false) {
			self::invalidate_opcache($path);
		}
	}

	//force PHP to re-read a file we just wrote (drop-in, runtime, or wp-config)
	private static function invalidate_opcache($path)
	{
		if(function_exists('opcache_invalidate')) {
			opcache_invalidate($path, true);
		}
	}

	//wp-config.php lives in ABSPATH, or one directory above on some installs
	private static function wp_config_path()
	{
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname(ABSPATH) . '/wp-config.php'
		);

		foreach($candidates as $path) {
			if(is_file($path)) {
				return $path;
			}
		}

		return '';
	}

	//true when wp-config already has WP_CACHE true (our line or an existing define)
	private static function wp_config_defines_cache()
	{
		$path = self::wp_config_path();

		if($path === '' || !is_readable($path)) {
			return false;
		}

		$contents = file_get_contents($path);

		if(!is_string($contents)) {
			return false;
		}

		if(strpos($contents, self::CONFIG_LINE) !== false) {
			return true;
		}

		return (bool) preg_match('/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)/', $contents);
	}

	private static function dropin_path()
	{
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	private static function runtime_path()
	{
		return Config::bootstrap_dir() . '/runtime.php';
	}
}
