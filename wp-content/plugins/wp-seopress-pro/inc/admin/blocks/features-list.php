<?php
/**
 * SEOPress PRO Features List.
 *
 * @package SEOPress PRO
 * @subpackage Blocks
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Add PRO features to SEO dashboard.
 *
 * @param array $features The features.
 * @return array The features.
 */
function seopress_pro_features_list_before_tools( $features ) {
	$docs         = seopress_get_docs_links();
	$url          = wp_parse_url( home_url() );
	$host         = isset( $url['host'] ) ? $url['host'] : '';
	$port         = isset( $url['port'] ) ? ':' . $url['port'] : '';
	$current_site = $host . $port;

	$features['404']           = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-redirections.svg',
		'title'       => __( 'Redirections', 'wp-seopress-pro' ),
		'desc'        => __( 'Monitor 404, create 301, 302 and 307 redirections.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_404' ),
		'filter'      => 'seopress_remove_feature_redirects',
	);
	$features['rich-snippets'] = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-schemas.svg',
		'title'       => __( 'Structured Data Types', 'wp-seopress-pro' ),
		'desc'        => __( 'Add data types to your content: articles, courses, recipes, videos, events, products and more.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_404' ),
		'filter'      => 'seopress_remove_feature_schemas',
	);
	if (
		! is_multisite() ||
		( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && constant( 'SUBDOMAIN_INSTALL' ) === true ) || // subdomains or single site.
		( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && constant( 'SUBDOMAIN_INSTALL' ) === false && defined( 'DOMAIN_CURRENT_SITE' ) && $current_site !== constant( 'DOMAIN_CURRENT_SITE' ) ) // subdirectories with custom domains.
	) {
		$features['robots'] = array(
			'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-robots-txt.svg',
			'title'       => __( 'robots.txt', 'wp-seopress-pro' ),
			'desc'        => __( 'Edit your robots.txt file.', 'wp-seopress-pro' ),
			'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_robots' ),
			'filter'      => 'seopress_remove_feature_robots',
		);
		$features['llms'] = array(
			'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-robots-txt.svg',
			'title'       => __( 'llms.txt', 'wp-seopress-pro' ),
			'desc'        => __( 'Edit your llms.txt file.', 'wp-seopress-pro' ),
			'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_llms' ),
			'filter'      => 'seopress_remove_feature_llms',
		);
	}
	$features['agent-ready'] = array(
		'svg'    => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-ai.svg',
		'title'  => __( 'Agent Readiness', 'wp-seopress-pro' ),
		'desc'   => __( 'Expose discovery signals for AI agents: Link / Content-Signal headers, Markdown content negotiation, and /.well-known/ endpoints (MCP Server Card, Agent Skills, API Catalog).', 'wp-seopress-pro' ),
		'filter' => 'seopress_remove_feature_agent_ready',
		'toggle' => true,
	);
	if ( ! is_multisite() ) {
		$features['htaccess'] = array(
			'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-htaccess.svg',
			'title'       => __( '.htaccess', 'wp-seopress-pro' ),
			'desc'        => __( 'View and edit your .htaccess file directly from WordPress to add custom redirects, security rules, or performance optimizations.', 'wp-seopress-pro' ),
			'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_htaccess' ),
			'filter'      => 'seopress_remove_feature_htaccess',
			'toggle'      => false,
		);
	}
	$features['local-business'] = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-local-business.svg',
		'title'       => __( 'Local Business', 'wp-seopress-pro' ),
		'desc'        => __( 'Add Google Local Business data type.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_local_business' ),
		'filter'      => 'seopress_remove_feature_local_business',
	);
	$features['ai']             = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-ai.svg',
		'title'       => __( 'AI', 'wp-seopress-pro' ),
		'desc'        => __( 'Use the power of artificial intelligence to increase your productivity.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_ai' ),
		'filter'      => 'seopress_remove_feature_ai',
	);
	$features['ai-assistant']   = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-ai.svg',
		'title'       => __( 'AI Assistant', 'wp-seopress-pro' ),
		'desc'        => __( 'A chat assistant in the block editor and on the settings pages: write content, generate metas, and fix the SEO issues it finds on the post you are editing.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_ai' ),
		'filter'      => 'seopress_remove_feature_ai_assistant',
		'toggle'      => true,
	);
	$features['breadcrumbs']    = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-breadcrumbs.svg',
		'title'       => __( 'Breadcrumbs', 'wp-seopress-pro' ),
		'desc'        => __( 'Enable Breadcrumbs for your theme and improve your SEO in SERPs.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_breadcrumbs' ),
		'filter'      => 'seopress_remove_feature_breadcrumbs',
	);
	$features['woocommerce']    = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-woocommerce.svg',
		'title'       => __( 'WooCommerce', 'wp-seopress-pro' ),
		'desc'        => __( 'Improve WooCommerce SEO.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_woocommerce' ),
		'filter'      => 'seopress_remove_feature_woocommerce',
	);
	$features['edd']            = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-edd.svg',
		'title'       => __( 'Easy Digital Downloads', 'wp-seopress-pro' ),
		'desc'        => __( 'Improve Easy Digital Downloads SEO.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_edd' ),
		'filter'      => 'seopress_remove_feature_edd',
	);
	$features['page-speed']     = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-google-page-speed.svg',
		'title'       => __( 'Google Page Speed', 'wp-seopress-pro' ),
		'desc'        => __( 'Track your website performance to improve SEO with Google Page Speed.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_page_speed' ),
		'filter'      => 'seopress_remove_feature_page_speed',
		'toggle'      => false,
	);
	$features['inspect-url']    = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-google-search-console.svg',
		'title'       => __( 'Google Search Console', 'wp-seopress-pro' ),
		'desc'        => __( 'Get clicks, positions, CTR and impressions. Inspect your URL for details about crawling, indexing, mobile compatibility, schemas and more.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_inspect_url' ),
		'filter'      => 'seopress_remove_feature_inspect_url',
		'toggle'      => true,
	);
	$features['news']           = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-google-news-sitemap.svg',
		'title'       => __( 'Google News Sitemap', 'wp-seopress-pro' ),
		'desc'        => __( 'Optimize your site for Google News.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_news' ),
		'filter'      => 'seopress_remove_feature_news',
	);
	$features['bot']            = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-broken-links.svg',
		'title'       => __( 'Audit', 'wp-seopress-pro' ),
		'desc'        => __( 'Scan your site to find SEO problems and broken links.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-bot-batch' ),
		'filter'      => 'seopress_remove_feature_bot',
	);
	$features['alerts']         = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-alert.svg',
		'title'       => __( 'SEO Alerts', 'wp-seopress-pro' ),
		'desc'        => __( 'Receive alerts by email/Slack about your SEO before it‘s too late.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_alerts' ),
		'filter'      => 'seopress_remove_feature_alerts',
	);
	$features['dublin-core']    = array(
		'svg'    => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-dublin-core.svg',
		'title'  => __( 'Dublin Core', 'wp-seopress-pro' ),
		'desc'   => __( 'Add Dublin Core meta tags.', 'wp-seopress-pro' ),
		'btn_primary' => false,
		'filter' => 'seopress_remove_feature_dublin_core',
	);
	$features['rss']            = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-rss.svg',
		'title'       => __( 'RSS', 'wp-seopress-pro' ),
		'desc'        => __( 'Configure default WordPress RSS.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-pro-page#tab=tab_seopress_rss' ),
		'filter'      => 'seopress_remove_feature_rss',
		'toggle'      => false,
	);

	return $features;
}
add_filter( 'seopress_features_list_before_tools', 'seopress_pro_features_list_before_tools' );

/**
 * Add PRO features to SEO dashboard (after Tools).
 *
 * @param array $features The features.
 * @return array The features.
 */
function seopress_pro_features_list_after_tools( $features ) {
	$docs = seopress_get_docs_links();

	$features['license'] = array(
		'svg'         => SEOPRESS_PRO_ASSETS_DIR . '/img/ico-license.svg',
		'title'       => __( 'License', 'wp-seopress-pro' ),
		'desc'        => __( 'Edit your license key.', 'wp-seopress-pro' ),
		'btn_primary' => admin_url( 'admin.php?page=seopress-license' ),
		'filter'      => 'seopress_remove_feature_license',
		'toggle'      => false,
	);

	return $features;
}
add_filter( 'seopress_features_list_after_tools', 'seopress_pro_features_list_after_tools' );

/**
 * Assign each PRO feature to its dashboard category.
 *
 * The free plugin only knows how to group its own built-in keys
 * (ModuleDashboard::getDefaultGroupMap()); every PRO feature would
 * otherwise fall back to "content". Stamp the group per PRO key here so
 * the SEO modules section is split into Content / Technical /
 * Data & Tracking exactly as designed. Runs after both PRO callbacks
 * (priority 20) and never overrides an explicit per-feature group.
 *
 * @param array $features The features.
 * @return array The features.
 */
function seopress_pro_features_list_groups( $features ) {
	$map = array(
		'404'            => 'technical',
		'rich-snippets'  => 'content',
		'robots'         => 'technical',
		'llms'           => 'technical',
		'agent-ready'    => 'technical',
		'htaccess'       => 'technical',
		'local-business' => 'technical',
		'breadcrumbs'    => 'technical',
		'license'        => 'technical',
		'woocommerce'    => 'content',
		'edd'            => 'content',
		'dublin-core'    => 'content',
		// Content, not Data & Tracking: both are about producing copy — metas,
		// alt text, articles — not about measuring or monitoring a site.
		'ai'             => 'content',
		'ai-assistant'   => 'content',
		'page-speed'     => 'data-tracking',
		'inspect-url'    => 'data-tracking',
		'news'           => 'data-tracking',
		'bot'            => 'data-tracking',
		'alerts'         => 'data-tracking',
		'rss'            => 'data-tracking',
	);

	foreach ( $map as $key => $group ) {
		if ( isset( $features[ $key ] ) && is_array( $features[ $key ] ) && empty( $features[ $key ]['group'] ) ) {
			$features[ $key ]['group'] = $group;
		}
	}

	return $features;
}
add_filter( 'seopress_features_list_after_tools', 'seopress_pro_features_list_groups', 20 );

/**
 * Explicit display order of every feature inside each dashboard category.
 *
 * Free only knows the order of its own keys; once PRO is active the full
 * sequence (free + PRO keys interleaved) is defined here so the SEO
 * modules section matches the intended layout. Any key not listed falls
 * to the end of its group in insertion order.
 *
 * @param array $order Ordered feature keys keyed by group id.
 * @return array The overridden order.
 */
function seopress_pro_features_list_order( $order ) {
	$order['content']       = array( 'titles', 'ai', 'ai-assistant', 'advanced', 'social', 'dublin-core', 'woocommerce', 'edd', 'rich-snippets' );
	$order['technical']     = array( '404', 'robots', 'llms', 'instant-indexing', 'htaccess', 'breadcrumbs', 'local-business', 'xml-sitemap', 'tools', 'license', 'agent-ready' );
	$order['data-tracking'] = array( 'google-analytics', 'page-speed', 'news', 'alerts', 'bot', 'inspect-url', 'rss' );

	return $order;
}
add_filter( 'seopress_features_list_order', 'seopress_pro_features_list_order' );
