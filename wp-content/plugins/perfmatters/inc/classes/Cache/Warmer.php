<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class Warmer
{
	const STATE = 'perfmatters_cache_warmer_state';
	const CRON = 'perfmatters_cache_warmer_health';
	const CRON_INTERVAL = 'perfmatters_cache_warmer_interval';
	const RECURRING = 'perfmatters_cache_warmer_cron';
	const RETRIES = 1;

	private static $deferred = false;
	private static $deferred_full = false;
	private static $deferred_urls = array();
	private static $deferred_forget = array();
	private static $deferred_extra = array();
	//true for this PHP request after prepare_request() — survives scrubbing $_GET
	private static $request = false;
	//memoized collect() results keyed by 'enabled' | 'all' (scan)
	private static $collection = array();

	//ajax batch, status poll, recurring schedule, and a fallback if the loopback never returns
	public static function init()
	{
		//ssl + strip perfmatters_warmer from request globals before templates build links
		self::prepare_request();

		add_action('wp_ajax_perfmatters_cache_warmer_start', array(__CLASS__, 'ajax_start'));
		add_action('wp_ajax_perfmatters_cache_warmer_stop', array(__CLASS__, 'ajax_stop'));
		add_action('wp_ajax_perfmatters_cache_warmer', array(__CLASS__, 'batch'));
		add_action('wp_ajax_nopriv_perfmatters_cache_warmer', array(__CLASS__, 'batch'));
		add_action('wp_ajax_perfmatters_cache_warmer_status', array(__CLASS__, 'status'));
		//cron-only: front init must not redispatch mid-run (batch already schedules this event)
		add_action(self::CRON, array(__CLASS__, 'healthcheck'));
		add_action(self::RECURRING, array(__CLASS__, 'run_recurring'));
		add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
		self::schedule_recurring();
	}

	//mark the warmer fetch, fix is_ssl() for loopback, then remove the query so pagination/reply links stay clean
	private static function prepare_request()
	{
		if(!isset($_GET['perfmatters_warmer'])) {
			return;
		}

		self::$request = true;
		self::force_ssl_for_request();
		self::scrub_query();
	}

	//warmer loopback hits :80 so is_ssl() is false; set_url_scheme then rewrites site/home assets to http://
	private static function force_ssl_for_request()
	{
		if(!self::is_request() || is_ssl()) {
			return;
		}

		$home = get_option('home');

		if(!is_string($home) || stripos($home, 'https://') !== 0) {
			return;
		}

		$_SERVER['HTTPS'] = 'on';
	}

	//param is only a fetch signal — leave it on the request and get_pagenum_link / cancel-reply bake it into HTML
	private static function scrub_query()
	{
		unset($_GET['perfmatters_warmer'], $_REQUEST['perfmatters_warmer']);

		if(isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
			$qs = array();
			parse_str($_SERVER['QUERY_STRING'], $qs);
			unset($qs['perfmatters_warmer']);
			$_SERVER['QUERY_STRING'] = http_build_query($qs);
		}

		if(isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] !== '') {
			$_SERVER['REQUEST_URI'] = remove_query_arg('perfmatters_warmer', $_SERVER['REQUEST_URI']);
		}
	}

	//queue every public url, or just the ones a partial purge needs rebuilt
	//true when a run was dispatched (or deferred until init); false when off / nothing to warm
	public static function start($urls = null, $forget = array(), $extra = array())
	{
		if(empty(\Perfmatters\Config::$options['cache']['warmer'])) {
			return false;
		}

		//permalinks are not ready until init
		if(!did_action('init')) {
			self::defer($urls, $forget, $extra);
			return true;
		}

		$forget = self::normalize_urls($forget);
		$extra = self::allowed_urls($extra);
		$partial = is_array($urls) ? self::allowed_urls($urls) : null;

		if($partial === null) {
			Queue::replace_all(self::collect_urls());
		}
		else {
			Queue::forget($forget);
			Queue::enqueue(array_merge($partial, $extra), 0);
		}

		if(!Queue::unfinished()) {
			if(!empty(self::state()['running'])) {
				self::cancel();
			}
			return false;
		}

		$token = wp_generate_password(32, false, false);
		$previous = self::state();

		self::save_state(array(
			'running' => 1,
			'token' => $token,
			'lock' => time(),
			'last_run' => !empty($previous['last_run']) ? (int) $previous['last_run'] : 0
		));

		self::schedule_cron();
		self::dispatch($token);

		return true;
	}

	//a clear that happens before init has to wait until permalinks exist
	private static function defer($urls, $forget, $extra)
	{
		if($urls === null) {
			self::$deferred_full = true;
		}
		else {
			self::$deferred_urls = array_merge(self::$deferred_urls, $urls);
			self::$deferred_forget = array_merge(self::$deferred_forget, $forget);
			self::$deferred_extra = array_merge(self::$deferred_extra, $extra);
		}

		if(self::$deferred) {
			return;
		}

		self::$deferred = true;
		add_action('init', array(__CLASS__, 'run_deferred'), 30);
	}

	//run the warmer that was requested before permalinks were ready
	public static function run_deferred()
	{
		$full = self::$deferred_full;
		$urls = self::$deferred_urls;
		$forget = self::$deferred_forget;
		$extra = self::$deferred_extra;
		self::$deferred = false;
		self::$deferred_full = false;
		self::$deferred_urls = array();
		self::$deferred_forget = array();
		self::$deferred_extra = array();

		self::start($full ? null : $urls, $forget, $extra);
	}

	//stop a running queue without deleting cached html or the url rows
	public static function cancel()
	{
		Queue::release_in_progress();
		self::unschedule_cron();

		//stamp now for natural finish and manual stop so "Last run" is when this attempt ended
		self::save_state(array(
			'running' => 0,
			'token' => '',
			'lock' => 0,
			'last_run' => time()
		));
	}

	//settings toolbar: start a full warm (data-pm-action)
	public static function ajax_start()
	{
		self::authorize_ajax();

		self::start();

		wp_send_json_success(array(
			'message' => __('Warmer started.', 'perfmatters'),
			'progress' => self::progress()
		));
	}

	//settings toolbar: stop the current run (data-pm-action)
	public static function ajax_stop()
	{
		self::authorize_ajax();

		self::cancel();

		wp_send_json_success(array(
			'message' => __('Warmer stopped.', 'perfmatters'),
			'progress' => self::progress()
		));
	}

	private static function authorize_ajax()
	{
		\Perfmatters\Ajax::security_check();

		if(function_exists('perfmatters_network_access') && !perfmatters_network_access()) {
			wp_send_json_error(array(
				'message' => __('Permission denied.', 'perfmatters')
			));
		}
	}

	//scheduled full crawl. skips when a run is already in progress
	public static function run_recurring()
	{
		if(empty(\Perfmatters\Config::$options['cache']['warmer'])) {
			return;
		}

		$state = self::state();

		if(!empty($state['running'])) {
			return;
		}

		self::start();
	}

	//keep one recurring event when a schedule is selected
	public static function schedule_recurring()
	{
		$schedule = isset(\Perfmatters\Config::$options['cache']['warmer_schedule'])
			? (string) \Perfmatters\Config::$options['cache']['warmer_schedule']
			: '';

		if($schedule === '' || empty(\Perfmatters\Config::$options['cache']['warmer'])) {
			//only touch cron when an event is actually queued (steady-state front traffic stays quiet)
			if(wp_next_scheduled(self::RECURRING)) {
				self::unschedule_recurring();
			}
			return;
		}

		$next = wp_next_scheduled(self::RECURRING);

		if($next) {
			$current = wp_get_schedule(self::RECURRING);

			if($current === $schedule) {
				return;
			}

			self::unschedule_recurring();
		}

		wp_schedule_event(time() + HOUR_IN_SECONDS, $schedule, self::RECURRING);
	}

	public static function unschedule_recurring()
	{
		wp_clear_scheduled_hook(self::RECURRING);
	}

	//clean uninstall / plugin delete — stop crons and drop persisted run state
	public static function uninstall()
	{
		self::cancel();
		self::unschedule_recurring();
		delete_option(self::STATE);
	}

	//true when this front-end request is the warmer fetch (flag survives scrub_query())
	public static function is_request()
	{
		return self::$request || isset($_GET['perfmatters_warmer']);
	}

	//true while a warmer run is in progress (Clear Cache toast / status)
	public static function is_running()
	{
		return !empty(self::state()['running']);
	}

	//queue done count + run state for the warmer status card
	public static function progress()
	{
		$state = self::state();
		$counts = Queue::counts();
		//in_progress rows often already have HTML on disk; include them so the card does not lag Pages cached mid-batch
		$cached = (int) $counts['done'] + (int) $counts['in_progress'];
		$running = !empty($state['running']);

		if($running) {
			$warmer_meta = sprintf(
				/* translators: %s: number of urls the warmer has finished or is fetching in the current run */
				__('%s warmed', 'perfmatters'),
				number_format_i18n($cached)
			);
		}
		else {
			$warmer_meta = self::last_run_label($state);

			if($warmer_meta === '') {
				$warmer_meta = __('Not run yet', 'perfmatters');
			}
		}

		//host/CF-only mode has no disk tree to walk
		$pages = 0;
		$size_label = size_format(0, 0);

		if(\Perfmatters\Cache::disk_enabled()) {
			$disk = Storage::disk_stats();
			$pages = $disk['pages'];
			$size_bytes = $disk['bytes'];
			$size_label = size_format($size_bytes, ($size_bytes >= MB_IN_BYTES) ? 1 : 0);
		}

		$complete = (!$running && !empty($state['last_run']));

		$progress = array(
			'running' => $running ? 1 : 0,
			'warmer_meta' => $warmer_meta,
			'warmer_value' => $running ? __('Running', 'perfmatters') : __('Idle', 'perfmatters'),
			'warmer_button' => $running ? __('Stop Warmer', 'perfmatters') : __('Start Warmer', 'perfmatters'),
			'warmer_complete' => $complete ? 1 : 0,
			'pages_cached' => $pages,
			'pages_cached_label' => number_format_i18n($pages),
			'pages_meta_parts' => Config::pages_cached_meta_parts($pages),
			'size_label' => $size_label
		);

		if(Cloudflare::is_enabled()) {
			$progress = array_merge($progress, Cloudflare::status_card());
		}

		return $progress;
	}

	//relative time for the last finished warmer run
	private static function last_run_label($state = null)
	{
		if($state === null) {
			$state = self::state();
		}

		$last_run = !empty($state['last_run']) ? (int) $state['last_run'] : 0;

		if($last_run < 1) {
			return '';
		}

		return sprintf(
			/* translators: %s: human time difference, such as "2 hours" */
			__('Last run %s ago', 'perfmatters'),
			human_time_diff($last_run)
		);
	}

	//continue a queue whose loopback died
	public static function healthcheck()
	{
		$state = self::state();

		if(empty($state['running']) || empty($state['token'])) {
			return;
		}

		if(!Queue::unfinished()) {
			self::cancel();
			return;
		}

		//the ajax chain is the fast path. this event restarts it when that call never returns
		self::schedule_cron();

		if(!empty($state['lock']) && (time() - (int) $state['lock']) < 90) {
			return;
		}

		Queue::release_in_progress();
		$state['lock'] = time();
		self::save_state($state);
		self::dispatch($state['token']);
	}

	//request the next urls, then hand off so this process can exit
	public static function batch()
	{
		$state = self::state();
		$token = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';

		if($token === '' || empty($state['token']) || !hash_equals((string) $state['token'], $token)) {
			wp_die();
		}

		//WP "non-blocking" still waits for headers/timeout; answer first so Clear Cache / chain handoff is not stuck on this batch
		self::release_dispatcher();

		$deadline = microtime(true) + 12;

		while(microtime(true) < $deadline) {
			$row = Queue::claim();

			if(!$row) {
				break;
			}

			$cached = self::fetch($row->url);
			$fresh = self::state();

			//a newer purge replaced this run. put the url back and stop
			if(empty($fresh['token']) || !hash_equals((string) $fresh['token'], $token)) {
				Queue::release($row->id);
				wp_die();
			}

			if($cached) {
				Queue::complete($row->id);
			}
			elseif((int) $row->attempts < self::RETRIES) {
				//one more attempt, after the urls that have not been tried, for a timeout or a missed write
				Queue::retry($row->id, (int) $row->attempts + 1);
			}
			else {
				Queue::fail($row->id);
			}

			self::pause();
		}

		$fresh = self::state();

		if(empty($fresh['token']) || !hash_equals((string) $fresh['token'], $token)) {
			wp_die();
		}

		$state['lock'] = time();
		$state['running'] = Queue::unfinished() ? 1 : 0;

		if(empty($state['running'])) {
			self::cancel();
			wp_die();
		}

		self::save_state($state);
		self::schedule_cron();
		self::dispatch($token);

		wp_die();
	}

	//healthcheck interval, plus weekly for the warmer schedule dropdown
	public static function cron_schedules($schedules)
	{
		$schedules[self::CRON_INTERVAL] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display' => __('Every 5 minutes', 'perfmatters')
		);

		if(!isset($schedules['weekly'])) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display' => __('Once Weekly', 'perfmatters')
			);
		}

		return $schedules;
	}

	//keep one event queued for the whole run. the first fire waits a full interval
	private static function schedule_cron()
	{
		if(wp_next_scheduled(self::CRON)) {
			return;
		}

		wp_schedule_event(time() + (5 * MINUTE_IN_SECONDS), self::CRON_INTERVAL, self::CRON);
	}

	private static function unschedule_cron()
	{
		wp_clear_scheduled_hook(self::CRON);
	}

	//progress for the settings screen while a queue is running
	public static function status()
	{
		check_ajax_referer('perfmatters_cache_warmer_status');

		if(!current_user_can('manage_options') || (function_exists('perfmatters_network_access') && !perfmatters_network_access())) {
			wp_send_json_error();
		}

		wp_send_json_success(self::progress());
	}

	//on-demand source badges via the shared Perfmatters action-button AJAX (data-pm-action)
	public static function scan_sources()
	{
		\Perfmatters\Ajax::security_check();

		if(function_exists('perfmatters_network_access') && !perfmatters_network_access()) {
			wp_send_json_error(array(
				'message' => __('Permission denied.', 'perfmatters')
			));
		}

		$counts = self::source_counts();
		unset($counts['home']); //always warmed; locked row has no scan badge
		$data = array();

		foreach($counts as $key => $count) {
			$data[$key] = number_format_i18n((int) $count);
		}

		wp_send_json_success(array(
			'message' => __('Warmer source scan completed.', 'perfmatters'),
			'data' => $data
		));
	}

	//filtered public urls the warmer will request
	public static function collect_urls()
	{
		return self::collect()['urls'];
	}

	//per-source counts for Scan Now (includes unchecked sources so badges stay informative)
	public static function source_counts()
	{
		return self::collect(true)['counts'];
	}

	//build the url list once per request and track which source each url came from
	//$all_sources: Scan Now ignores checkboxes so each badge shows what that source would add
	public static function collect($all_sources = false)
	{
		$key = $all_sources ? 'all' : 'enabled';

		if(isset(self::$collection[$key])) {
			return self::$collection[$key];
		}

		$cache = \Perfmatters\Config::$options['cache'] ?? array();
		$sources = (isset($cache['warmer_sources']) && is_array($cache['warmer_sources'])) ? $cache['warmer_sources'] : array();
		$skip_ids = array();
		$seen = array();
		$counts = array(
			'home' => 0,
			'posts' => 0,
			'pages' => 0,
			'categories' => 0,
			'tags' => 0,
			'authors' => 0,
			'archives' => 0,
			'attachments' => 0
		);

		$cpt_objects = Config::warmer_post_types();
		$enabled_cpts = array();

		foreach($cpt_objects as $name => $object) {
			$counts[$name] = 0;

			if($all_sources || !empty($sources['post_types'][$name])) {
				$enabled_cpts[] = $name;
			}
		}

		//shop is cacheable (and warmed); cart/checkout/account stay out
		if(function_exists('wc_get_page_id')) {
			foreach(array('cart', 'checkout', 'myaccount') as $page) {
				$page_id = (int) wc_get_page_id($page);

				if($page_id > 0) {
					$skip_ids[] = $page_id;
				}
			}
		}

		$types = array();

		if($all_sources || !empty($sources['posts'])) {
			$types['post'] = 'post';
		}

		if($all_sources || !empty($sources['pages'])) {
			$types['page'] = 'page';
		}

		if($all_sources || !empty($sources['attachments'])) {
			$types['attachment'] = 'attachment';
		}

		foreach($enabled_cpts as $name) {
			$types[$name] = $name;
		}

		Storage::load_runtime();
		$excluded = Config::excluded_paths();

		$add = function($url, $source) use (&$seen, &$counts, $excluded) {
			$url = self::normalize($url);

			if($url === '' || isset($seen[$url]) || !isset($counts[$source])) {
				return;
			}

			$path = wp_parse_url($url, PHP_URL_PATH);

			if(!is_string($path) || $path === '') {
				$path = '/';
			}

			if(perfmatters_cache_path_excluded($path, $excluded)) {
				return;
			}

			$seen[$url] = $source;
			$counts[$source]++;
		};

		$add(home_url('/'), 'home');

		if(!empty($types)) {
			$paged = 1;
			$query = array(
				'post_type' => array_values($types),
				'post_status' => 'publish',
				'posts_per_page' => 200,
				'paged' => $paged,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'has_password' => false,
				'no_found_rows' => true
			);

			if(!empty($skip_ids)) {
				$query['post__not_in'] = $skip_ids;
			}

			do {
				$query['paged'] = $paged;
				$ids = get_posts($query);

				if(!is_array($ids)) {
					break;
				}

				foreach($ids as $id) {
					$permalink = get_permalink($id);

					if(!is_string($permalink) || $permalink === '') {
						continue;
					}

					$type = get_post_type($id);

					if($type === 'page') {
						$add($permalink, 'pages');
					}
					elseif($type === 'attachment') {
						$add($permalink, 'attachments');
					}
					elseif($type === 'post') {
						$add($permalink, 'posts');
					}
					else {
						$add($permalink, $type);
					}
				}

				$paged++;
			}
			while(count($ids) === 200);
		}

		$posts_page = (int) get_option('page_for_posts');

		if(($all_sources || !empty($sources['posts'])) && $posts_page > 0 && !in_array($posts_page, $skip_ids, true)) {
			$permalink = get_permalink($posts_page);

			if(is_string($permalink) && $permalink !== '') {
				$add($permalink, 'posts');
			}
		}

		if($all_sources || !empty($sources['archives'])) {
			foreach($enabled_cpts as $type) {
				$archive = get_post_type_archive_link($type);

				if(is_string($archive) && $archive !== '') {
					$add($archive, 'archives');
				}
			}
		}

		$taxonomies = get_taxonomies(array('public' => true), 'objects');

		foreach($taxonomies as $taxonomy) {
			if($taxonomy->name === 'category') {
				if(!$all_sources && empty($sources['categories'])) {
					continue;
				}

				$source = 'categories';
			}
			elseif($taxonomy->name === 'post_tag') {
				if(!$all_sources && empty($sources['tags'])) {
					continue;
				}

				$source = 'tags';
			}
			else {
				//product_cat / product_tag and other public CPTs land under archives
				if(!$all_sources && empty($sources['archives'])) {
					continue;
				}

				$source = 'archives';
			}

			$terms = get_terms(array(
				'taxonomy' => $taxonomy->name,
				'hide_empty' => true
			));

			if(is_wp_error($terms) || !is_array($terms)) {
				continue;
			}

			foreach($terms as $term) {
				$link = get_term_link($term);

				if(!is_wp_error($link) && is_string($link)) {
					$add($link, $source);
				}
			}
		}

		if($all_sources || !empty($sources['authors'])) {
			$author_types = array_values(array_diff($types, array('page', 'attachment')));

			if(!empty($author_types)) {
				$authors = get_users(array(
					'has_published_posts' => $author_types,
					'fields' => array('ID')
				));

				foreach($authors as $author) {
					$add(get_author_posts_url($author->ID), 'authors');
				}
			}
		}

		$urls = array_keys($seen);
		$filtered = apply_filters('perfmatters_cache_warmer_urls', $urls);

		if(!is_array($filtered)) {
			$filtered = $urls;
		}

		//re-normalize after the filter so off-site hosts cannot enter the queue
		$filtered = self::normalize_urls($filtered);

		//recount after the filter so the settings badges still match the queue
		if($filtered !== $urls) {
			$counts = array_fill_keys(array_keys($counts), 0);

			foreach($filtered as $url) {
				if(isset($seen[$url])) {
					$counts[$seen[$url]]++;
				}
			}
		}

		self::$collection[$key] = array(
			'urls' => $filtered,
			'counts' => $counts
		);

		return self::$collection[$key];
	}

	//one anonymous request. when our disk cache is active, the file must exist too
	private static function fetch($url)
	{
		if(!self::fetch_device($url, false)) {
			return false;
		}

		//device-specific Perfmatters HTML needs a second pass with a mobile user agent
		if(!empty(\Perfmatters\Config::$options['cache']['page_mobile']) && Dropin::is_active()) {
			self::pause();
			return self::fetch_device($url, true);
		}

		return true;
	}

	//desktop or mobile warmer hit. avoid browser-spoof UAs (WAFs/CF often 520 origin→public fetches)
	private static function fetch_device($url, $mobile)
	{
		//Mobile token + Sec-CH-UA-Mobile trip perfmatters_cache_is_mobile() / wp_is_mobile() for -mobile files
		$ua = $mobile ? 'Perfmatters/Warmer Mobile' : 'Perfmatters/Warmer';
		$ua = apply_filters('perfmatters_cache_warmer_user_agent', $ua, $mobile);

		if(!is_string($ua) || $ua === '') {
			$ua = $mobile ? 'Perfmatters/Warmer Mobile' : 'Perfmatters/Warmer';
		}

		$args = array(
			'timeout' => 20,
			'cookies' => array(),
			'user-agent' => $ua,
			'headers' => array(
				'Cache-Control' => 'no-cache'
			)
		);

		if($mobile) {
			$args['headers']['Sec-CH-UA-Mobile'] = '?1';
		}

		$response = null;

		//disk on: loopback first so a CDN edge HIT cannot skip the origin write; public URL is the fallback
		foreach(self::fetch_targets($url) as $target) {
			$request = $args;
			$request['headers'] = array_merge($args['headers'], $target['headers']);
			$request['sslverify'] = $target['sslverify'];
			$request['redirection'] = $target['redirection'];

			//wp_safe_remote_get blocks private IPs; loopback must use wp_remote_get
			$response = $target['safe']
				? wp_safe_remote_get($target['url'], $request)
				: wp_remote_get($target['url'], $request);

			if(!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
				break;
			}

			$response = null;
		}

		if($response === null || is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
			return false;
		}

		//host caches and CDNs store on visit. our drop-in needs the html file on disk
		if(Dropin::is_active()) {
			return Storage::is_cached($url, $mobile);
		}

		return true;
	}

	//URLs the warmer tries, in order. loopback only when our disk drop-in is active (needs an origin write)
	private static function fetch_targets($url)
	{
		$warm = add_query_arg('perfmatters_warmer', '1', $url);
		$targets = array();
		$host = Config::site_host();
		$parsed = wp_parse_url($warm);

		//warmer-only / host / CF: public URL fills the front layer. disk: loopback forces the drop-in write
		if(Dropin::is_active() && $host !== '' && is_array($parsed) && apply_filters('perfmatters_cache_warmer_loopback', true)) {
			$path = (isset($parsed['path']) && is_string($parsed['path']) && $parsed['path'] !== '') ? $parsed['path'] : '/';
			$query = (!empty($parsed['query']) && is_string($parsed['query'])) ? '?' . $parsed['query'] : '';
			$headers = array(
				'Host' => $host
			);

			//advanced-cache (and Warmer::force_ssl_for_request) flip is_ssl() so cached HTML keeps https:// assets
			if(!empty($parsed['scheme']) && strtolower((string) $parsed['scheme']) === 'https') {
				$headers['X-Perfmatters-Warmer-HTTPS'] = '1';
			}

			$targets[] = array(
				'url' => 'http://127.0.0.1' . $path . $query,
				'headers' => $headers,
				'sslverify' => false,
				'safe' => false,
				//do not follow http→https off the box onto the CDN
				'redirection' => 0
			);
		}

		$targets[] = array(
			'url' => $warm,
			'headers' => array(),
			'sslverify' => self::sslverify(),
			'safe' => true,
			'redirection' => 3
		);

		return $targets;
	}

	//TLS verify for warmer page fetches and the ajax handoff. filter false on hosts with a bad local cert
	private static function sslverify()
	{
		return (bool) apply_filters('perfmatters_cache_warmer_sslverify', true);
	}

	//gap between page fetches so a long queue does not generate pages back to back
	private static function pause()
	{
		$delay = apply_filters('perfmatters_cache_warmer_delay', 0.5);

		if(!is_numeric($delay)) {
			$delay = 0.5;
		}

		$delay = (float) $delay;

		if($delay < 0) {
			$delay = 0;
		}

		if($delay > 5) {
			$delay = 5;
		}

		if($delay > 0) {
			usleep((int) round($delay * 1000000));
		}
	}

	//fire the next batch without waiting for it
	private static function dispatch($token)
	{
		$ajax = admin_url('admin-ajax.php');
		$host = Config::site_host();
		$parsed = wp_parse_url($ajax);
		$path = (is_array($parsed) && !empty($parsed['path']) && is_string($parsed['path'])) ? $parsed['path'] : '/wp-admin/admin-ajax.php';
		$body = array(
			'action' => 'perfmatters_cache_warmer',
			'token' => $token
		);

		//wp_remote_post(blocking=false) still waits ~1s for a response/timeout; write and close instead
		if($host !== '' && apply_filters('perfmatters_cache_warmer_loopback', true) && self::dispatch_loopback($host, $path, $body)) {
			return;
		}

		//batch() answers 204 immediately, so a short blocking call returns without waiting on page fetches
		wp_remote_post($ajax, array(
			'timeout' => 2,
			'blocking' => true,
			'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
			'cookies' => array(),
			'body' => $body
		));
	}

	//POST to 127.0.0.1:80 — avoids the WP HTTP non-blocking stall when :80 reaches PHP
	//HTTPS-only hosts 301 this; a non-2xx lets dispatch() fall back to public admin-ajax
	//mod_php often holds the status line until the batch ends (no fastcgi_finish_request) — waiting
	//for 2xx there stalls Clear Cache / chain handoff into the wp_remote_post fallback (~2s)
	private static function dispatch_loopback($host, $path, $body)
	{
		$payload = http_build_query($body);
		$fp = @fsockopen('127.0.0.1', 80, $errno, $errstr, 0.5);

		if(!$fp) {
			return false;
		}

		$request = "POST {$path} HTTP/1.1\r\n";
		$request .= "Host: {$host}\r\n";
		$request .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$request .= 'Content-Length: ' . strlen($payload) . "\r\n";
		$request .= "Connection: Close\r\n\r\n";
		$request .= $payload;

		if(fwrite($fp, $request) === false) {
			fclose($fp);
			return false;
		}

		//long enough for a quick 204 (FPM) or 301 (HTTPS-only); short enough not to feel like the spinner
		stream_set_timeout($fp, 0, 50000);
		$status = '';
		$line = fgets($fp, 512);

		if(is_string($line)) {
			$status = trim($line);
		}

		fclose($fp);

		if($status !== '' && (bool) preg_match('/^HTTP\/\S+\s+2\d\d\b/', $status)) {
			return true;
		}

		if($status !== '' && (bool) preg_match('/^HTTP\/\S+\s+[345]\d\d\b/', $status)) {
			return false;
		}

		//no status yet — mod_php is likely already running the batch in another worker; treat as
		//dispatched. FPM should have answered, so empty there means try the public HTTPS fallback.
		return !function_exists('fastcgi_finish_request');
	}

	//close the ajax response so the parent dispatch returns; batch work continues in this process
	private static function release_dispatcher()
	{
		ignore_user_abort(true);

		if(function_exists('fastcgi_finish_request')) {
			if(!headers_sent()) {
				status_header(204);
				header('Content-Length: 0');
				header('Connection: close');
			}

			while(ob_get_level() > 0) {
				ob_end_flush();
			}

			flush();
			fastcgi_finish_request();
			return;
		}

		if(headers_sent()) {
			return;
		}

		status_header(204);
		header('Content-Length: 0');
		header('Connection: close');

		while(ob_get_level() > 0) {
			ob_end_flush();
		}

		flush();
	}

	private static function state()
	{
		$state = get_option(self::STATE, array());

		if(!is_array($state)) {
			$state = array();
		}

		return wp_parse_args($state, array(
			'running' => 0,
			'token' => '',
			'lock' => 0,
			'last_run' => 0
		));
	}

	private static function save_state($state)
	{
		if(get_option(self::STATE, null) === null) {
			add_option(self::STATE, $state, '', false);
			return;
		}

		update_option(self::STATE, $state, false);
	}

	//normalize a list of urls into the same form as the warmer list
	public static function normalize_urls($urls)
	{
		$clean = array();

		foreach((array) $urls as $url) {
			if(!is_string($url)) {
				continue;
			}

			$url = self::normalize($url);

			if($url !== '') {
				$clean[] = $url;
			}
		}

		return array_values(array_unique($clean));
	}

	//drop excluded paths so the queue does not wait on a page that will never be stored
	private static function allowed_urls($urls)
	{
		$urls = self::normalize_urls($urls);
		Storage::load_runtime();
		$excluded = Config::excluded_paths();

		return array_values(array_filter($urls, function($url) use ($excluded) {
			$path = wp_parse_url($url, PHP_URL_PATH);

			if(!is_string($path) || $path === '') {
				$path = '/';
			}

			return !perfmatters_cache_path_excluded($path, $excluded);
		}));
	}

	//same host and trailing slash for every url in the list. off-site hosts are dropped
	//Cache Query Strings params are kept (sorted) so the warmer hits the same q- files disk uses
	private static function normalize($url)
	{
		$home = wp_parse_url(home_url('/'));
		$parts = wp_parse_url($url);
		$site_host = Config::site_host();

		if(empty($parts['host']) || $site_host === '') {
			return '';
		}

		$host = strtolower((string) $parts['host']);

		if($host !== $site_host) {
			return '';
		}

		$path = isset($parts['path']) ? $parts['path'] : '/';
		$path = user_trailingslashit(untrailingslashit($path));

		if($path === '') {
			$path = '/';
		}

		$scheme = !empty($home['scheme']) ? $home['scheme'] : 'https';
		$normalized = $scheme . '://' . $host . $path;
		$query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';

		if($query === '') {
			return $normalized;
		}

		Storage::load_runtime();
		$args = perfmatters_cache_query_args_from_string($query);

		if(!is_array($args) || empty($args)) {
			return $normalized;
		}

		$kept = perfmatters_cache_filter_allowed_query_args($args, Config::ignored_queries(), Config::cache_queries());

		if(empty($kept)) {
			return $normalized;
		}

		return $normalized . '?' . http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
	}
}
