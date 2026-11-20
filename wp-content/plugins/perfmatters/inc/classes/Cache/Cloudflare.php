<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

//Cloudflare edge HTML cache: API auth, Cache Rules, and purge.
class Cloudflare
{
	//bump when Cache Rule / transform payloads change so existing sites re-sync on save
	const RULES_VERSION = 1;
	const STATUS_OPTION = 'perfmatters_cache_cf_status';
	//API token stays out of the autoloaded settings option (or PERFMATTERS_CLOUDFLARE_API_TOKEN in wp-config)
	const TOKEN_OPTION = 'perfmatters_cache_cf_token';
	//sha256 of the constant value — detect swaps without storing the secret again in the DB
	const TOKEN_CONSTANT_FP = 'perfmatters_cache_cf_token_constant_fp';
	//block stacked Sync Now / identical rebuilds after a *successful* sync (seconds)
	const SYNC_DEBOUNCE = 45;
	//after a CF rate-limit response, refuse another sync for this long (seconds)
	const SYNC_THROTTLE_COOLDOWN = 300;
	//parallel Sync Now / save while a sync is mid-flight (seconds; also clears a stuck lock)
	const SYNC_LOCK = 120;

	private static $syncing = false;
	private static $last_api_message = '';
	//set when sanitize stores a new API token so after_settings_save can sync without comparing secrets
	private static $token_updated = false;

	//true after store_token() changed the DB secret (Ajax reload + options_updated token-only path)
	public static function token_was_updated()
	{
		return self::$token_updated;
	}
	//human message when sync is skipped (debounce / cooldown / already in sync) for Sync Now AJAX
	private static $last_skip_message = '';
	//'noop' | 'debounce' | 'cooldown' | ''
	private static $last_skip_reason = '';

	//hooks only while Cloudflare Edge Cache is enabled
	public static function init()
	{
		add_action('wp_ajax_perfmatters_sync_cloudflare_rules', array(__CLASS__, 'ajax_sync_rules'));
		add_action('wp_ajax_perfmatters_remove_cloudflare_token', array(__CLASS__, 'ajax_remove_token'));
		//CDN no-store by default; Storage::capture / finalize upgrades to max-age only when blessed
		add_action('template_redirect', array(__CLASS__, 'start_edge_buffer'), 0);
		//CDN veto only here — do not set Cache-Control: private (that trips Storage::response_is_private)
		add_action('send_headers', array(__CLASS__, 'send_cdn_bypass_headers'), 999);
	}

	//Cache::init() — when the constant exists, strip a leftover UI token / refresh its fingerprint.
	//Constant removal (stop CDN blessing) matches License::constant_check: admin only, and only if Edge Cache is on.
	public static function sync_constant_token()
	{
		if(defined('PERFMATTERS_CLOUDFLARE_API_TOKEN')) {
			self::maybe_flag_constant_token_change();
			return;
		}

		//front hot path: no fingerprint option read when the constant is absent
		if(!is_admin()) {
			return;
		}

		//orphan page-config / htaccess blessing only matters while the toggle is on
		if(empty(\Perfmatters\Config::$options['cache']['cloudflare'])) {
			if(get_option(self::TOKEN_CONSTANT_FP, null) !== null) {
				delete_option(self::TOKEN_CONSTANT_FP);
			}

			return;
		}

		self::maybe_flag_constant_token_change();
	}

	//Edge Cache Expires presets. '' = MONTH_IN_SECONDS (~30 days); blank sits mid-list chronologically
	public static function edge_ttl_choices()
	{
		return array(
			(2 * HOUR_IN_SECONDS) => __('2 hours', 'perfmatters'),
			(6 * HOUR_IN_SECONDS) => __('6 hours', 'perfmatters'),
			(12 * HOUR_IN_SECONDS) => __('12 hours', 'perfmatters'),
			DAY_IN_SECONDS => __('24 hours', 'perfmatters'),
			WEEK_IN_SECONDS => __('7 days', 'perfmatters'),
			'' => __('30 days', 'perfmatters') . ' (' . __('Default', 'perfmatters') . ')',
			YEAR_IN_SECONDS => __('1 year', 'perfmatters')
		);
	}

	//edge HTML lease when origin opts in (bypass_by_default). filterable; blank option → 30 days
	public static function edge_ttl($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		$ttl = MONTH_IN_SECONDS;

		if(isset($options['cf_edge_ttl']) && $options['cf_edge_ttl'] !== '') {
			$ttl = is_numeric($options['cf_edge_ttl']) ? (int) $options['cf_edge_ttl'] : $options['cf_edge_ttl'];
		}

		$ttl = apply_filters('perfmatters_cache_cf_edge_ttl', $ttl);
		$ttl = is_numeric($ttl) ? (int) $ttl : 0;

		return $ttl > 0 ? $ttl : MONTH_IN_SECONDS;
	}

	//disk write, CF-only buffer, and warmer — one place to pick bless vs bypass
	public static function send_edge_headers($cacheable)
	{
		//never feed the edge without credentials — Clear Cache cannot purge orphan HITs
		if($cacheable && self::has_token()) {
			self::send_cacheable_headers();
		}
		else {
			self::send_bypass_headers();
		}
	}

	//mark this response as CDN-cacheable HTML; browsers still revalidate (no-cache)
	private static function send_cacheable_headers()
	{
		if(headers_sent()) {
			return;
		}

		//drop an earlier no-store veto — Cloudflare-CDN-Cache-Control outranks CDN-Cache-Control
		header_remove('Cloudflare-CDN-Cache-Control');
		header('CDN-Cache-Control: max-age=' . self::edge_ttl(), true);
		header('Cache-Control: no-cache, must-revalidate', true);
	}

	//CDN-only veto — safe before we know whether disk will store (avoids Cache-Control: private blocking capture)
	public static function send_cdn_bypass_headers()
	{
		if(!self::is_enabled() || is_admin() || wp_doing_ajax() || wp_doing_cron() || headers_sent()) {
			return;
		}

		header('CDN-Cache-Control: no-store', true);
		header('Cloudflare-CDN-Cache-Control: no-store', true);
	}

	//final origin veto when this response is not blessed (browser + CDN)
	public static function send_bypass_headers()
	{
		if(headers_sent()) {
			return;
		}

		self::send_cdn_bypass_headers();

		if(!self::is_enabled() || is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return;
		}

		header('Cache-Control: private, no-store', true);
	}

	//CDN no-store by default; capture/finalize may upgrade to max-age when the response is stored/blessed
	public static function start_edge_buffer()
	{
		if(!self::is_enabled() || is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return;
		}

		self::send_cdn_bypass_headers();

		//Storage::capture upgrades headers when the drop-in stores HTML
		if(\Perfmatters\Cache::disk_enabled() && Dropin::is_active()) {
			return;
		}

		if(Warmer::is_request()) {
			return;
		}

		ob_start(array(__CLASS__, 'finalize_edge_headers'));
	}

	//ob callback for CF-only / host-cache modes
	public static function finalize_edge_headers($html)
	{
		if(!is_string($html)) {
			return $html;
		}

		Storage::load_runtime();
		self::send_edge_headers(Storage::is_cacheable_response($html));

		return $html;
	}

	//after settings save: sync only when CF-relevant inputs change (or $force from Sync Now)
	public static function after_settings_save($old, $options, $force = false)
	{
		if(self::$syncing) {
			return;
		}

		self::$syncing = true;
		self::$last_skip_message = '';
		self::$last_skip_reason = '';

		$old = is_array($old) ? $old : array();
		$options = is_array($options) ? $options : array();
		$was_enabled = !empty($old['cloudflare']);
		$enabled = self::is_enabled($options);

		//wp-config token swap — same zone-clear + sync nudge as store_token()
		self::maybe_flag_constant_token_change();

		//token swaps clear the zone in store_token(); zone ID field changes clear here
		$zone_changed = self::manual_zone_id($old) !== self::manual_zone_id($options);

		if($zone_changed) {
			self::clear_resolved_zone();
		}

		if(!$enabled) {
			//skip API cleanup when CF was already off and this save did not touch it
			if($force || $was_enabled) {
				//blocking: slow Save is acceptable until we have UI for background cleanup
				$cleanup = $options;
				$cleanup['cf_zone_id'] = self::resolved_zone_id($options);
				self::delete_rules($cleanup, true);

				if(self::has_token() && $cleanup['cf_zone_id'] !== '') {
					self::api('POST', '/purge_cache', array('purge_everything' => true), true, $cleanup, false);
				}

				self::set_status(array(
					'state' => 'disabled',
					'message' => '',
					'zone_id' => '',
					'zone_domain' => '',
					'host_proxied' => -1,
					'rules_version' => 0,
					'rules_hash' => '',
					'updated' => time()
				));
				//strip CDN-Cache-Control from disk HITs (toggle off must not keep blessing the edge)
				Config::write();
				Htaccess::update();
			}

			self::$syncing = false;
			return;
		}

		//before should_sync no-op — token removed (UI or wp-config) must clear stale zone / ready UI
		if(!self::has_token()) {
			self::clear_status_after_token_loss();
			//toggle can stay on — still stop origin from blessing until a token exists again
			Config::write();
			Htaccess::update();
			self::$syncing = false;
			return;
		}

		if(!$force && !self::should_sync_rules($old, $options)) {
			self::$syncing = false;
			return;
		}

		$status = self::status();
		$hash = self::desired_rules_hash($options);
		$hash_unchanged = ($hash !== '' && $hash === (string) (isset($status['rules_hash']) ? $status['rules_hash'] : ''));
		$version_ok = ((int) (isset($status['rules_version']) ? $status['rules_version'] : 0) === self::RULES_VERSION);
		$already_ready = (isset($status['state']) && $status['state'] === 'ready' && !empty($status['zone_id']));

		//payloads already match what we last pushed — skip the delete/POST burst
		if($hash_unchanged && $version_ok && $already_ready) {
			//clear leftover optional-feature notes from older builds without a full re-POST
			if(!empty($status['message'])) {
				self::set_status(array(
					'message' => '',
					'updated' => time()
				));
			}

			self::$last_skip_reason = 'noop';
			self::$last_skip_message = __('Cloudflare cache rules are already up to date.', 'perfmatters');
			self::$syncing = false;
			return;
		}

		$gate = self::sync_gate_message($force, $hash_unchanged, !empty($status['zone_id']));

		if($gate !== '') {
			self::$last_skip_message = $gate;
			self::$syncing = false;
			return;
		}

		$zone = self::resolve_zone($options);

		if($zone === null) {
			self::$syncing = false;
			return;
		}

		//zone lives in the status option only so we never re-enter Config::updated
		self::persist_zone($zone['id'], $zone['name']);
		$options['cf_zone_id'] = $zone['id'];

		//in-flight lock for parallel Sync Now / saves — last_sync_at only advances on success
		self::set_status(array(
			'sync_lock_at' => time(),
			'updated' => time()
		));

		self::sync_rules($options, $hash);

		//safety net if sync_rules returned without clearing (e.g. unexpected early exit)
		self::set_status(array(
			'sync_lock_at' => 0
		));

		self::$syncing = false;
	}

	//inputs that change Cache Rule expressions / credentials / zone
	private static function should_sync_rules($old, $options)
	{
		if(empty($old['cloudflare']) && !empty($options['cloudflare'])) {
			return true;
		}

		if(self::$token_updated) {
			return true;
		}

		if(self::manual_zone_id($old) !== self::manual_zone_id($options)) {
			return true;
		}

		foreach(array('excluded_urls', 'bypass_cookies', 'page_mobile') as $key) {
			$prev = isset($old[$key]) ? $old[$key] : null;
			$next = isset($options[$key]) ? $options[$key] : null;

			if($prev !== $next) {
				return true;
			}
		}

		//plugin shipped new rule definitions
		$status = self::status();

		if((int) (isset($status['rules_version']) ? $status['rules_version'] : 0) !== self::RULES_VERSION) {
			return true;
		}

		return false;
	}

	//Remove Token beside the masked API Token field (license-key pattern)
	public static function ajax_remove_token()
	{
		\Perfmatters\Ajax::security_check();

		if(function_exists('perfmatters_network_access') && !perfmatters_network_access()) {
			wp_send_json_error(array(
				'message' => __('Permission denied.', 'perfmatters')
			));
		}

		if(self::token_from_constant()) {
			wp_send_json_error(array(
				'message' => sprintf(
					/* translators: %s: PHP constant name */
					__('Using %s from wp-config.php.', 'perfmatters'),
					'PERFMATTERS_CLOUDFLARE_API_TOKEN'
				)
			));
		}

		if(!self::has_option_token()) {
			wp_send_json_error(array(
				'message' => __('No Cloudflare API token is saved.', 'perfmatters')
			));
		}

		self::delete_token();

		wp_send_json_success(array(
			'message' => __('Cloudflare API token removed.', 'perfmatters'),
			'reload' => true,
			'message_key' => 'remove_cloudflare_token'
		));
	}

	//manual Sync Now from the Cloudflare settings field
	public static function ajax_sync_rules()
	{
		\Perfmatters\Ajax::security_check();

		if(function_exists('perfmatters_network_access') && !perfmatters_network_access()) {
			wp_send_json_error(array(
				'message' => __('Permission denied.', 'perfmatters')
			));
		}

		$options = \Perfmatters\Config::$options['cache'] ?? array();

		if(!self::is_enabled($options)) {
			wp_send_json_error(array(
				'message' => __('Enable Cloudflare edge cache and save before syncing rules.', 'perfmatters')
			));
		}

		if(!self::has_token()) {
			wp_send_json_error(array(
				'message' => __('Add a Cloudflare API token with Zone Cache Purge, Zone Read, and Cache Settings Edit.', 'perfmatters')
			));
		}

		self::after_settings_save($options, $options, true);

		if(self::$last_skip_message !== '') {
			if(self::$last_skip_reason === 'noop') {
				wp_send_json_success(self::ajax_sync_response(self::$last_skip_message));
			}

			wp_send_json_error(self::ajax_sync_response(self::$last_skip_message));
		}

		$status = self::status();
		$state = isset($status['state']) ? $status['state'] : '';

		if($state === 'ready') {
			$domain = !empty($status['zone_domain']) ? $status['zone_domain'] : '';
			$message = ($domain !== '')
				? sprintf(
					/* translators: %s: Cloudflare zone domain name */
					__('Cloudflare cache rules synced for %s.', 'perfmatters'),
					$domain
				)
				: __('Cloudflare cache rules synced.', 'perfmatters');

			if(!empty($status['message'])) {
				$message .= ' ' . $status['message'];
			}

			$host_proxied = isset($status['host_proxied']) ? (int) $status['host_proxied'] : -1;

			if($host_proxied === 0) {
				$message .= ' ' . sprintf(
					/* translators: %s: site hostname */
					__('%s is DNS-only (gray cloud). Set it to proxied (orange cloud) or visitors will not hit cache rules.', 'perfmatters'),
					Config::site_host()
				);
			}
			elseif($host_proxied === -1) {
				$message .= ' ' . sprintf(
					/* translators: %s: site hostname */
					__('Could not verify whether %s is proxied. Add Zone → DNS → Read on the API token (and confirm an A/AAAA/CNAME exists for this hostname).', 'perfmatters'),
					Config::site_host()
				);
			}

			wp_send_json_success(self::ajax_sync_response($message));
		}

		wp_send_json_error(self::ajax_sync_response(
			!empty($status['message'])
				? $status['message']
				: __('Cloudflare cache rules could not be synced.', 'perfmatters')
		));
	}

	//toast + status-card payload (same progress shape as Clear / Warmer toolbar actions)
	private static function ajax_sync_response($message)
	{
		return array(
			'message' => $message,
			'progress' => Warmer::progress()
		);
	}

	//labels/classes for the Cloudflare status card (settings UI + AJAX progress)
	public static function status_card($status = null)
	{
		if(!is_array($status)) {
			$status = self::status();
		}

		$state = isset($status['state']) ? (string) $status['state'] : 'disabled';
		$host_proxied = isset($status['host_proxied']) ? (int) $status['host_proxied'] : -1;
		$has_token = self::has_token();

		//stale "ready" after token removed — same gate as the Cloudflare toggle copy
		if(!$has_token) {
			return array(
				'cf_value' => __('Setup', 'perfmatters'),
				'cf_meta' => __('Add a token and save', 'perfmatters'),
				'cf_class' => 'is-setup'
			);
		}

		if($state === 'ready' && $host_proxied === 0) {
			return array(
				'cf_value' => __('DNS-only', 'perfmatters'),
				'cf_meta' => __('Set hostname to proxied', 'perfmatters'),
				'cf_class' => 'is-error'
			);
		}

		if($state === 'ready') {
			return array(
				'cf_value' => __('Ready', 'perfmatters'),
				'cf_meta' => !empty($status['zone_domain'])
					? (string) $status['zone_domain']
					: __('Cache rules active', 'perfmatters'),
				'cf_class' => 'is-ready'
			);
		}

		if($state === 'error') {
			return array(
				'cf_value' => __('Error', 'perfmatters'),
				'cf_meta' => !empty($status['message'])
					? (string) $status['message']
					: __('Check API token or zone', 'perfmatters'),
				'cf_class' => 'is-error'
			);
		}

		return array(
			'cf_value' => __('Setup', 'perfmatters'),
			'cf_meta' => __('Add a token and save', 'perfmatters'),
			'cf_class' => 'is-setup'
		);
	}

	public static function is_enabled($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		return !empty($options['cloudflare']);
	}

	public static function has_token()
	{
		return self::get_token() !== '';
	}

	//true when PERFMATTERS_CLOUDFLARE_API_TOKEN is defined (even if empty — UI stays locked)
	public static function token_from_constant()
	{
		return defined('PERFMATTERS_CLOUDFLARE_API_TOKEN');
	}

	//same shape as License::get_key_constant() — null when the constant is not defined
	public static function get_token_constant()
	{
		if(!defined('PERFMATTERS_CLOUDFLARE_API_TOKEN')) {
			return null;
		}

		return trim((string) PERFMATTERS_CLOUDFLARE_API_TOKEN);
	}

	//constant wins over the DB option (secret can stay out of the database)
	private static function get_token()
	{
		$constant = self::get_token_constant();

		if($constant !== null) {
			return $constant;
		}

		$token = get_option(self::TOKEN_OPTION, '');

		return is_string($token) ? trim($token) : '';
	}

	//option value only — used when comparing before store_token() (ignores the constant)
	private static function get_option_token()
	{
		$token = get_option(self::TOKEN_OPTION, '');

		return is_string($token) ? trim($token) : '';
	}

	//true when a UI-saved token exists in the DB (wp-config constant does not count)
	public static function has_option_token()
	{
		return self::get_option_token() !== '';
	}

	//license-style mask for the settings field (first four chars + asterisks)
	public static function option_token_mask()
	{
		$token = self::get_option_token();

		if($token === '') {
			return '';
		}

		return substr($token, 0, 4) . str_repeat('*', 27);
	}

	//Remove Token — purge edge + delete Cache Rules while credentials still work, then clear the DB secret
	public static function delete_token()
	{
		if(self::token_from_constant()) {
			return false;
		}

		$status = self::status();
		$had_token = self::get_option_token() !== '';
		$zone_id = !empty($status['zone_id']) ? (string) $status['zone_id'] : '';

		//must finish API cleanup before the secret is gone
		if($had_token && $zone_id !== '') {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
			$options['cf_zone_id'] = $zone_id;
			self::delete_rules($options, true);
			self::api('POST', '/purge_cache', array('purge_everything' => true), true, $options, false);
		}

		if($had_token) {
			delete_option(self::TOKEN_OPTION);
		}

		//always clear local status — token already gone can leave a stale "Using zone…" line
		self::set_status(array(
			'state' => 'disabled',
			'message' => '',
			'zone_id' => '',
			'zone_domain' => '',
			'host_proxied' => -1,
			'rules_version' => 0,
			'rules_hash' => '',
			'last_sync_at' => 0,
			'sync_lock_at' => 0,
			'sync_cooldown_until' => 0,
			'updated' => time()
		));

		//stop CDN-Cache-Control on disk HITs (page-config + htaccess)
		Config::write();
		Htaccess::update();

		return true;
	}

	//save a new token; clear the resolved zone when the secret actually changes
	public static function store_token($token)
	{
		$token = trim((string) $token);

		if($token === '' || self::token_from_constant()) {
			return false;
		}

		$previous = self::get_option_token();

		if(get_option(self::TOKEN_OPTION, null) === null) {
			add_option(self::TOKEN_OPTION, $token, '', false);
		}
		else {
			update_option(self::TOKEN_OPTION, $token, false);
		}

		if($previous === '' || !hash_equals($previous, $token)) {
			if($previous !== '') {
				self::clear_resolved_zone();
			}

			self::$token_updated = true;
		}

		return true;
	}

	//when the wp-config constant appears, changes, or disappears — same zone / blessing gates as a UI token swap
	private static function maybe_flag_constant_token_change()
	{
		$constant = self::get_token_constant();

		if($constant === null) {
			//constant removed — fingerprint means we previously relied on wp-config; stop blessing without a save
			if(get_option(self::TOKEN_CONSTANT_FP, null) !== null) {
				delete_option(self::TOKEN_CONSTANT_FP);

				//API cleanup is impossible without the secret; at least strip CDN-Cache-Control from origin HITs
				if(!self::has_token()) {
					self::clear_status_after_token_loss();
					Config::write();
					Htaccess::update();
				}
			}

			return;
		}

		//UI-saved secret is redundant once wp-config defines the token — remove it from the DB
		$option_token = self::get_option_token();

		if($option_token !== '') {
			//different secret than the constant → same zone-clear as store_token()
			if(!hash_equals($option_token, $constant)) {
				self::clear_resolved_zone();
				self::$token_updated = true;
			}

			delete_option(self::TOKEN_OPTION);
		}

		$fingerprint = hash('sha256', $constant);
		$seen = get_option(self::TOKEN_CONSTANT_FP, '');

		if(!is_string($seen)) {
			$seen = '';
		}

		if($seen === $fingerprint) {
			return;
		}

		if($seen !== '') {
			self::clear_resolved_zone();
			self::$token_updated = true;
		}

		if(get_option(self::TOKEN_CONSTANT_FP, null) === null) {
			add_option(self::TOKEN_CONSTANT_FP, $fingerprint, '', false);
		}
		else {
			update_option(self::TOKEN_CONSTANT_FP, $fingerprint, false);
		}
	}

	//ready UI + zone after credentials are gone (UI remove, wp-config constant deleted, or settings save with no token)
	private static function clear_status_after_token_loss()
	{
		self::set_status(array(
			'state' => 'error',
			'message' => __('Add a Cloudflare API token with Zone Cache Purge, Zone Read, and Cache Settings Edit.', 'perfmatters'),
			'zone_id' => '',
			'zone_domain' => '',
			'host_proxied' => -1,
			'rules_version' => 0,
			'rules_hash' => '',
			'updated' => time()
		));
	}

	//enabled + token + resolved zone that matches this host — same gate as purge_everything()
	public static function is_ready($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		//skip status option read when the toggle is off
		if(!self::is_enabled($options)) {
			return false;
		}

		$status = self::status();

		return self::has_token()
			&& !empty($status['zone_id'])
			&& !empty($status['zone_domain'])
			&& self::zone_matches_host(Config::site_host(), $status['zone_domain']);
	}

	//full clear from settings / admin bar / rule changes
	public static function purge_everything()
	{
		if(!self::is_ready()) {
			return false;
		}

		return self::api('POST', '/purge_cache', array('purge_everything' => true), false) !== false;
	}

	//partial purge for the same urls we deleted from disk
	public static function purge_urls($urls)
	{
		if(!self::is_ready() || !is_array($urls) || empty($urls)) {
			return false;
		}

		$files = array();
		$mobile = !empty(\Perfmatters\Config::$options['cache']['page_mobile']);

		foreach(array_unique($urls) as $url) {
			if(!is_string($url) || $url === '') {
				continue;
			}

			if($mobile) {
				foreach(array('desktop', 'mobile', 'tablet') as $device) {
					$files[] = array(
						'url' => $url,
						'headers' => array('CF-Device-Type' => $device)
					);
				}
			}
			else {
				$files[] = $url;
			}
		}

		if(empty($files)) {
			return false;
		}

		foreach(array_chunk($files, 30) as $batch) {
			self::api('POST', '/purge_cache', array('files' => $batch), false);
		}

		return true;
	}

	public static function status()
	{
		$stored = get_option(self::STATUS_OPTION, array());

		if(!is_array($stored)) {
			$stored = array();
		}

		return wp_parse_args($stored, array(
			'state' => 'disabled',
			'message' => '',
			'zone_id' => '',
			'zone_domain' => '',
			//1 = orange cloud, 0 = DNS-only, -1 = unknown (no record / no DNS Read on token)
			'host_proxied' => -1,
			'rules_version' => 0,
			//md5 of last successfully pushed rule payloads (skip no-op rebuilds)
			'rules_hash' => '',
			'last_sync_at' => 0,
			'sync_lock_at' => 0,
			'sync_cooldown_until' => 0,
			'updated' => 0
		));
	}

	//remove our Cache Rules when the plugin is deactivated
	public static function uninstall()
	{
		$options = \Perfmatters\Config::$options['cache'] ?? array();
		$status = self::status();

		if(self::has_token() && !empty($status['zone_id'])) {
			$options['cf_zone_id'] = $status['zone_id'];
			self::delete_rules($options, true);
			self::api('POST', '/purge_cache', array('purge_everything' => true), true, $options, false);
		}

		delete_option(self::STATUS_OPTION);
		delete_option(self::TOKEN_OPTION);
		delete_option(self::TOKEN_CONSTANT_FP);
	}

	//optional Zone ID from settings; resolved zone lives in status
	private static function manual_zone_id($options = null)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		return isset($options['cf_zone_id']) ? trim((string) $options['cf_zone_id']) : '';
	}

	private static function resolved_zone_id($options = null)
	{
		$status = self::status();

		if(!empty($status['zone_id'])) {
			return (string) $status['zone_id'];
		}

		return self::manual_zone_id($options);
	}

	private static function set_status($patch)
	{
		$next = array_merge(self::status(), is_array($patch) ? $patch : array());
		update_option(self::STATUS_OPTION, $next, false);
	}

	//drop cached zone only — keep rules_version / rules_hash so a re-resolve can no-op when payloads match
	private static function clear_resolved_zone()
	{
		self::set_status(array(
			'zone_id' => '',
			'zone_domain' => '',
			'host_proxied' => -1,
			'updated' => time()
		));
	}

	//rate-limit cooldown always blocks; debounce blocks Sync Now and identical rebuilds after success
	private static function sync_gate_message($force, $hash_unchanged, $has_zone = true)
	{
		$status = self::status();
		$now = time();
		$cooldown_until = (int) (isset($status['sync_cooldown_until']) ? $status['sync_cooldown_until'] : 0);

		if($cooldown_until > $now) {
			self::$last_skip_reason = 'cooldown';
			$wait = max(1, (int) ceil(($cooldown_until - $now) / 60));

			return sprintf(
				/* translators: %d: minutes remaining */
				__('Cloudflare rate limit cooldown active. Try syncing again in about %d minute(s).', 'perfmatters'),
				$wait
			);
		}

		$lock_at = (int) (isset($status['sync_lock_at']) ? $status['sync_lock_at'] : 0);

		if($lock_at > 0 && ($now - $lock_at) < self::SYNC_LOCK) {
			self::$last_skip_reason = 'debounce';

			return __('Cloudflare rules sync is already in progress. Please wait a moment and try again.', 'perfmatters');
		}

		$last_sync = (int) (isset($status['last_sync_at']) ? $status['last_sync_at'] : 0);
		$within_debounce = ($last_sync > 0 && ($now - $last_sync) < self::SYNC_DEBOUNCE);
		$state = isset($status['state']) ? (string) $status['state'] : '';

		//Sync Now always debounced after success; settings saves only when payloads are unchanged and zone is known.
		//Never debounce an error state — delete+recreate may have left rules missing and recovery must retry immediately.
		$debounce = $within_debounce
			&& $state !== 'error'
			&& ($force || ($hash_unchanged && $has_zone));

		if($debounce) {
			self::$last_skip_reason = 'debounce';
			$wait = max(1, self::SYNC_DEBOUNCE - ($now - $last_sync));

			return sprintf(
				/* translators: %d: seconds remaining */
				__('Cloudflare rules were synced recently. Please wait %d second(s) before syncing again.', 'perfmatters'),
				$wait
			);
		}

		return '';
	}

	//stable fingerprint of the three rule payloads we would push
	private static function desired_rules_hash($options)
	{
		$payload = array(
			'v' => self::RULES_VERSION,
			'cache' => self::cache_rule_payload($options),
			'file' => self::cache_file_rule_payload(),
			'rewrite' => self::rewrite_rule_payload()
		);
		$json = wp_json_encode($payload);

		return is_string($json) ? md5($json) : '';
	}

	private static function is_rate_limited($code, $message)
	{
		if((int) $code === 429) {
			return true;
		}

		$message = is_string($message) ? $message : '';

		return $message !== '' && (stripos($message, 'rate limit') !== false || stripos($message, 'throttl') !== false);
	}

	private static function persist_zone($zone_id, $zone_domain)
	{
		self::set_status(array(
			'zone_id' => $zone_id,
			'zone_domain' => $zone_domain,
			'updated' => time()
		));
	}

	//find the CF zone that matches this site host
	private static function resolve_zone($options)
	{
		$host = Config::site_host();
		$manual = self::manual_zone_id($options);
		$status = self::status();

		if($manual !== '') {
			$zone = self::api_zones_get($manual, $options);

			if($zone === null) {
				//api_request already stored the upstream message when the call failed
				if(self::last_api_message() === '') {
					self::set_status(array(
						'state' => 'error',
						'message' => __('Cloudflare could not load that zone ID. Check the token permissions and zone.', 'perfmatters'),
						'updated' => time()
					));
				}

				return null;
			}

			$name = isset($zone['name']) ? (string) $zone['name'] : '';

			if($name === '' || !self::zone_matches_host($host, $name)) {
				self::set_status(array(
					'state' => 'error',
					'message' => __('That Cloudflare zone does not match this site’s domain.', 'perfmatters'),
					'updated' => time()
				));
				return null;
			}

			return array('id' => $zone['id'], 'name' => $name);
		}

		//reuse a previously resolved zone when it still matches this host (avoids a flaky zones list wiping Ready)
		if(!empty($status['zone_id']) && !empty($status['zone_domain']) && self::zone_matches_host($host, $status['zone_domain'])) {
			$cached = self::api_zones_get((string) $status['zone_id'], $options);

			if($cached !== null) {
				$name = isset($cached['name']) ? (string) $cached['name'] : (string) $status['zone_domain'];

				if($name !== '' && self::zone_matches_host($host, $name)) {
					return array('id' => $cached['id'], 'name' => $name);
				}
			}
		}

		$page = 1;

		do {
			$list = self::api_request('GET', 'https://api.cloudflare.com/client/v4/zones?per_page=50&page=' . $page, null, $options, true);

			//api_request already recorded the real error — do not replace it with a generic token hint
			if($list === null) {
				return null;
			}

			$results = (isset($list['result']) && is_array($list['result'])) ? $list['result'] : array();

			foreach($results as $zone) {
				$name = isset($zone['name']) ? (string) $zone['name'] : '';

				if($name !== '' && self::zone_matches_host($host, $name)) {
					return array('id' => $zone['id'], 'name' => $name);
				}
			}

			$total_pages = isset($list['result_info']['total_pages']) ? (int) $list['result_info']['total_pages'] : 1;
			$page++;
		} while($page <= $total_pages);

		self::set_status(array(
			'state' => 'error',
			'message' => __('No Cloudflare zone matched this site’s domain. Enter a zone ID or check DNS.', 'perfmatters'),
			'updated' => time()
		));

		return null;
	}

	private static function zone_matches_host($host, $zone)
	{
		$host = strtolower(rtrim((string) $host, '.'));
		$zone = strtolower(rtrim((string) $zone, '.'));

		if($host === '' || $zone === '') {
			return false;
		}

		return ($host === $zone) || (substr($host, -strlen('.' . $zone)) === '.' . $zone);
	}

	private static function sync_rules($options, $rules_hash = '')
	{
		$refs = self::rule_refs();

		if($rules_hash === '') {
			$rules_hash = self::desired_rules_hash($options);
		}

		//HTML + static Cache Rules are required; transform (query strip) is optional
		$cache_id = self::ensure_ruleset('http_request_cache_settings', $options, true);

		if($cache_id === '') {
			self::set_status(array(
				'state' => 'error',
				'message' => self::last_api_message() !== ''
					? self::last_api_message()
					: __('Cloudflare cache rules could not be created. Token needs cache rules edit (or cache settings edit).', 'perfmatters'),
				'sync_lock_at' => 0,
				'updated' => time()
			));
			return;
		}

		self::delete_our_rules($cache_id, $refs, $options);

		$cache_ok = self::api('POST', '/rulesets/' . $cache_id . '/rules', self::cache_rule_payload($options), true, $options);
		$file_ok = self::api('POST', '/rulesets/' . $cache_id . '/rules', self::cache_file_rule_payload(), true, $options);

		if($cache_ok === null || $file_ok === null) {
			self::set_status(array(
				'state' => 'error',
				'message' => self::last_api_message() !== ''
					? self::last_api_message()
					: __('Cloudflare cache rules could not be updated. Token needs cache rules edit.', 'perfmatters'),
				'sync_lock_at' => 0,
				'updated' => time()
			));
			return;
		}

		//strip Config::ignored_queries() at the edge (remove_query_args — Free+; no regex / Business plan)
		$transform_id = self::ensure_ruleset('http_request_transform', $options, false);

		if($transform_id !== '') {
			self::delete_our_rules($transform_id, $refs, $options);
			self::api('POST', '/rulesets/' . $transform_id . '/rules', self::rewrite_rule_payload(), true, $options, false);
		}

		$zone_id = self::resolved_zone_id($options);
		$host_proxied = self::host_is_proxied($zone_id, $options);

		self::set_status(array(
			'state' => 'ready',
			'message' => '',
			'host_proxied' => $host_proxied,
			'rules_version' => self::RULES_VERSION,
			'rules_hash' => $rules_hash,
			'last_sync_at' => time(),
			'sync_lock_at' => 0,
			'sync_cooldown_until' => 0,
			'updated' => time()
		));
	}

	//true when site host has a proxied A/AAAA/CNAME; false when DNS-only; -1 when unknown
	private static function host_is_proxied($zone_id, $options)
	{
		$host = Config::site_host();
		$zone_id = is_string($zone_id) ? $zone_id : '';

		if($host === '' || $zone_id === '') {
			return -1;
		}

		$url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id)
			. '/dns_records?per_page=100&name=' . rawurlencode($host);
		//DNS Read is optional — missing permission must not fail the Cache Rules sync
		$list = self::api_request('GET', $url, null, $options, true, false);

		if($list === null || empty($list['result']) || !is_array($list['result'])) {
			return -1;
		}

		$found = false;

		foreach($list['result'] as $record) {
			$type = isset($record['type']) ? (string) $record['type'] : '';

			if(!in_array($type, array('A', 'AAAA', 'CNAME'), true)) {
				continue;
			}

			$found = true;

			if(!empty($record['proxied'])) {
				return 1;
			}
		}

		return $found ? 0 : -1;
	}

	//create or return the entrypoint ruleset id for a phase
	private static function ensure_ruleset($phase, $options, $required)
	{
		$ruleset = self::api('GET', '/rulesets/phases/' . $phase . '/entrypoint', array(), true, $options, false);

		if(!empty($ruleset['id'])) {
			return (string) $ruleset['id'];
		}

		$ruleset = self::api('POST', '/rulesets', array(
			'name' => 'Perfmatters ' . $phase,
			'kind' => 'zone',
			'phase' => $phase
		), true, $options, $required);

		return !empty($ruleset['id']) ? (string) $ruleset['id'] : '';
	}

	//$ruleset null = fetch by id (token remove). callers that already GET the entrypoint pass it to skip a round trip
	private static function delete_our_rules($ruleset_id, $refs, $options, $blocking = false, $ruleset = null)
	{
		if(!is_array($ruleset) || empty($ruleset['rules'])) {
			$ruleset = self::api('GET', '/rulesets/' . $ruleset_id, array(), true, $options, false);
		}

		if(empty($ruleset['rules']) || !is_array($ruleset['rules'])) {
			return;
		}

		foreach($ruleset['rules'] as $rule) {
			if(self::is_our_rule($rule, $refs) && !empty($rule['id'])) {
				//blocking on token remove / uninstall so deletes finish before the secret is cleared
				self::api('DELETE', '/rulesets/' . $ruleset_id . '/rules/' . $rule['id'], array(), $blocking, $options, false);
			}
		}
	}

	private static function delete_rules($options = null, $blocking = false)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		if(!self::has_token() || self::resolved_zone_id($options) === '') {
			return;
		}

		$refs = self::rule_refs();

		foreach(array('http_request_cache_settings', 'http_request_transform') as $phase) {
			$ruleset = self::api('GET', '/rulesets/phases/' . $phase . '/entrypoint', array(), true, $options, false);

			if(empty($ruleset['id'])) {
				continue;
			}

			self::delete_our_rules($ruleset['id'], $refs, $options, $blocking, $ruleset);
		}
	}

	private static function is_our_rule($rule, $refs)
	{
		$ref = isset($rule['ref']) ? (string) $rule['ref'] : '';

		if($ref !== '' && in_array($ref, $refs, true)) {
			return true;
		}

		$description = isset($rule['description']) ? (string) $rule['description'] : '';

		if($description === '') {
			return false;
		}

		//current + pre-rename descriptions so leftover edge rules still get cleaned up
		return strpos($description, 'Perfmatters cache') === 0
			|| strpos($description, 'Perfmatters page cache') === 0;
	}

	private static function rule_refs()
	{
		$suffix = md5(Config::site_host());

		return array(
			'cache' => 'perfmatters_cache_' . $suffix,
			'cache_file' => 'perfmatters_cache_file_' . $suffix,
			'rewrite' => 'perfmatters_cache_rewrite_' . $suffix
		);
	}

	private static function cache_rule_payload($options)
	{
		$host = Config::site_host();
		$cookies = Config::cookie_prefixes($options);
		$cookies = array_values(array_unique(array_filter(array_map('strval', $cookies))));

		//CF http.cookie contains matches prefixes; strip trailing _ so wordpress_logged_in_ still hits
		$cookies = array_map(function($cookie) {
			return rtrim($cookie, '_');
		}, $cookies);

		if(!in_array('wordpress_logged_in', $cookies, true)) {
			array_unshift($cookies, 'wordpress_logged_in');
		}

		$parts = array('http.host eq "' . self::escape_expression($host) . '"');
		$parts[] = '(http.request.method eq "GET" or http.request.method eq "HEAD")';
		$parts[] = 'not http.request.uri.path contains "/wp-admin"';
		$parts[] = 'not http.request.uri.path contains "/wp-login.php"';
		$parts[] = 'not http.request.uri.path contains "/xmlrpc.php"';
		$parts[] = 'not http.request.uri.path contains "/wp-json/"';

		//same partial path matches as disk: settings Excluded URLs + WooCommerce cart/checkout/account
		foreach(Config::excluded_paths($options) as $path) {
			$path = trim((string) $path);

			if($path === '') {
				continue;
			}

			$parts[] = 'not http.request.uri.path contains "' . self::escape_expression($path) . '"';
		}

		foreach($cookies as $cookie) {
			$parts[] = 'not http.cookie contains "' . self::escape_expression($cookie) . '"';
		}

		//warmer must reach origin so the drop-in can write; do not serve an edge HIT for these requests
		$parts[] = 'not http.request.uri.query contains "perfmatters_warmer"';

		return array(
			'ref' => self::rule_refs()['cache'],
			'description' => 'Perfmatters cache',
			'enabled' => true,
			'expression' => '(' . implode(' and ', $parts) . ')',
			'action' => 'set_cache_settings',
			'action_parameters' => array(
				'cache' => true,
				'browser_ttl' => array('mode' => 'respect_origin'),
				//edge only when origin sends CDN-Cache-Control; browsers keep no-cache
				'edge_ttl' => array(
					'mode' => 'bypass_by_default'
				),
				'cache_key' => array(
					'cache_by_device_type' => !empty($options['page_mobile']),
					'cache_deception_armor' => true
				)
			)
		);
	}

	private static function cache_file_rule_payload()
	{
		$host = Config::site_host();
		$extensions = array(
			'7z', 'avi', 'avif', 'apk', 'bin', 'bmp', 'bz2', 'class', 'css', 'csv', 'doc', 'docx', 'dmg',
			'ejs', 'eot', 'eps', 'exe', 'flac', 'gif', 'gz', 'ico', 'iso', 'jar', 'jpg', 'jpeg', 'js',
			'mid', 'midi', 'mkv', 'mp3', 'mp4', 'ogg', 'otf', 'pdf', 'pict', 'pls', 'png', 'ppt', 'pptx',
			'ps', 'rar', 'svg', 'svgz', 'swf', 'tar', 'tif', 'tiff', 'ttf', 'webm', 'webp', 'woff', 'woff2',
			'xls', 'xlsx', 'zip', 'zst'
		);

		return array(
			'ref' => self::rule_refs()['cache_file'],
			'description' => 'Perfmatters cache static files',
			'enabled' => true,
			'expression' => '(http.host eq "' . self::escape_expression($host) . '" and http.request.uri.path.extension in {"' . implode('" "', $extensions) . '"})',
			'action' => 'set_cache_settings',
			'action_parameters' => array(
				'cache' => true,
				'browser_ttl' => array('mode' => 'override_origin', 'default' => YEAR_IN_SECONDS),
				'edge_ttl' => array('mode' => 'override_origin', 'default' => YEAR_IN_SECONDS)
			)
		);
	}

	private static function rewrite_rule_payload()
	{
		$host = Config::site_host();
		$params = Config::ignored_queries();
		$params = array_values(array_unique(array_filter(array_map('strval', is_array($params) ? $params : array()))));
		//keep perfmatters_warmer on the request so CF cache bypass + Warmer::is_request() still see it
		$params = array_values(array_diff($params, array('perfmatters_warmer')));

		if(empty($params)) {
			$params = array('utm_source');
		}

		$escaped = array_map(array(__CLASS__, 'escape_expression'), $params);
		$expr = array();

		foreach($escaped as $param) {
			$expr[] = '(http.request.uri.query contains "' . $param . '")';
		}

		//named args only — Free/Pro (regex_replace needed Business)
		$remove = 'remove_query_args(http.request.uri.query, "' . implode('", "', $escaped) . '")';

		return array(
			'ref' => self::rule_refs()['rewrite'],
			'description' => 'Perfmatters cache ignore query params',
			'enabled' => true,
			'expression' => '(http.host eq "' . self::escape_expression($host) . '" and (' . implode(' or ', $expr) . '))',
			'action' => 'rewrite',
			'action_parameters' => array(
				'uri' => array(
					'query' => array(
						'expression' => $remove
					)
				)
			)
		);
	}

	private static function escape_expression($value)
	{
		return str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $value);
	}

	private static function last_api_message()
	{
		return self::$last_api_message;
	}

	private static function api($method, $endpoint, $body = array(), $blocking = true, $options = null, $record_error = true)
	{
		if(!is_array($options)) {
			$options = \Perfmatters\Config::$options['cache'] ?? array();
		}

		$zone_id = self::resolved_zone_id($options);

		if($zone_id === '' || !self::has_token()) {
			return $blocking ? null : false;
		}

		$url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id) . $endpoint;
		$result = self::api_request($method, $url, $body, $options, $blocking, $record_error);

		if(!$blocking) {
			return true;
		}

		if($result === null || empty($result['success'])) {
			return null;
		}

		return isset($result['result']) ? $result['result'] : array();
	}

	private static function api_zones_get($zone_id, $options)
	{
		$url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id);
		$result = self::api_request('GET', $url, null, $options, true, true);

		return (is_array($result) && isset($result['result'])) ? $result['result'] : null;
	}

	private static function api_request($method, $url, $body, $options, $blocking, $record_error = true)
	{
		self::$last_api_message = '';
		$token = self::get_token();
		$args = array(
			'method' => $method,
			'timeout' => 20,
			'blocking' => $blocking,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type' => 'application/json'
			)
		);

		if($body !== null && $body !== array()) {
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_remote_request($url, $args);

		if(!$blocking) {
			return null;
		}

		if(is_wp_error($response)) {
			self::$last_api_message = $response->get_error_message();

			if($record_error) {
				self::set_status(array(
					'state' => 'error',
					'message' => self::$last_api_message,
					'updated' => time()
				));
			}

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$json = json_decode(wp_remote_retrieve_body($response), true);

		if($code < 200 || $code >= 300 || !is_array($json) || empty($json['success'])) {
			$message = __('Cloudflare API request failed.', 'perfmatters');

			if(is_array($json) && !empty($json['errors'][0]['message'])) {
				$message = (string) $json['errors'][0]['message'];
			}

			self::$last_api_message = $message;

			$patch = array(
				'updated' => time()
			);

			if(self::is_rate_limited($code, $message)) {
				$patch['sync_cooldown_until'] = time() + self::SYNC_THROTTLE_COOLDOWN;
			}

			if($record_error) {
				$patch['state'] = 'error';
				$patch['message'] = $message;
			}

			if($record_error || isset($patch['sync_cooldown_until'])) {
				self::set_status($patch);
			}

			return null;
		}

		return $json;
	}
}
