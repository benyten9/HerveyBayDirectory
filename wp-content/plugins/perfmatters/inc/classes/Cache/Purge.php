<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class Purge
{
	private static $scheduled = false;
	private static $full = false;
	private static $delete = array();
	private static $warm = array();
	private static $extra = array();
	private static $forget = array();
	private static $previous = array();
	private static $terms = array();
	//host/CF already sent on this request (before redirect). flush() skips or diffs them
	private static $edge_done = false;
	private static $edge_urls = array();
	//manual clear_url() target — flush() fires clear_url_after after disk delete (not for content-edit purges)
	private static $cleared_url = '';

	//content edits purge the changed url and the archives that list it.
	//active theme switch is the one site-chrome case we clear everything for (menus/widgets/updates stay manual)
	public static function init()
	{
		add_action('pre_post_update', array(__CLASS__, 'remember'));
		add_action('save_post', array(__CLASS__, 'on_save_post'), 20, 2);
		add_action('before_delete_post', array(__CLASS__, 'on_delete'), 10, 2);
		add_action('set_object_terms', array(__CLASS__, 'on_terms'), 10, 6);
		add_action('edit_terms', array(__CLASS__, 'remember_term'), 10, 3);
		add_action('edited_term', array(__CLASS__, 'on_edit_term'), 10, 4);
		add_action('pre_delete_term', array(__CLASS__, 'on_delete_term'), 10, 2);
		add_action('transition_comment_status', array(__CLASS__, 'on_comment_status'), 10, 3);
		add_action('edit_comment', array(__CLASS__, 'on_comment_edit'));
		add_action('switch_theme', array(__CLASS__, 'clear'));
		add_action('perfmatters_cache_expire', array(__CLASS__, 'expire'));
		add_action('init', array(__CLASS__, 'schedule_expire'), 30);

		//WooCommerce stock/price via CRUD / REST
		add_action('woocommerce_product_object_updated_props', array(__CLASS__, 'on_wc_product_updated_props'), 10, 2);
		add_action('woocommerce_product_set_stock', array(__CLASS__, 'on_wc_product'));
		add_action('woocommerce_variation_set_stock', array(__CLASS__, 'on_wc_product'));
		add_action('woocommerce_product_set_stock_status', array(__CLASS__, 'on_wc_product_id'), 10, 1);
		add_action('woocommerce_variation_set_stock_status', array(__CLASS__, 'on_wc_variation_stock_status'), 10, 1);
	}

	//hourly sweep for files the drop-in has not been asked for yet
	public static function schedule_expire()
	{
		if(!\Perfmatters\Cache::disk_enabled() || Config::cache_ttl() < 1) {
			self::unschedule_expire();
			return;
		}

		if(!wp_next_scheduled('perfmatters_cache_expire')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'perfmatters_cache_expire');
		}
	}

	//remove the sweep when the cache or the lifespan is turned off
	public static function unschedule_expire()
	{
		wp_clear_scheduled_hook('perfmatters_cache_expire');
	}

	//delete html files older than the lifespan
	public static function expire()
	{
		Storage::delete_expired(Config::cache_ttl());
	}

	//permalink and terms are still the previous values here. save_post only sees the new ones
	public static function remember($post_id)
	{
		if(self::ignored($post_id) || self::is_product_variation($post_id)) {
			return;
		}

		self::$previous[$post_id] = self::targets($post_id);
	}

	//published changes, including trash, purge the old url and the new one
	public static function on_save_post($post_id, $post)
	{
		if((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		if($post instanceof \WP_Post && $post->post_type === 'attachment') {
			if($post->post_parent) {
				self::queue_post((int) $post->post_parent);
			}
			return;
		}

		//variations share the parent product URL — purge that tree instead
		if(self::is_product_variation($post_id, $post)) {
			self::queue_wc_product($post instanceof \WP_Post ? (int) $post->post_parent : 0);
			return;
		}

		if(self::ignored($post_id, $post)) {
			return;
		}

		$old = isset(self::$previous[$post_id]) ? self::$previous[$post_id] : self::blank();
		$new = self::targets($post_id);

		if(empty($old['delete']) && empty($new['delete'])) {
			return;
		}

		list($delete, $warm) = self::with_listed_urls(
			array_merge($old['delete'], $new['delete']),
			array_merge($old['shared'], $new['warm'])
		);

		self::partial(
			$delete,
			$warm,
			array_merge($old['dates'], $new['dates']),
			array_diff($old['own'], $new['own'])
		);
	}

	//the post still exists, so its permalink and terms can be collected
	public static function on_delete($post_id, $post = null)
	{
		if(wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		if(!$post instanceof \WP_Post) {
			$post = get_post($post_id);
		}

		if(!$post instanceof \WP_Post) {
			return;
		}

		if($post->post_type === 'attachment') {
			if($post->post_parent) {
				self::queue_post((int) $post->post_parent);
			}
			return;
		}

		if($post->post_type === 'product_variation') {
			self::queue_wc_product((int) $post->post_parent);
			return;
		}

		if($post->post_type === 'nav_menu_item') {
			return;
		}

		$targets = isset(self::$previous[$post_id]) ? self::$previous[$post_id] : self::targets($post_id);

		if(empty($targets['delete'])) {
			return;
		}

		list($delete, $warm) = self::with_listed_urls($targets['delete'], $targets['shared']);

		self::partial($delete, $warm, $targets['dates'], $targets['own']);
	}

	//term changes can arrive after save_post, so collect the old and new archives here too
	public static function on_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
	{
		$post = get_post($object_id);

		if(!$post instanceof \WP_Post || self::ignored($object_id, $post) || !self::is_public_post($post)) {
			return;
		}

		$tax_obj = get_taxonomy($taxonomy);

		if(!$tax_obj || empty($tax_obj->public)) {
			return;
		}

		$urls = self::term_links_from_ids($tt_ids, $old_tt_ids);
		$targets = self::targets($object_id);
		list($delete, $warm) = self::with_listed_urls(
			array_merge($urls, $targets['delete']),
			array_merge($urls, $targets['warm'])
		);

		self::partial(
			$delete,
			$warm,
			$targets['dates'],
			array()
		);
	}

	//the link is still the old slug here. edited_term sees the saved one
	public static function remember_term($term_id, $taxonomy, $args)
	{
		$url = self::term_archive_url($term_id, $taxonomy);

		if($url === '') {
			return;
		}

		self::$terms[(int) $term_id] = $url;
	}

	//one archive. posts that print the term name wait until one of those posts is saved
	public static function on_edit_term($term_id, $tt_id, $taxonomy, $args)
	{
		$old = isset(self::$terms[(int) $term_id]) ? self::$terms[(int) $term_id] : '';
		$new = self::term_archive_url($term_id, $taxonomy);
		unset(self::$terms[(int) $term_id]);

		if($old === '' && $new === '') {
			return;
		}

		$delete = array();

		if($old !== '') {
			$delete[] = $old;
		}

		if($new !== '') {
			$delete[] = $new;
		}

		$warm = $new !== '' ? array($new) : array();
		$forget = $old !== '' && $old !== $new ? array($old) : array();

		self::partial($delete, $warm, array(), $forget);
	}

	//the term row still exists, so the archive file can be removed before the drop-in keeps serving it
	public static function on_delete_term($term_id, $taxonomy)
	{
		$url = self::term_archive_url($term_id, $taxonomy);

		if($url === '') {
			return;
		}

		self::partial(array($url), array(), array(), array($url));
	}

	//public archives only (includes product_cat / product_tag when WooCommerce is active)
	private static function term_archive_url($term_id, $taxonomy)
	{
		$taxonomy = (string) $taxonomy;
		$tax_obj = get_taxonomy($taxonomy);

		if(!$tax_obj || empty($tax_obj->public)) {
			return '';
		}

		$term = get_term((int) $term_id, $taxonomy);

		if(!$term instanceof \WP_Term) {
			return '';
		}

		$link = get_term_link($term);

		if(is_wp_error($link) || !is_string($link) || $link === '') {
			return '';
		}

		return $link;
	}

	//approved comments show on the post and on the archives that list it
	public static function on_comment_status($new_status, $old_status, $comment)
	{
		if($new_status === $old_status || ($new_status !== 'approved' && $old_status !== 'approved')) {
			return;
		}

		if($comment instanceof \WP_Comment) {
			self::queue_post((int) $comment->comment_post_ID);
		}
	}

	//editing the text does not change status, so the transition hook never runs
	public static function on_comment_edit($comment_id)
	{
		$comment = get_comment($comment_id);

		if($comment instanceof \WP_Comment && $comment->comment_approved === '1') {
			self::queue_post((int) $comment->comment_post_ID);
		}
	}

	//manual clear from the settings screen or the admin bar
	public static function handle_clear()
	{
		self::authorize_admin_post('perfmatters_cache_clear');

		self::clear();
		set_transient('perfmatters_cache_cleared', 1, MINUTE_IN_SECONDS);

		self::redirect_after_admin_post();
	}

	//admin bar clear for the page being viewed. shutdown still flushes after the redirect
	public static function handle_clear_url()
	{
		self::authorize_admin_post('perfmatters_cache_clear_url');

		$url = isset($_REQUEST['url']) ? wp_unslash($_REQUEST['url']) : '';

		if(!is_string($url)) {
			$url = '';
		}

		self::clear_url(esc_url_raw($url));

		self::redirect_after_admin_post();
	}

	private static function authorize_admin_post($nonce_action)
	{
		if(!current_user_can('manage_options') || (function_exists('perfmatters_network_access') && !perfmatters_network_access())) {
			wp_die(esc_html__('Sorry, you are not allowed to do that.', 'perfmatters'), 403);
		}

		check_admin_referer($nonce_action);
	}

	private static function redirect_after_admin_post()
	{
		wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('options-general.php?page=perfmatters#cache'));
		exit;
	}

	//settings toolbar Clear Cache (data-pm-action) — toast message + cards from progress; warmer may start when enabled
	public static function ajax_clear()
	{
		\Perfmatters\Ajax::security_check();

		if(function_exists('perfmatters_network_access') && !perfmatters_network_access()) {
			wp_send_json_error(array(
				'message' => __('Permission denied.', 'perfmatters')
			));
		}

		self::clear();
		//run now so disk stats / warmer state are current before the JSON response (shutdown would be too late)
		self::flush();

		wp_send_json_success(array(
			'message' => self::cleared_message(),
			'progress' => Warmer::progress()
		));
	}

	//toast / admin notice after a full clear — match what this request could actually wipe
	//$warmer_started null = detect from warmer state after flush (toggle on with an empty queue must not claim a start)
	public static function cleared_message($warmer_started = null)
	{
		$disk = \Perfmatters\Cache::disk_enabled();
		$cf = Cloudflare::is_ready();

		if($disk && $cf) {
			$message = __('Page cache and Cloudflare cleared.', 'perfmatters');
		}
		elseif($cf) {
			$message = __('Cloudflare cleared.', 'perfmatters');
		}
		else {
			$message = __('Page cache cleared.', 'perfmatters');
		}

		if($warmer_started === null) {
			$warmer_started = Warmer::is_running();
		}

		if($warmer_started) {
			$message .= ' ' . __('Warmer started.', 'perfmatters');
		}

		return $message;
	}

	//delete one url and warm it again. other cached pages stay
	public static function clear_url($url)
	{
		if(Warmer::is_request() || self::$full) {
			return false;
		}

		$urls = Warmer::normalize_urls(array($url));

		if(count($urls) !== 1) {
			return false;
		}

		$host = strtolower((string) wp_parse_url($urls[0], PHP_URL_HOST));

		if($host === '' || $host !== Config::site_host()) {
			return false;
		}

		$url = $urls[0];

		do_action('perfmatters_cache_clear_url_before', $url);

		//host + CF once on this response (before admin redirect). flush() diffs filter-added urls
		HostPurge::clear_urls($urls);
		Cloudflare::purge_urls($urls);
		self::$edge_urls = array_merge(self::$edge_urls, $urls);
		self::$cleared_url = $url;
		self::partial($urls, $urls, array(), array());

		return true;
	}

	public static function clear()
	{
		//this request is building a page for the queue. clearing here would restart it forever
		if(Warmer::is_request()) {
			return;
		}

		do_action('perfmatters_cache_clear_before');

		self::$full = true;
		self::$cleared_url = '';
		//host purge headers must be sent on this response, so the host clear cannot wait for shutdown
		HostPurge::clear();
		//CF once here too — flush() will not call them again
		Cloudflare::purge_everything();
		self::$edge_done = true;
		self::schedule();
	}

	//run once, after every save_post and set_object_terms in this request have been seen
	public static function flush()
	{
		if(Warmer::is_request()) {
			return;
		}

		$full = self::$full;
		$delete = self::$delete;
		$warm = self::$warm;
		$extra = self::$extra;
		$forget = self::$forget;
		$edge_done = self::$edge_done;
		$edge_urls = self::$edge_urls;
		$cleared_url = self::$cleared_url;
		self::$scheduled = false;
		self::$full = false;
		self::$delete = array();
		self::$warm = array();
		self::$extra = array();
		self::$forget = array();
		self::$edge_done = false;
		self::$edge_urls = array();
		self::$cleared_url = '';

		if($full) {
			Storage::clear();

			if(!$edge_done) {
				HostPurge::clear();
				Cloudflare::purge_everything();
			}

			do_action('perfmatters_cache_clear_after');

			//warmer is off, so nothing will mark these rows done again
			if(empty(\Perfmatters\Config::$options['cache']['warmer'])) {
				Queue::mark_pending();
			}

			Warmer::start();
			return;
		}

		if(empty($delete) && empty($warm) && empty($extra) && empty($forget)) {
			return;
		}

		$delete = Warmer::normalize_urls($delete);
		$filtered = apply_filters('perfmatters_cache_purge_urls', $delete);

		if(!is_array($filtered)) {
			$filtered = $delete;
		}

		$filtered = Warmer::normalize_urls($filtered);
		$added = array_diff($filtered, $delete);
		//manual clear_url uses clear_url_*; content-edit batches use clear_urls_*
		$batch = ($cleared_url === '');

		if($batch) {
			do_action('perfmatters_cache_clear_urls_before', $filtered);
		}

		Storage::delete_urls($filtered);

		//content edits: purge all. manual URL clear: only urls the early pass did not already send
		$pending = array_values(array_diff($filtered, Warmer::normalize_urls($edge_urls)));

		if(!empty($pending)) {
			HostPurge::clear_urls($pending);
			Cloudflare::purge_urls($pending);
		}

		if($batch) {
			do_action('perfmatters_cache_clear_urls_after', $filtered);
		}
		elseif($cleared_url !== '') {
			do_action('perfmatters_cache_clear_url_after', $cleared_url);
		}

		Warmer::start(array_merge($warm, $added), $forget, $extra);
	}

	//home, the post, its terms, author, and post type archive. dates are warmed but kept off the progress total
	private static function targets($post_id)
	{
		$blank = self::blank();
		$post = get_post($post_id);

		if(!$post instanceof \WP_Post || !self::is_public_post($post)) {
			return $blank;
		}

		$shared = array(home_url('/'));
		$own = array();
		$dates = array();

		if($post->post_type === 'post') {
			$posts_page = (int) get_option('page_for_posts');

			if($posts_page > 0) {
				$shared[] = get_permalink($posts_page);
			}

			$dates = self::date_urls($post);
		}

		if($post->post_type !== 'page') {
			$archive = get_post_type_archive_link($post->post_type);

			if(is_string($archive) && $archive !== '') {
				$shared[] = $archive;
			}

			if(post_type_supports($post->post_type, 'author')) {
				$shared[] = get_author_posts_url((int) $post->post_author);
			}
		}

		if(is_post_type_hierarchical($post->post_type)) {
			foreach(get_post_ancestors($post) as $ancestor_id) {
				$shared[] = get_permalink($ancestor_id);
			}
		}

		$shared = array_merge($shared, self::term_urls($post));
		$permalink = get_permalink($post);

		if(is_string($permalink) && $permalink !== '' && $post->post_password === '') {
			$own[] = $permalink;
		}

		$delete = array_merge($own, $shared, $dates);

		if(is_string($permalink) && $permalink !== '') {
			$delete[] = $permalink;
		}

		return array(
			'own' => Warmer::normalize_urls($own),
			'shared' => Warmer::normalize_urls($shared),
			'dates' => Warmer::normalize_urls($dates),
			'warm' => Warmer::normalize_urls(array_merge($own, $shared)),
			'delete' => Warmer::normalize_urls($delete)
		);
	}

	private static function queue_post($post_id)
	{
		$targets = self::targets($post_id);

		if(empty($targets['delete'])) {
			return;
		}

		list($delete, $warm) = self::with_listed_urls($targets['delete'], $targets['warm']);

		self::partial($delete, $warm, $targets['dates'], array());
	}

	//pages that list posts but are not the home page or an archive
	private static function with_listed_urls($delete, $warm)
	{
		$listed = Config::purge_urls();

		if(empty($listed)) {
			return array($delete, $warm);
		}

		return array(
			array_merge($delete, $listed),
			array_merge($warm, $listed)
		);
	}

	private static function partial($delete, $warm, $extra, $forget)
	{
		if(Warmer::is_request() || self::$full) {
			return;
		}

		self::$delete = array_merge(self::$delete, $delete);
		self::$warm = array_merge(self::$warm, $warm);
		self::$extra = array_merge(self::$extra, $extra);
		self::$forget = array_merge(self::$forget, $forget);
		self::schedule();
	}

	private static function schedule()
	{
		if(self::$scheduled) {
			return;
		}

		self::$scheduled = true;
		add_action('shutdown', array(__CLASS__, 'flush'), 0);
	}

	private static function term_urls($post)
	{
		$urls = array();
		$taxonomies = get_object_taxonomies($post->post_type, 'objects');

		foreach($taxonomies as $taxonomy) {
			if(empty($taxonomy->public)) {
				continue;
			}

			$terms = get_the_terms($post, $taxonomy->name);

			if(!is_array($terms)) {
				continue;
			}

			foreach($terms as $term) {
				$link = get_term_link($term);

				if(!is_wp_error($link) && is_string($link)) {
					$urls[] = $link;
				}
			}
		}

		return $urls;
	}

	private static function term_links_from_ids($tt_ids, $old_tt_ids)
	{
		$urls = array();

		foreach(array_merge((array) $tt_ids, (array) $old_tt_ids) as $tt_id) {
			$term = get_term_by('term_taxonomy_id', (int) $tt_id);

			if(!$term || is_wp_error($term)) {
				continue;
			}

			$link = get_term_link($term);

			if(!is_wp_error($link) && is_string($link)) {
				$urls[] = $link;
			}
		}

		return $urls;
	}

	private static function date_urls($post)
	{
		$year = (int) get_the_date('Y', $post);
		$month = (int) get_the_date('m', $post);
		$day = (int) get_the_date('d', $post);

		if($year < 1 || $month < 1 || $day < 1) {
			return array();
		}

		return array(
			get_year_link($year),
			get_month_link($year, $month),
			get_day_link($year, $month, $day)
		);
	}

	private static function is_public_post($post)
	{
		$object = get_post_type_object($post->post_type);

		if(!$object || empty($object->public)) {
			return false;
		}

		//variations are handled via the parent product; products themselves are cacheable
		if(in_array($post->post_type, array('product_variation', 'attachment', 'nav_menu_item'), true)) {
			return false;
		}

		$statuses = get_post_stati(array('public' => true));

		return isset($statuses[$post->post_status]);
	}

	private static function ignored($post_id, $post = null)
	{
		if((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return true;
		}

		if(!$post instanceof \WP_Post) {
			$post = get_post($post_id);
		}

		if(!$post instanceof \WP_Post || $post->post_status === 'auto-draft') {
			return true;
		}

		return in_array($post->post_type, array('product_variation', 'attachment', 'nav_menu_item', 'revision'), true);
	}

	//stock / price props changed on a WC_Product (including REST / CRUD without a full editor save)
	public static function on_wc_product_updated_props($product, $updated_props)
	{
		if(!is_object($product) || !is_array($updated_props)) {
			return;
		}

		$watch = array('regular_price', 'sale_price', 'price', 'stock_quantity', 'stock_status', 'manage_stock');

		if(!array_intersect($watch, $updated_props)) {
			return;
		}

		self::queue_wc_product_object($product);
	}

	//woocommerce_product_set_stock / woocommerce_variation_set_stock
	public static function on_wc_product($product)
	{
		self::queue_wc_product_object($product);
	}

	//woocommerce_product_set_stock_status — first arg is product id
	public static function on_wc_product_id($product_id)
	{
		self::queue_wc_product((int) $product_id);
	}

	//woocommerce_variation_set_stock_status — purge the parent product page
	public static function on_wc_variation_stock_status($variation_id)
	{
		$variation_id = (int) $variation_id;

		if($variation_id < 1) {
			return;
		}

		$parent = (int) wp_get_post_parent_id($variation_id);
		self::queue_wc_product($parent > 0 ? $parent : $variation_id);
	}

	private static function queue_wc_product_object($product)
	{
		if(!is_object($product) || !method_exists($product, 'get_id')) {
			return;
		}

		$id = (int) $product->get_id();

		if($id < 1) {
			return;
		}

		if(method_exists($product, 'get_parent_id')) {
			$parent = (int) $product->get_parent_id();

			if($parent > 0) {
				$id = $parent;
			}
		}

		self::queue_wc_product($id);
	}

	private static function queue_wc_product($product_id)
	{
		$product_id = (int) $product_id;

		if($product_id < 1) {
			return;
		}

		self::queue_post($product_id);
	}

	private static function is_product_variation($post_id, $post = null)
	{
		if(!$post instanceof \WP_Post) {
			$post = get_post($post_id);
		}

		return $post instanceof \WP_Post && $post->post_type === 'product_variation';
	}

	private static function blank()
	{
		return array(
			'own' => array(),
			'shared' => array(),
			'dates' => array(),
			'warm' => array(),
			'delete' => array()
		);
	}
}
