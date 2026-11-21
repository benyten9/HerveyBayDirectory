<?php
namespace Perfmatters;

if(!defined('ABSPATH')) {
	exit;
}

use Perfmatters\Cache\Cloudflare;
use Perfmatters\Cache\Config;
use Perfmatters\Cache\Dropin;
use Perfmatters\Cache\Purge;
use Perfmatters\Cache\Queue;
use Perfmatters\Cache\Storage;
use Perfmatters\Cache\Warmer;
use WP_Admin_Bar;

class Cache
{
	private static $notice = '';

	//memoized detected_host_cache() for this request
	private static $detected_host = null;

	//boot the helper
	public static function init()
	{
		Config::init();

		add_action('admin_notices', array(__CLASS__, 'admin_notices'));
		//available even when the warmer toggle is off so source badges can be scanned first
		add_action('wp_ajax_perfmatters_scan_warmer_sources', array(Warmer::class, 'scan_sources'));
		add_action('wp_ajax_perfmatters_sync_cache_files', array(Dropin::class, 'ajax_sync_files'));

		$cache = \Perfmatters\Config::$options['cache'] ?? array();
		$warmer = !empty($cache['warmer']);
		$cf = !empty($cache['cloudflare']);
		$cf_ready = false;
		$disk = self::disk_enabled($cache);

		//avoid autoloading Cloudflare.php on the hot path when CF is unused
		if($cf || defined('PERFMATTERS_CLOUDFLARE_API_TOKEN')) {
			//wp-config PERFMATTERS_CLOUDFLARE_API_TOKEN → remove any UI-saved token from the DB
			Cloudflare::sync_constant_token();

			if($cf) {
				$cf_ready = Cloudflare::is_ready($cache);
				Cloudflare::init();
			}
		}

		//Clear only when disk is serving or CF can actually purge (toggle alone is not enough)
		if($disk || $cf_ready) {
			add_action('wp_ajax_perfmatters_cache_clear', array(Purge::class, 'ajax_clear'));
			add_action('admin_post_perfmatters_cache_clear', array(Purge::class, 'handle_clear'));
			add_action('admin_post_perfmatters_cache_clear_url', array(Purge::class, 'handle_clear_url'));
		}

		//warmer visits urls for any cache layer, including when we step aside on Kinsta
		if($warmer) {
			Warmer::init();
		}
		//string hook name — do not touch Warmer::RECURRING (autoloads Warmer.php) unless a leftover exists
		elseif(wp_next_scheduled('perfmatters_cache_warmer_cron')) {
			Warmer::unschedule_recurring();
		}

		//page on but disk blocked (host / multisite) — clean leftover drop-in/files if present
		if(!empty($cache['page']) && !$disk) {
			self::stand_aside();
		}

		$serving = false;

		if(!$disk) {
			if(wp_next_scheduled('perfmatters_cache_expire')) {
				Purge::unschedule_expire();
			}
		}
		else {
			//only copies drop-in / refreshes .htaccess when PERFMATTERS_VERSION changes
			$result = Dropin::maybe_upgrade();

			if(is_wp_error($result)) {
				self::$notice = $result->get_error_message();
			}

			if(Dropin::is_active()) {
				//late, so the stored file includes the other output-buffer optimizations
				add_filter('perfmatters_output_buffer', array(Storage::class, 'capture'), PHP_INT_MAX);
				//page-config.php is written from Dropin::install / sync / uninstall — not every request
				$serving = true;
			}
		}

		//content edits / admin bar when disk is serving, warmer is on, or CF can purge
		if($serving || $warmer || $cf_ready) {
			Purge::init();
			add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_menu'), 501);
		}
	}

	//admin bar clear, attached to Perfmatters when that menu exists
	public static function admin_bar_menu(WP_Admin_Bar $wp_admin_bar)
	{
		if(!current_user_can('manage_options') || (function_exists('perfmatters_network_access') && !perfmatters_network_access())) {
			return;
		}

		$can_clear = self::disk_enabled() || Cloudflare::is_ready();

		if(!$can_clear) {
			return;
		}

		$parent = $wp_admin_bar->get_node('perfmatters') ? 'perfmatters' : 'perfmatters-cache';
		$settings = admin_url('options-general.php?page=perfmatters#cache');

		if($parent === 'perfmatters-cache') {
			$wp_admin_bar->add_node(array(
				'id' => 'perfmatters-cache',
				'title' => 'Perfmatters Cache',
				'href' => $settings
			));
		}

		$url = self::viewed_url();

		if($url !== '') {
			$wp_admin_bar->add_node(array(
				'parent' => $parent,
				'id' => 'perfmatters-cache-clear-url',
				'title' => __('Clear Cache (Current)', 'perfmatters'),
				'href' => wp_nonce_url(add_query_arg(array(
					'action' => 'perfmatters_cache_clear_url',
					'url' => $url
				), admin_url('admin-post.php')), 'perfmatters_cache_clear_url')
			));
		}

		$wp_admin_bar->add_node(array(
			'parent' => $parent,
			'id' => 'perfmatters-cache-clear',
			'title' => __('Clear Cache', 'perfmatters'),
			'href' => wp_nonce_url(admin_url('admin-post.php?action=perfmatters_cache_clear'), 'perfmatters_cache_clear')
		));
	}

	//front-end url the admin is viewing, in the same form as a stored file
	private static function viewed_url()
	{
		if(is_admin() || wp_doing_ajax() || is_preview() || is_customize_preview() || is_feed() || is_404() || is_search() || is_embed()) {
			return '';
		}

		Storage::load_runtime();
		$path = perfmatters_cache_request_path();

		if($path === '' || perfmatters_cache_uri_blocked($path)) {
			return '';
		}

		$relative = perfmatters_cache_strip_home_path($path, Config::home_path());
		$urls = Warmer::normalize_urls(array(home_url($relative)));

		if(count($urls) !== 1) {
			return '';
		}

		$host = strtolower((string) wp_parse_url($urls[0], PHP_URL_HOST));

		if($host === '' || $host !== Config::site_host()) {
			return '';
		}

		return $urls[0];
	}

	//true when disk cache should run even on a host that provides its own page cache
	public static function force()
	{
		$force = defined('PERFMATTERS_CACHE_FORCE') && PERFMATTERS_CACHE_FORCE;

		return (bool) apply_filters('perfmatters_cache_force', $force);
	}

	//host name when that server is the page cache. empty when this plugin should still store html
	public static function host_cache()
	{
		if(self::force()) {
			return '';
		}

		return self::detected_host_cache();
	}

	//host detection only. ignores PERFMATTERS_CACHE_FORCE so notices can still name the host
	public static function detected_host_cache()
	{
		if(self::$detected_host !== null) {
			return self::$detected_host;
		}

		$early = self::early_host_cache();

		if($early !== '') {
			self::$detected_host = $early;
			return self::$detected_host;
		}

		//these classes and constants come from mu-plugins, which load after the drop-in
		if(class_exists('FlywheelNginxCompat', false)) {
			self::$detected_host = 'Flywheel';
			return self::$detected_host;
		}

		if(class_exists('WpeCommon', false) && function_exists('wpe_param')) {
			self::$detected_host = 'WP Engine';
			return self::$detected_host;
		}

		if(defined('WPCOMSH_VERSION') && WPCOMSH_VERSION) {
			self::$detected_host = 'WordPress.com';
			return self::$detected_host;
		}

		if(defined('\Savvii\CacheFlusherPlugin::NAME_FLUSH_NOW') && defined('\Savvii\CacheFlusherPlugin::NAME_DOMAINFLUSH_NOW')) {
			self::$detected_host = 'Savvii';
			return self::$detected_host;
		}

		if(isset($GLOBALS['kinsta_cache']) && is_object($GLOBALS['kinsta_cache'])) {
			self::$detected_host = 'Kinsta';
			return self::$detected_host;
		}

		if(defined('PL_INSTANCE_REF') && class_exists('Presslabs\Cache\CacheHandler', false)) {
			self::$detected_host = 'Presslabs';
			return self::$detected_host;
		}

		//Pantheon Advanced Page Cache and related mu-plugins load after the drop-in
		if(class_exists('Pantheon_Cache', false) || class_exists('Pantheon\Cache\Cache', false)) {
			self::$detected_host = 'Pantheon';
			return self::$detected_host;
		}

		self::$detected_host = '';

		return self::$detected_host;
	}

	//signals that already exist in advanced-cache.php, before mu-plugins load. keep the two copies together
	private static function early_host_cache()
	{
		if(defined('IS_PRESSABLE') && IS_PRESSABLE) {
			return 'Pressable';
		}

		$spinup = getenv('SPINUPWP_CACHE_PATH');

		if((!is_string($spinup) || $spinup === '') && isset($_SERVER['SPINUPWP_CACHE_PATH']) && is_string($_SERVER['SPINUPWP_CACHE_PATH'])) {
			$spinup = $_SERVER['SPINUPWP_CACHE_PATH'];
		}

		if(is_string($spinup) && $spinup !== '') {
			return 'SpinupWP';
		}

		if(self::is_dreampress()) {
			return 'DreamPress';
		}

		if(!empty($_SERVER['KINSTA_CACHE_ZONE'])) {
			return 'Kinsta';
		}

		if(self::is_pantheon()) {
			return 'Pantheon';
		}

		return '';
	}

	//Pantheon defines this before plugins load; env is the fallback on some containers
	private static function is_pantheon()
	{
		if(defined('PANTHEON_ENVIRONMENT') && PANTHEON_ENVIRONMENT) {
			return true;
		}

		$env = getenv('PANTHEON_ENVIRONMENT');

		if((!is_string($env) || $env === '') && isset($_SERVER['PANTHEON_ENVIRONMENT']) && is_string($_SERVER['PANTHEON_ENVIRONMENT'])) {
			$env = $_SERVER['PANTHEON_ENVIRONMENT'];
		}

		if((!is_string($env) || $env === '') && isset($_ENV['PANTHEON_ENVIRONMENT']) && is_string($_ENV['PANTHEON_ENVIRONMENT'])) {
			$env = $_ENV['PANTHEON_ENVIRONMENT'];
		}

		return is_string($env) && $env !== '';
	}

	//DreamPress machines are named dp-* and the account user starts with wp_
	private static function is_dreampress()
	{
		if(!isset($_SERVER['DH_USER']) || !is_string($_SERVER['DH_USER'])) {
			return false;
		}

		$machine = function_exists('gethostname') ? gethostname() : false;

		if(!is_string($machine) || strncmp($machine, 'dp-', 3) !== 0) {
			return false;
		}

		$user = strtolower(preg_replace('/[^a-z0-9_\-]/', '', wp_unslash($_SERVER['DH_USER'])));

		return strncmp($user, 'wp_', 3) === 0;
	}

	//true when disk Page Cache can be offered / installed (not host-owned, multisite, or a foreign drop-in)
	public static function disk_available()
	{
		return self::host_cache() === '' && !is_multisite() && !self::foreign_dropin();
	}

	//true when we should install/serve HTML via the drop-in (single-site only in beta)
	public static function disk_enabled($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		return !empty($options['page']) && self::disk_available();
	}

	//full page-cache teardown for deactivate / clean uninstall (idempotent)
	public static function uninstall()
	{
		//CF first while a token may still exist to delete rules + purge
		Cloudflare::uninstall();

		Warmer::uninstall();
		Purge::unschedule_expire();
		Queue::drop();
		Dropin::uninstall();
		Storage::delete_all();

		delete_transient(Storage::DISK_STATS_TRANSIENT);
		delete_transient('perfmatters_cache_cleared');

		//belt-and-suspenders if an earlier step skipped an option
		delete_option('perfmatters_cache_files_version');
		delete_option(Cloudflare::STATUS_OPTION);
		delete_option(Cloudflare::TOKEN_OPTION);
		delete_option(Cloudflare::TOKEN_CONSTANT_FP);
		delete_option(Queue::VERSION_OPTION);
		delete_option(Warmer::STATE);
	}

	//remove leftover drop-in / html when a host owns page cache or multisite blocks disk. idempotent — no option write
	public static function stand_aside()
	{
		$ours = Dropin::is_ours();
		$has_files = is_dir(Config::cache_dir());

		//already clean; page may still be on in settings but host/multisite keeps disk off
		if(!$ours && !$has_files) {
			return;
		}

		Purge::unschedule_expire();

		if(empty(\Perfmatters\Config::$options['cache']['warmer'])) {
			Warmer::cancel();
			Queue::clear_rows();
		}

		if($ours) {
			Dropin::uninstall();
		}

		Storage::delete_all();
	}

	//labels for active plugins from a plugin-file => label map
	private static function active_plugin_names($plugins)
	{
		if(!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = array();

		foreach($plugins as $file => $name) {
			if(is_plugin_active($file)) {
				$active[] = $name;
			}
		}

		return $active;
	}

	//other page cache plugins that would fight advanced-cache.php or store their own html
	private static function conflicting_plugins()
	{
		return self::active_plugin_names(array(
			'wp-rocket/wp-rocket.php' => 'WP Rocket',
			'flying-press/flying-press.php' => 'FlyingPress',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'wp-super-cache/wp-cache.php' => 'WP Super Cache',
			'wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php' => 'Super Page Cache',
			'cache-enabler/cache-enabler.php' => 'Cache Enabler',
			'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
			'wp-optimize/wp-optimize.php' => 'WP-Optimize',
			'breeze/breeze.php' => 'Breeze',
			'swift-performance/performance.php' => 'Swift Performance',
			'swift-performance-lite/performance.php' => 'Swift Performance Lite',
			'comet-cache/comet-cache.php' => 'Comet Cache',
			'hyper-cache/plugin.php' => 'Hyper Cache',
			'nitropack/main.php' => 'NitroPack'
		));
	}

	//true when wp-content/advanced-cache.php exists and was not written by this helper
	private static function foreign_dropin()
	{
		return is_file(WP_CONTENT_DIR . '/advanced-cache.php') && !Dropin::is_ours();
	}

	//drop-in install error from maybe_upgrade / sync (shown on the Cache settings screen only)
	public static function install_notice()
	{
		return self::$notice;
	}

	//site-wide: clear confirmation only. install/conflict warnings live on the Cache settings screen
	public static function admin_notices()
	{
		if(!current_user_can('manage_options')) {
			return;
		}

		if(get_transient('perfmatters_cache_cleared') === false) {
			return;
		}

		delete_transient('perfmatters_cache_cleared');

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(Purge::cleared_message()) . '</p></div>';
	}

	//Cache settings: drop-in/runtime drifted, page-config stale, or WP_CACHE missing — settings-only
	public static function print_files_notice()
	{
		if(!self::disk_enabled() || self::foreign_dropin()) {
			return;
		}

		if(!Dropin::needs_resync()) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html__('Page cache files or config are missing or out of date. Reinstall them so disk cache and cache filters apply correctly.', 'perfmatters') . '</p>';
		echo '<p style="margin: 8px 0 4px;">';
		perfmatters_action_button('sync_cache_files', __('Reinstall Cache Files', 'perfmatters'), 'secondary');
		echo '</p></div>';
	}

	//why Page Cache is locked off (empty when disk can run)
	public static function page_unavailable_message()
	{
		if(self::disk_available()) {
			return '';
		}

		$host = self::host_cache();

		if($host !== '') {
			return sprintf(
				/* translators: %s: host name, such as Kinsta or WP Engine. */
				__('%s provides the page cache on this server, so Perfmatters page cache is not available.', 'perfmatters'),
				$host
			);
		}

		if(is_multisite()) {
			return __('Page cache is not available on multisite during beta.', 'perfmatters');
		}

		if(self::foreign_dropin()) {
			return __('Another plugin already provides wp-content/advanced-cache.php, so Perfmatters page cache is not available.', 'perfmatters');
		}

		return '';
	}

	//standalone row when disk cannot run — fake off toggle + reason below (same pattern as Cloudflare status lines)
	public static function print_page_disabled_field()
	{
		$message = self::page_unavailable_message();
		$tools = get_option('perfmatters_tools');
		$accessibility = !empty($tools['accessibility_mode']);

		if(!$accessibility) {
			echo '<label class="perfmatters-switch is-disabled" aria-disabled="true">';
			echo '<input type="checkbox" id="cache-page-disabled" value="1" disabled="disabled" />';
			echo '<div class="perfmatters-slider"></div>';
			echo '</label>';
		}
		else {
			echo '<input type="checkbox" id="cache-page-disabled" value="1" disabled="disabled" />';
		}

		if($message !== '') {
			echo '<p class="description"><span class="dashicons dashicons-warning" style="color:#dba617;"></span> ' . esc_html($message) . '</p>';
		}
	}

	//active rival page-cache plugins — warning while disk UI may still be shown
	public static function print_conflict_notice()
	{
		if(self::host_cache() !== '' || self::foreign_dropin()) {
			return;
		}

		$conflicts = self::conflicting_plugins();

		if(empty($conflicts)) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>';
		echo esc_html(sprintf(
			/* translators: %s: comma-separated plugin names. */
			__('Another page cache plugin is active (%s). Deactivate it before using Perfmatters Cache.', 'perfmatters'),
			implode(', ', $conflicts)
		));
		echo '</p></div>';
	}

	//warning when another plugin is managing Cloudflare Cache Rules alongside ours
	public static function print_cloudflare_conflict_notice()
	{
		if(!Cloudflare::is_enabled()) {
			return;
		}

		$conflicts = self::active_plugin_names(array(
			'wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php' => 'Super Page Cache',
			'flying-press/flying-press.php' => 'FlyingPress'
		));

		if(empty($conflicts)) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>';
		echo esc_html(sprintf(
			/* translators: %s: comma-separated plugin names. */
			__('Another plugin is managing Cloudflare cache rules (%s). Disable its Cloudflare edge/page cache or turn off Perfmatters Cloudflare edge cache to avoid conflicting rules.', 'perfmatters'),
			implode(', ', $conflicts)
		));
		echo '</p></div>';
	}

	//one Warmer::progress() per settings request — status cards and toolbar both need it
	private static function warmer_progress()
	{
		static $progress = null;

		if($progress === null) {
			$progress = Warmer::progress();
		}

		return $progress;
	}

	//Settings / Rules — same hash pattern as Code (#cache, #cache/rules)
	public static function print_subnav($active)
	{
		echo '<div class="pm-subnav">';
			echo '<a href="#cache" rel="cache-settings"' . ($active === 'settings' ? ' class="active"' : '') . '><span class="dashicons dashicons-admin-generic"></span>' . esc_html__('Settings', 'perfmatters') . '</a>';
			echo '<a href="#cache/rules" rel="cache-rules"' . ($active === 'rules' ? ' class="active"' : '') . '><span class="dashicons dashicons-list-view"></span>' . esc_html__('Rules', 'perfmatters') . '</a>';
		echo '</div>';
	}

	//pages cached / warmer / cloudflare cards under the subnav
	public static function print_status($options)
	{
		$disk = self::disk_enabled($options);
		$warmer = !empty($options['warmer']);
		$cf = !empty($options['cloudflare']);

		if(!$disk && !$warmer && !$cf) {
			return;
		}

		$progress = $warmer ? self::warmer_progress() : null;
		$running = ($warmer && $progress && !empty($progress['running']));

		if($disk && $progress && isset($progress['pages_cached'], $progress['size_label'])) {
			$cached = (int) $progress['pages_cached'];
			$size_label = $progress['size_label'];
		}
		elseif($disk) {
			$stats = Storage::disk_stats();
			$cached = $stats['pages'];
			$size_bytes = $stats['bytes'];
			$size_label = size_format($size_bytes, ($size_bytes >= MB_IN_BYTES) ? 1 : 0);
		}
		else {
			$cached = 0;
			$size_label = '';
		}

		$pages_parts = $disk ? Config::pages_cached_meta_parts($cached) : array('status' => '');
		$warmer_value = ($warmer && $progress && isset($progress['warmer_value'])) ? (string) $progress['warmer_value'] : '';
		$warmer_meta = ($warmer && $progress && isset($progress['warmer_meta'])) ? $progress['warmer_meta'] : '';
		$warmer_complete = ($warmer && !$running && $progress && !empty($progress['warmer_complete']));
		$cf_status = $cf ? Cloudflare::status() : array();
		$attrs = '';

		if($warmer) {
			$attrs = ' data-running="' . esc_attr($running ? 1 : 0) . '" data-nonce="' . esc_attr(wp_create_nonce('perfmatters_cache_warmer_status')) . '" aria-live="polite"';
		}

		echo '<div id="perfmatters-cache-status" class="perfmatters-cache-status"' . $attrs . '>';
			echo '<div class="perfmatters-cache-status-dual">';
				if($disk) {
					$disk_class = 'perfmatters-cache-status-card perfmatters-cache-status-disk' . ($cached > 0 ? ' has-pages' : ' is-empty');
					echo '<div class="' . esc_attr($disk_class) . '" data-disk-card>';
						echo '<div class="perfmatters-cache-status-copy">';
							echo '<span class="perfmatters-cache-status-label">' . esc_html__('Pages cached', 'perfmatters') . '</span>';
							echo '<strong class="perfmatters-cache-status-value" data-pages>' . esc_html(number_format_i18n($cached)) . '</strong>';
							self::print_pages_meta_row($pages_parts, $size_label);
						echo '</div>';
					echo '</div>';
				}

				if($warmer) {
					echo '<div class="perfmatters-cache-status-card perfmatters-cache-status-warmer">';
						echo '<div class="perfmatters-cache-status-copy">';
							echo '<span class="perfmatters-cache-status-label">' . esc_html__('Warmer', 'perfmatters') . '</span>';
							echo '<strong class="perfmatters-cache-status-value" data-warmer-value>' . esc_html($warmer_value) . '</strong>';
							$meta_row_class = 'perfmatters-cache-status-meta-row';
							if($running) {
								$meta_row_class .= ' is-warming';
							}
							elseif($warmer_complete) {
								$meta_row_class .= ' is-complete';
							}
							echo '<span class="' . esc_attr($meta_row_class) . '" data-warmer-meta-row>';
								echo '<svg class="perfmatters-cache-flame" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">';
									echo '<path d="M12 2c.4 3.2-1.4 5.1-3.2 6.8C7 10.5 5.5 12.2 5.5 15.2 5.5 18.6 8.2 21 12 21s6.5-2.4 6.5-5.8c0-2.4-1-4.2-2.4-5.8-.5-.6-1-1.1-1.4-1.8 1.1 2.1.8 3.5.8 3.5C14.2 9.4 12.8 5.8 12 2z"/>';
								echo '</svg>';
								echo '<span class="perfmatters-cache-status-meta" data-warmer-meta>' . esc_html($warmer_meta) . '</span>';
							echo '</span>';
						echo '</div>';
					echo '</div>';
				}

				if($cf) {
					$card = Cloudflare::status_card($cf_status);
					$cf_value = $card['cf_value'];
					$cf_meta = $card['cf_meta'];
					$cf_class = $card['cf_class'];

					echo '<div class="perfmatters-cache-status-card perfmatters-cache-status-cloudflare ' . esc_attr($cf_class) . '" data-cf-card>';
						echo '<div class="perfmatters-cache-status-copy">';
							echo '<span class="perfmatters-cache-status-label">' . esc_html__('Cloudflare', 'perfmatters') . '</span>';
							echo '<strong class="perfmatters-cache-status-value" data-cf-value>' . esc_html($cf_value) . '</strong>';
							echo '<span class="perfmatters-cache-status-meta-row">';
								echo '<span class="dashicons dashicons-cloud perfmatters-cache-cf-icon" aria-hidden="true"></span>';
								echo '<span class="perfmatters-cache-status-meta" data-cf-meta>' . esc_html($cf_meta) . '</span>';
							echo '</span>';
						echo '</div>';
					echo '</div>';
				}
			echo '</div>';
		echo '</div>';
	}

	//status + disk size under Pages cached
	private static function print_pages_meta_row($parts, $size_label)
	{
		$status = isset($parts['status']) ? (string) $parts['status'] : '';
		$size_label = is_string($size_label) ? $size_label : '';

		if($status === '' && $size_label === '') {
			return;
		}

		echo '<span class="perfmatters-cache-status-meta-row perfmatters-cache-pages-meta" data-pages-meta>';
			if($status !== '') {
				echo '<span class="perfmatters-cache-status-meta" data-pages-status>' . esc_html($status) . '</span>';
			}

			if($size_label !== '') {
				echo '<span class="perfmatters-cache-meta-chip" title="' . esc_attr__('Disk size', 'perfmatters') . '">';
					echo '<span class="dashicons dashicons-database" aria-hidden="true"></span>';
					echo '<span class="screen-reader-text">' . esc_html__('Disk size', 'perfmatters') . '</span>';
					echo '<span data-pages-size>' . esc_html($size_label) . '</span>';
				echo '</span>';
			}
		echo '</span>';
	}

	//Clear / Warmer controls in the subnav bar
	public static function print_toolbar_actions($options)
	{
		$disk = self::disk_enabled($options);
		$warmer = !empty($options['warmer']);
		$show_clear = $disk || Cloudflare::is_ready($options);

		if(!$show_clear && !$warmer) {
			return;
		}

		$running = false;
		$warmer_button = __('Start Warmer', 'perfmatters');

		if($warmer) {
			$progress = self::warmer_progress();
			$running = !empty($progress['running']);
			if(!empty($progress['warmer_button'])) {
				$warmer_button = (string) $progress['warmer_button'];
			}
		}

		echo '<div class="perfmatters-cache-toolbar-actions">';
			if($warmer) {
				$warmer_action = $running ? 'cache_warmer_stop' : 'cache_warmer_start';
				perfmatters_action_button($warmer_action, $warmer_button, 'secondary', '', 'data-warmer-button', 'toast');
			}

			if($show_clear) {
				//still starts the warmer when it's enabled; label stays simple
				perfmatters_action_button('cache_clear', __('Clear Cache', 'perfmatters'), 'primary', '', '', 'toast');
			}
		echo '</div>';
	}

}
