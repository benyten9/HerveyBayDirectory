<?php // phpcs:ignore

namespace SEOPressPro\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command palette index contributed by SEOPress PRO.
 *
 * Hooks into `seopress_command_palette_items` (registered by the free
 * plugin) to add PRO pages, PRO tabs and PRO-only settings to the native
 * WordPress command palette (Cmd/Ctrl+K).
 *
 * @since 9.8.0
 */
class CommandPaletteIndex {

	/**
	 * Register the filter that injects PRO commands.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'seopress_command_palette_items', array( __CLASS__, 'add_items' ) );
	}

	/**
	 * Append PRO commands to the list.
	 *
	 * @param array<int,array<string,mixed>> $commands Command list from free.
	 * @return array<int,array<string,mixed>>
	 */
	public static function add_items( $commands ) {
		// Page-level navigation commands are omitted: WordPress core
		// already generates "Go to: …" commands for every admin menu item.
		return array_merge(
			$commands,
			self::pro_tabs(),
			self::pro_fields()
		);
	}

	/**
	 * PRO tabs on the SEOPress PRO settings page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function pro_tabs() {
		$page = 'seopress-pro-page';
		return array(
			self::tab( 'pro/local-business', __( 'Local Business', 'wp-seopress-pro' ), array( 'local business', 'gmb', 'address', 'hours', 'schema' ), __( 'PRO › Local Business', 'wp-seopress-pro' ), $page, 'tab_seopress_local_business' ),
			self::tab( 'pro/ai', __( 'AI settings', 'wp-seopress-pro' ), array( 'ai', 'openai', 'gemini', 'generate', 'chatgpt' ), __( 'PRO › AI', 'wp-seopress-pro' ), $page, 'tab_seopress_ai' ),
			self::tab( 'pro/breadcrumbs', __( 'Breadcrumbs settings', 'wp-seopress-pro' ), array( 'breadcrumbs', 'navigation', 'schema' ), __( 'PRO › Breadcrumbs', 'wp-seopress-pro' ), $page, 'tab_seopress_breadcrumbs' ),
			self::tab( 'pro/woocommerce', __( 'WooCommerce SEO', 'wp-seopress-pro' ), array( 'woocommerce', 'ecommerce', 'shop', 'product' ), __( 'PRO › WooCommerce', 'wp-seopress-pro' ), $page, 'tab_seopress_woocommerce' ),
			self::tab( 'pro/edd', __( 'Easy Digital Downloads SEO', 'wp-seopress-pro' ), array( 'edd', 'easy digital downloads', 'ecommerce' ), __( 'PRO › EDD', 'wp-seopress-pro' ), $page, 'tab_seopress_edd' ),
			self::tab( 'pro/news', __( 'Google News sitemap', 'wp-seopress-pro' ), array( 'news', 'google news', 'sitemap', 'press' ), __( 'PRO › News', 'wp-seopress-pro' ), $page, 'tab_seopress_news' ),
			self::tab( 'pro/rss', __( 'RSS feed settings', 'wp-seopress-pro' ), array( 'rss', 'feed', 'atom' ), __( 'PRO › RSS', 'wp-seopress-pro' ), $page, 'tab_seopress_rss' ),
			self::tab( 'pro/robots', __( 'robots.txt editor', 'wp-seopress-pro' ), array( 'robots', 'robots.txt', 'crawler', 'disallow' ), __( 'PRO › robots.txt', 'wp-seopress-pro' ), $page, 'tab_seopress_robots' ),
			self::tab( 'pro/htaccess', __( '.htaccess editor', 'wp-seopress-pro' ), array( 'htaccess', 'apache', 'server', 'rewrite' ), __( 'PRO › .htaccess', 'wp-seopress-pro' ), $page, 'tab_seopress_htaccess' ),
			self::tab( 'pro/llms', __( 'LLMs.txt file', 'wp-seopress-pro' ), array( 'llms', 'llms.txt', 'ai', 'crawler', 'chatgpt' ), __( 'PRO › LLMs.txt', 'wp-seopress-pro' ), $page, 'tab_seopress_llms' ),
			self::tab( 'pro/alerts', __( 'SEO alerts', 'wp-seopress-pro' ), array( 'alerts', 'email', 'monitoring', 'watcher' ), __( 'PRO › Alerts', 'wp-seopress-pro' ), $page, 'tab_seopress_alerts' ),
			self::tab( 'pro/404', __( '404 monitoring', 'wp-seopress-pro' ), array( '404', 'monitoring', 'redirections', 'errors' ), __( 'PRO › 404 monitoring', 'wp-seopress-pro' ), $page, 'tab_seopress_404' ),
			self::tab( 'pro/page-speed', __( 'Page Speed (PageSpeed Insights)', 'wp-seopress-pro' ), array( 'page speed', 'pagespeed', 'psi', 'core web vitals', 'performance' ), __( 'PRO › Page Speed', 'wp-seopress-pro' ), $page, 'tab_seopress_page_speed' ),
			self::tab( 'pro/inspect-url', __( 'URL Inspection tool', 'wp-seopress-pro' ), array( 'inspect', 'url', 'search console', 'google' ), __( 'PRO › URL Inspection', 'wp-seopress-pro' ), $page, 'tab_seopress_inspect_url' ),
			// Dublin Core tab removed in 9.8.X.
		);
	}

	/**
	 * PRO-specific fields injected via ExtensionSlot into the free admin.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function pro_fields() {
		$pro = 'seopress-pro-page';

		return array_merge(
			self::injected_fields(),
			self::local_business_fields( $pro ),
			self::breadcrumbs_fields( $pro ),
			self::woocommerce_fields( $pro ),
			self::edd_fields( $pro ),
			self::news_fields( $pro ),
			self::rss_fields( $pro ),
			self::robots_fields( $pro ),
			self::llms_fields( $pro ),
			self::alerts_fields( $pro ),
			self::redirections_fields( $pro ),
			self::page_speed_fields( $pro ),
			self::ai_fields( $pro ),
			self::structured_data_fields( $pro ),
			// Dublin Core removed in 9.8.X.
			self::white_label_fields( $pro ),
			self::gsc_fields( $pro )
		);
	}

	/**
	 * Fields injected into the free plugin pages via ExtensionSlot.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function injected_fields() {
		return array(
			self::setting( 'pro/video-sitemap', __( 'Enable XML Video Sitemap', 'wp-seopress-pro' ), array( 'video', 'sitemap', 'xml', 'movie' ), __( 'XML Sitemap › General', 'wp-seopress-pro' ), 'seopress-xml-sitemap', 'tab_seopress_sitemaps_general', 'seopress_xml_sitemap_video_enable' ),
			self::setting( 'pro/rewrite-search', __( 'Search results base URL', 'wp-seopress-pro' ), array( 'search', 'rewrite', 'slug', 'permalink' ), __( 'Advanced › Advanced', 'wp-seopress-pro' ), 'seopress-advanced', 'tab_seopress_advanced_advanced', 'seopress_rewrite_search' ),
			self::setting( 'pro/appearance-ps-col', __( 'Page Speed column', 'wp-seopress-pro' ), array( 'page speed', 'column', 'performance' ), __( 'Advanced › Appearance', 'wp-seopress-pro' ), 'seopress-advanced', 'tab_seopress_advanced_appearance', 'seopress_advanced_appearance_ps_col' ),
			self::setting( 'pro/appearance-search-console', __( 'Search Console data column', 'wp-seopress-pro' ), array( 'search console', 'column', 'gsc' ), __( 'Advanced › Appearance', 'wp-seopress-pro' ), 'seopress-advanced', 'tab_seopress_advanced_appearance', 'seopress_advanced_appearance_search_console' ),
			self::setting( 'pro/appearance-livechat', __( 'Disable AI live chat', 'wp-seopress-pro' ), array( 'live chat', 'ai', 'disable', 'chatbot' ), __( 'Advanced › Appearance', 'wp-seopress-pro' ), 'seopress-advanced', 'tab_seopress_advanced_appearance', 'seopress_advanced_appearance_dashboard_livechat' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function local_business_fields( $page ) {
		$ctx = __( 'PRO › Local Business', 'wp-seopress-pro' );
		$tab = 'tab_seopress_local_business';
		return array(
			self::setting( 'pro/lb-type', __( 'Business type', 'wp-seopress-pro' ), array( 'business', 'type', 'schema' ), $ctx, $page, $tab, 'seopress_local_business_type' ),
			self::setting( 'pro/lb-street', __( 'Street Address', 'wp-seopress-pro' ), array( 'street', 'address' ), $ctx, $page, $tab, 'seopress_local_business_street_address' ),
			self::setting( 'pro/lb-city', __( 'City', 'wp-seopress-pro' ), array( 'city', 'locality' ), $ctx, $page, $tab, 'seopress_local_business_address_locality' ),
			self::setting( 'pro/lb-state', __( 'State', 'wp-seopress-pro' ), array( 'state', 'region' ), $ctx, $page, $tab, 'seopress_local_business_address_region' ),
			self::setting( 'pro/lb-postal', __( 'Postal code', 'wp-seopress-pro' ), array( 'postal', 'zip', 'code' ), $ctx, $page, $tab, 'seopress_local_business_postal_code' ),
			self::setting( 'pro/lb-country', __( 'Country', 'wp-seopress-pro' ), array( 'country' ), $ctx, $page, $tab, 'seopress_local_business_address_country' ),
			self::setting( 'pro/lb-phone', __( 'Telephone', 'wp-seopress-pro' ), array( 'phone', 'telephone', 'number' ), $ctx, $page, $tab, 'seopress_local_business_phone' ),
			self::setting( 'pro/lb-price', __( 'Price range', 'wp-seopress-pro' ), array( 'price', 'range', 'cost' ), $ctx, $page, $tab, 'seopress_local_business_price_range' ),
			self::setting( 'pro/lb-hours', __( 'Opening hours', 'wp-seopress-pro' ), array( 'opening', 'hours', 'schedule' ), $ctx, $page, $tab, 'seopress_local_business_opening_hours' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function breadcrumbs_fields( $page ) {
		$ctx = __( 'PRO › Breadcrumbs', 'wp-seopress-pro' );
		$tab = 'tab_seopress_breadcrumbs';
		return array(
			self::setting( 'pro/bc-enable', __( 'Enable Breadcrumbs', 'wp-seopress-pro' ), array( 'breadcrumbs', 'enable' ), $ctx, $page, $tab, 'seopress_breadcrumbs_enable' ),
			self::setting( 'pro/bc-json', __( 'Enable JSON-LD Breadcrumbs', 'wp-seopress-pro' ), array( 'json-ld', 'breadcrumbs', 'schema' ), $ctx, $page, $tab, 'seopress_breadcrumbs_json_enable' ),
			self::setting( 'pro/bc-sep', __( 'Breadcrumbs Separator', 'wp-seopress-pro' ), array( 'separator', 'breadcrumbs' ), $ctx, $page, $tab, 'seopress_breadcrumbs_separator' ),
			self::setting( 'pro/bc-sep-disable', __( 'Disable default breadcrumbs separator', 'wp-seopress-pro' ), array( 'separator', 'disable', 'breadcrumbs' ), $ctx, $page, $tab, 'seopress_breadcrumbs_separator_disable' ),
			self::setting( 'pro/bc-cpt', __( 'Post type to show in Breadcrumbs', 'wp-seopress-pro' ), array( 'post type', 'breadcrumbs' ), $ctx, $page, $tab, 'seopress_breadcrumbs_cpt' ),
			self::setting( 'pro/bc-tax', __( 'Taxonomy to show in Breadcrumbs', 'wp-seopress-pro' ), array( 'taxonomy', 'breadcrumbs' ), $ctx, $page, $tab, 'seopress_breadcrumbs_tax' ),
			self::setting( 'pro/bc-no-blog', __( 'Remove Posts page', 'wp-seopress-pro' ), array( 'posts page', 'blog', 'remove' ), $ctx, $page, $tab, 'seopress_breadcrumbs_remove_blog_page' ),
			self::setting( 'pro/bc-no-shop', __( 'Remove Shop page', 'wp-seopress-pro' ), array( 'shop', 'woocommerce', 'remove' ), $ctx, $page, $tab, 'seopress_breadcrumbs_remove_shop_page' ),
			self::setting( 'pro/bc-storefront', __( 'Storefront compatibility', 'wp-seopress-pro' ), array( 'storefront', 'theme' ), $ctx, $page, $tab, 'seopress_breadcrumbs_storefront' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function woocommerce_fields( $page ) {
		$ctx = __( 'PRO › WooCommerce', 'wp-seopress-pro' );
		$tab = 'tab_seopress_woocommerce';
		return array(
			self::setting( 'pro/wc-cart', __( 'Cart page noindex', 'wp-seopress-pro' ), array( 'cart', 'noindex', 'woocommerce' ), $ctx, $page, $tab, 'seopress_woocommerce_cart_page_no_index' ),
			self::setting( 'pro/wc-checkout', __( 'Checkout page noindex', 'wp-seopress-pro' ), array( 'checkout', 'noindex', 'woocommerce' ), $ctx, $page, $tab, 'seopress_woocommerce_checkout_page_no_index' ),
			self::setting( 'pro/wc-account', __( 'Customer account pages noindex', 'wp-seopress-pro' ), array( 'account', 'noindex', 'customer' ), $ctx, $page, $tab, 'seopress_woocommerce_customer_account_page_no_index' ),
			self::setting( 'pro/wc-og-price', __( 'OG Price', 'wp-seopress-pro' ), array( 'open graph', 'price', 'product' ), $ctx, $page, $tab, 'seopress_woocommerce_product_og_price' ),
			self::setting( 'pro/wc-og-currency', __( 'OG Currency', 'wp-seopress-pro' ), array( 'open graph', 'currency', 'product' ), $ctx, $page, $tab, 'seopress_woocommerce_product_og_currency' ),
			self::setting( 'pro/wc-generator', __( 'Remove WooCommerce generator tag', 'wp-seopress-pro' ), array( 'generator', 'meta', 'remove' ), $ctx, $page, $tab, 'seopress_woocommerce_meta_generator' ),
			self::setting( 'pro/wc-schema', __( 'Remove WooCommerce Schemas', 'wp-seopress-pro' ), array( 'schema', 'structured data', 'remove' ), $ctx, $page, $tab, 'seopress_woocommerce_schema_output' ),
			self::setting( 'pro/wc-schema-breadcrumbs', __( 'Remove WooCommerce breadcrumbs schemas', 'wp-seopress-pro' ), array( 'schema', 'breadcrumbs', 'remove', 'woocommerce' ), $ctx, $page, $tab, 'seopress_woocommerce_schema_breadcrumbs_output' ),
			self::setting( 'pro/wc-return-policy', __( 'Enable return policy (Schema.org)', 'wp-seopress-pro' ), array( 'return policy', 'merchant', 'schema', 'refund' ), $ctx, $page, $tab, 'seopress_woocommerce_return_policy_enable' ),
			self::setting( 'pro/wc-return-policy-category', __( 'Return policy category', 'wp-seopress-pro' ), array( 'return policy', 'category', 'merchant' ), $ctx, $page, $tab, 'seopress_woocommerce_return_policy_category' ),
			self::setting( 'pro/wc-return-policy-days', __( 'Return window (days)', 'wp-seopress-pro' ), array( 'return policy', 'days', 'window', 'merchant' ), $ctx, $page, $tab, 'seopress_woocommerce_return_policy_days' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function edd_fields( $page ) {
		$ctx = __( 'PRO › EDD', 'wp-seopress-pro' );
		$tab = 'tab_seopress_edd';
		return array(
			self::setting( 'pro/edd-og-price', __( 'OG Price (EDD)', 'wp-seopress-pro' ), array( 'open graph', 'price', 'edd' ), $ctx, $page, $tab, 'seopress_edd_product_og_price' ),
			self::setting( 'pro/edd-og-currency', __( 'OG Currency (EDD)', 'wp-seopress-pro' ), array( 'open graph', 'currency', 'edd' ), $ctx, $page, $tab, 'seopress_edd_product_og_currency' ),
			self::setting( 'pro/edd-generator', __( 'Remove EDD generator tag', 'wp-seopress-pro' ), array( 'generator', 'edd', 'remove' ), $ctx, $page, $tab, 'seopress_edd_meta_generator' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function news_fields( $page ) {
		$ctx = __( 'PRO › News', 'wp-seopress-pro' );
		$tab = 'tab_seopress_news';
		return array(
			self::setting( 'pro/news-enable', __( 'Enable Google News Sitemap', 'wp-seopress-pro' ), array( 'google news', 'sitemap', 'enable' ), $ctx, $page, $tab, 'seopress_news_enable' ),
			self::setting( 'pro/news-name', __( 'Publication Name', 'wp-seopress-pro' ), array( 'publication', 'name', 'news' ), $ctx, $page, $tab, 'seopress_news_name' ),
			self::setting( 'pro/news-cpt', __( 'Post types in Google News Sitemap', 'wp-seopress-pro' ), array( 'post types', 'include', 'news' ), $ctx, $page, $tab, 'seopress_news_name_post_types_list' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rss_fields( $page ) {
		$ctx = __( 'PRO › RSS', 'wp-seopress-pro' );
		$tab = 'tab_seopress_rss';
		return array(
			self::setting( 'pro/rss-before', __( 'Content before each post (RSS)', 'wp-seopress-pro' ), array( 'rss', 'before', 'content' ), $ctx, $page, $tab, 'seopress_rss_before_html' ),
			self::setting( 'pro/rss-after', __( 'Content after each post (RSS)', 'wp-seopress-pro' ), array( 'rss', 'after', 'content' ), $ctx, $page, $tab, 'seopress_rss_after_html' ),
			self::setting( 'pro/rss-thumb', __( 'Add post thumbnail (RSS)', 'wp-seopress-pro' ), array( 'thumbnail', 'image', 'rss' ), $ctx, $page, $tab, 'seopress_rss_post_thumbnail' ),
			self::setting( 'pro/rss-disable-comments', __( 'Disable comments RSS feed', 'wp-seopress-pro' ), array( 'disable', 'comments', 'feed' ), $ctx, $page, $tab, 'seopress_rss_disable_comments_feed' ),
			self::setting( 'pro/rss-disable-posts', __( 'Disable posts RSS feed', 'wp-seopress-pro' ), array( 'disable', 'posts', 'feed' ), $ctx, $page, $tab, 'seopress_rss_disable_posts_feed' ),
			self::setting( 'pro/rss-disable-extra', __( 'Disable extra RSS feed', 'wp-seopress-pro' ), array( 'disable', 'extra', 'feed' ), $ctx, $page, $tab, 'seopress_rss_disable_extra_feed' ),
			self::setting( 'pro/rss-disable-all', __( 'Disable all RSS feeds', 'wp-seopress-pro' ), array( 'disable', 'all', 'feed' ), $ctx, $page, $tab, 'seopress_rss_disable_all_feeds' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function robots_fields( $page ) {
		$ctx = __( 'PRO › robots.txt', 'wp-seopress-pro' );
		$tab = 'tab_seopress_robots';
		return array(
			self::setting( 'pro/robots-enable', __( 'Enable virtual robots.txt', 'wp-seopress-pro' ), array( 'robots', 'enable', 'virtual' ), $ctx, $page, $tab, 'seopress_robots_enable' ),
			self::setting( 'pro/robots-file', __( 'Virtual robots.txt file', 'wp-seopress-pro' ), array( 'robots', 'edit', 'file' ), $ctx, $page, $tab, 'seopress_robots_file' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function llms_fields( $page ) {
		$ctx = __( 'PRO › LLMs.txt', 'wp-seopress-pro' );
		$tab = 'tab_seopress_llms';
		return array(
			self::setting( 'pro/llms-file', __( 'Edit llms.txt file', 'wp-seopress-pro' ), array( 'llms', 'llms.txt', 'ai', 'markdown', 'crawler' ), $ctx, $page, $tab, 'seopress_llms_file' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function alerts_fields( $page ) {
		$ctx = __( 'PRO › Alerts', 'wp-seopress-pro' );
		$tab = 'tab_seopress_alerts';
		return array(
			self::setting( 'pro/alert-noindex', __( 'Alert if noindex on homepage', 'wp-seopress-pro' ), array( 'alert', 'noindex', 'homepage' ), $ctx, $page, $tab, 'seopress_seo_alerts_noindex' ),
			self::setting( 'pro/alert-robots', __( 'Alert if robots.txt failed', 'wp-seopress-pro' ), array( 'alert', 'robots', 'fail' ), $ctx, $page, $tab, 'seopress_seo_alerts_robots_txt' ),
			self::setting( 'pro/alert-sitemap', __( 'Alert if XML sitemaps failed', 'wp-seopress-pro' ), array( 'alert', 'sitemap', 'fail' ), $ctx, $page, $tab, 'seopress_seo_alerts_xml_sitemaps' ),
			self::setting( 'pro/alert-recipients', __( 'Alert recipients', 'wp-seopress-pro' ), array( 'email', 'recipients', 'alert' ), $ctx, $page, $tab, 'seopress_seo_alerts_recipients' ),
			self::setting( 'pro/alert-slack', __( 'Slack Webhook URL', 'wp-seopress-pro' ), array( 'slack', 'webhook', 'notification' ), $ctx, $page, $tab, 'seopress_seo_alerts_slack_webhook_url' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function redirections_fields( $page ) {
		$ctx = __( 'PRO › 404 monitoring', 'wp-seopress-pro' );
		$tab = 'tab_seopress_404';
		return array(
			self::setting( 'pro/404-enable', __( '404 log', 'wp-seopress-pro' ), array( '404', 'log', 'enable' ), $ctx, $page, $tab, 'seopress_404_enable' ),
			self::setting( 'pro/404-exclude-paths', __( 'Exclude paths from 404 logging', 'wp-seopress-pro' ), array( '404', 'exclude', 'paths', 'ignore' ), $ctx, $page, $tab, 'seopress_404_exclude_paths' ),
			self::setting( 'pro/404-cleaning', __( '404 cleaning', 'wp-seopress-pro' ), array( '404', 'cleaning', 'purge' ), $ctx, $page, $tab, 'seopress_404_cleaning' ),
			self::setting( 'pro/404-redirect', __( 'Redirect 404 to', 'wp-seopress-pro' ), array( '404', 'redirect', 'homepage' ), $ctx, $page, $tab, 'seopress_404_redirect_home' ),
			self::setting( 'pro/404-redirect-custom-url', __( 'Redirect 404 to a specific URL', 'wp-seopress-pro' ), array( '404', 'redirect', 'custom', 'url' ), $ctx, $page, $tab, 'seopress_404_redirect_custom_url' ),
			self::setting( 'pro/404-status', __( 'Status code of redirections', 'wp-seopress-pro' ), array( 'status', 'code', '301', '302' ), $ctx, $page, $tab, 'seopress_404_redirect_status_code' ),
			self::setting( 'pro/404-default-param', __( 'Default query parameters mode for new redirects', 'wp-seopress-pro' ), array( '404', 'query', 'parameters', 'redirect' ), $ctx, $page, $tab, 'seopress_404_default_param' ),
			self::setting( 'pro/404-disable-suggestions', __( 'Disable redirect suggestions', 'wp-seopress-pro' ), array( '404', 'suggestions', 'disable', 'redirect' ), $ctx, $page, $tab, 'seopress_404_disable_automatic_redirects' ),
			self::setting( 'pro/404-mails', __( 'Email notifications (404)', 'wp-seopress-pro' ), array( 'email', '404', 'notification' ), $ctx, $page, $tab, 'seopress_404_enable_mails' ),
			self::setting( 'pro/404-mails-from', __( 'Send 404 emails to', 'wp-seopress-pro' ), array( 'email', '404', 'recipients', 'notification' ), $ctx, $page, $tab, 'seopress_404_enable_mails_from' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function page_speed_fields( $page ) {
		$ctx = __( 'PRO › Page Speed', 'wp-seopress-pro' );
		$tab = 'tab_seopress_page_speed';
		return array(
			self::setting( 'pro/ps-url', __( 'URL to analyse', 'wp-seopress-pro' ), array( 'url', 'page speed', 'analyse' ), $ctx, $page, $tab, 'seopress_ps_url' ),
			self::setting( 'pro/ps-api-key', __( 'Google PageSpeed API key', 'wp-seopress-pro' ), array( 'api key', 'pagespeed', 'google' ), $ctx, $page, $tab, 'seopress_ps_api_key' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function ai_fields( $page ) {
		$ctx = __( 'PRO › AI', 'wp-seopress-pro' );
		$tab = 'tab_seopress_ai';
		return array(
			self::setting( 'pro/ai-provider', __( 'AI provider', 'wp-seopress-pro' ), array( 'ai', 'provider', 'openai', 'gemini', 'claude' ), $ctx, $page, $tab, 'seopress_ai_provider' ),
			self::setting( 'pro/ai-alt-text', __( 'Auto Alt Text', 'wp-seopress-pro' ), array( 'alt text', 'image', 'ai', 'automatic' ), $ctx, $page, $tab, 'seopress_ai_openai_alt_text' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function structured_data_fields( $page ) {
		$ctx = __( 'PRO › Structured Data', 'wp-seopress-pro' );
		$tab = 'tab_seopress_rich_snippets';
		return array(
			self::setting( 'pro/sd-enable', __( 'Enable Structured Data Types', 'wp-seopress-pro' ), array( 'structured data', 'schema', 'enable' ), $ctx, $page, $tab, 'seopress_rich_snippets_enable' ),
			self::setting( 'pro/sd-logo', __( 'Publisher logo', 'wp-seopress-pro' ), array( 'publisher', 'logo', 'image' ), $ctx, $page, $tab, 'seopress_rich_snippets_publisher_logo' ),
			self::setting( 'pro/sd-profile', __( 'Enable ProfilePage Schema', 'wp-seopress-pro' ), array( 'profile', 'schema', 'author' ), $ctx, $page, $tab, 'seopress_rich_snippets_profilepage_enable' ),
			self::setting( 'pro/sd-video-youtube', __( 'Auto-fill Video schema from first YouTube video', 'wp-seopress-pro' ), array( 'video', 'youtube', 'schema', 'structured data' ), $ctx, $page, $tab, 'seopress_xml_sitemap_video_schema_youtube_enable' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	// Dublin Core tab removed in 9.8.X.

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function white_label_fields( $page ) {
		$ctx = __( 'PRO › White Label', 'wp-seopress-pro' );
		$tab = 'tab_seopress_white_label';
		return array(
			self::setting( 'pro/wl-admin-header', __( 'Keep only the SEO management block', 'wp-seopress-pro' ), array( 'dashboard', 'header', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_admin_header' ),
			self::setting( 'pro/wl-menu', __( 'Filter SEO admin menu dashicons', 'wp-seopress-pro' ), array( 'menu', 'icon', 'dashicons', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_admin_menu' ),
			self::setting( 'pro/wl-admin-bar-icon', __( 'Edit SEO item in admin bar', 'wp-seopress-pro' ), array( 'admin bar', 'toolbar', 'icon', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_admin_bar_icon' ),
			self::setting( 'pro/wl-title', __( 'Edit SEOPress title in main menu', 'wp-seopress-pro' ), array( 'title', 'menu', 'rename', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_admin_title' ),
			self::setting( 'pro/wl-help', __( 'Hide SEOPress links / help icons', 'wp-seopress-pro' ), array( 'help', 'links', 'hide', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_help_links' ),
			self::setting( 'pro/wl-plugin-title', __( 'Change plugin title in plugins list', 'wp-seopress-pro' ), array( 'plugin', 'title', 'rename', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_plugin_list_title' ),
			self::setting( 'pro/wl-plugin-author', __( 'Change plugin author in plugins list', 'wp-seopress-pro' ), array( 'author', 'plugin', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_plugin_list_author' ),
			self::setting( 'pro/wl-plugin-desc', __( 'Change plugin description in plugins list', 'wp-seopress-pro' ), array( 'description', 'plugin', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_plugin_list_desc' ),
			self::setting( 'pro/wl-plugin-website', __( 'Change plugin website in plugins list', 'wp-seopress-pro' ), array( 'website', 'url', 'plugin', 'white label' ), $ctx, $page, $tab, 'seopress_white_label_plugin_list_website' ),
		);
	}

	/**
	 * @param string $page PRO page slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function gsc_fields( $page ) {
		$ctx = __( 'PRO › URL Inspection', 'wp-seopress-pro' );
		$tab = 'tab_seopress_inspect_url';
		return array(
			self::setting( 'pro/gsc-api-key', __( 'Google Search Console API key', 'wp-seopress-pro' ), array( 'search console', 'api', 'json', 'key' ), $ctx, $page, $tab, 'seopress_instant_indexing_google_api_key' ),
			self::setting( 'pro/gsc-site-url', __( 'Site URL (Search Console)', 'wp-seopress-pro' ), array( 'site url', 'gsc', 'property' ), $ctx, $page, $tab, 'seopress_gsc_site_url' ),
			self::setting( 'pro/gsc-domain-property', __( 'Use domain property (Search Console)', 'wp-seopress-pro' ), array( 'domain property', 'gsc', 'search console' ), $ctx, $page, $tab, 'seopress_gsc_domain_property' ),
			self::setting( 'pro/gsc-date-range', __( 'Date range (Search Console)', 'wp-seopress-pro' ), array( 'date range', 'gsc', 'period', 'search console' ), $ctx, $page, $tab, 'seopress_gsc_date_range' ),
		);
	}

	/**
	 * @param string   $id
	 * @param string   $label
	 * @param string[] $keywords
	 * @param string   $context
	 * @param string   $page
	 * @param string   $tab
	 * @return array<string,mixed>
	 */
	private static function tab( $id, $label, array $keywords, $context, $page, $tab ) {
		return array(
			'name'     => 'seopress/' . $id,
			'label'    => $label,
			'keywords' => $keywords,
			'context'  => $context,
			'kind'     => 'navigation',
			'page'     => $page,
			'tab'      => $tab,
		);
	}

	/**
	 * @param string   $id
	 * @param string   $label
	 * @param string[] $keywords
	 * @param string   $context
	 * @param string   $page
	 * @param string   $tab
	 * @param string   $field
	 * @return array<string,mixed>
	 */
	private static function setting( $id, $label, array $keywords, $context, $page, $tab, $field ) {
		return array(
			'name'     => 'seopress/' . $id,
			'label'    => $label,
			'keywords' => $keywords,
			'context'  => $context,
			'kind'     => 'setting',
			'page'     => $page,
			'tab'      => $tab,
			'field'    => $field,
		);
	}
}
