<?php

namespace SEOPressPro\Services\Options;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPressPro\Services\Options\Schemas\LocalBusinessOptions;
use SEOPressPro\Services\Options\Schemas\PublisherOptions;

class OptionPro {
	use LocalBusinessOptions;
	use PublisherOptions;

	/**
	 * @since 4.5.0
	 *
	 * @return array
	 */
	public function getOption( $is_multisite ) {
		if ( $is_multisite === true && function_exists( 'get_network' ) ) {
			$network         = get_network();
			$main_network_id = $network->site_id;

			return get_blog_option( $main_network_id, 'seopress_pro_mu_option_name' );
		} else {
			return get_option( 'seopress_pro_option_name' );
		}
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string|null
	 *
	 * @param string $key
	 */
	protected function searchOptionByKey( $key, $is_multisite = false ) {

		$data = $this->getOption( $is_multisite );

		if ( empty( $data ) ) {
			return null;
		}

		if ( ! isset( $data[ $key ] ) ) {
			return null;
		}

		return $data[ $key ];
	}

	/**
	 * @since 4.6.0
	 *
	 * @return string
	 */
	public function getRichSnippetEnable() {
		return $this->searchOptionByKey( 'seopress_rich_snippets_enable' );
	}

	/**
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getProfilePageSchemaEnable() {
		return $this->searchOptionByKey( 'seopress_rich_snippets_profilepage_enable' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsEnable() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_enable' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsJsonEnable() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_json_enable' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsSeparator() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_separator' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18nHere() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_here' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18nHome() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_home' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18nAuthor() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_author' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18n404() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_404' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18nSearch() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_search' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18nNoResults() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_no_results' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18nAttachments() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_attachments' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function getBreadcrumbsI18nPaged() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_i18n_paged' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return boolean
	 */
	public function getBreadcrumbsRemoveBlogPage() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_remove_blog_page' );
	}

	/**
	 * @since 6.0.0
	 *
	 * @return boolean
	 */
	public function getBreadcrumbsRemoveShopPage() {
		return $this->searchOptionByKey( 'seopress_breadcrumbs_remove_shop_page' );
	}



	/**
	 * @since 6.3.0
	 *
	 * @return boolean
	 */
	public function get404Enable() {
		return $this->searchOptionByKey( 'seopress_404_enable' );
	}

	/**
	 * @since 6.3.0
	 *
	 * @return string
	 */
	public function get404RedirectHome() {
		return $this->searchOptionByKey( 'seopress_404_redirect_home' );
	}

	/**
	 * @since 6.3.0
	 *
	 * @return string
	 */
	public function get404RedirectUrl() {
		return $this->searchOptionByKey( 'seopress_404_redirect_custom_url' );
	}

	/**
	 * @since 6.3.0
	 *
	 * @return string
	 */
	public function get404RedirectStatusCode() {
		return $this->searchOptionByKey( 'seopress_404_redirect_status_code' );
	}

	/**
	 * @since 6.3.0
	 *
	 * @return string
	 */
	public function get404RedirectEnableMails() {
		return $this->searchOptionByKey( 'seopress_404_enable_mails' );
	}

	/**
	 * @since 6.3.0
	 *
	 * @return string
	 */
	public function get404RedirectEnableMailsFrom() {
		return $this->searchOptionByKey( 'seopress_404_enable_mails_from' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function get404Cleaning() {
		return $this->searchOptionByKey( 'seopress_404_cleaning' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function get404DisableAutomaticRedirects() {
		return $this->searchOptionByKey( 'seopress_404_disable_automatic_redirects' );
	}

	/**
	 * @since 6.3.0
	 *
	 * @return boolean
	 */
	public function get404DisableGuessAutomaticRedirects() {
		return $this->searchOptionByKey( 'seopress_404_disable_guess_automatic_redirects_404' );
	}

	/**
	 * Default Query Parameters mode applied to newly created redirects.
	 *
	 * Returns one of 'exact_match', 'without_param', 'with_ignored_param'.
	 * Falls back to 'exact_match' (the historical hardcoded default) when no
	 * value is stored or the stored value is unrecognized, so installs that
	 * never touched this setting keep their existing behavior.
	 *
	 * @since 9.8.0
	 *
	 * @return string
	 */
	public function get404DefaultParam() {
		$value = $this->searchOptionByKey( 'seopress_404_default_param' );
		if ( ! in_array( $value, array( 'exact_match', 'without_param', 'with_ignored_param' ), true ) ) {
			return 'exact_match';
		}
		return $value;
	}

	/**
	 * @since 6.5.0
	 *
	 * @return boolean
	 */
	public function getRSSDisableCommentsFeed() {
		return $this->searchOptionByKey( 'seopress_rss_disable_comments_feed' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return boolean
	 */
	public function getRSSDisablePostsFeed() {
		return $this->searchOptionByKey( 'seopress_rss_disable_posts_feed' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return boolean
	 */
	public function getRSSDisableExtraFeed() {
		return $this->searchOptionByKey( 'seopress_rss_disable_extra_feed' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return boolean
	 */
	public function getRSSDisableAllFeeds() {
		return $this->searchOptionByKey( 'seopress_rss_disable_all_feeds' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return boolean
	 */
	public function getRSSBeforeHTML() {
		return $this->searchOptionByKey( 'seopress_rss_before_html' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return boolean
	 */
	public function getRSSAfterHTML() {
		return $this->searchOptionByKey( 'seopress_rss_after_html' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return boolean
	 */
	public function getRSSPostThumbnail() {
		return $this->searchOptionByKey( 'seopress_rss_post_thumbnail' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getGoogleNewsEnable() {
		return $this->searchOptionByKey( 'seopress_news_enable' );
	}

	/**
	 * @since 7.1.0
	 *
	 * @return string
	 */
	public function getGoogleNewsName() {
		return $this->searchOptionByKey( 'seopress_news_name' );
	}

	/**
	 * @since 7.1.0
	 *
	 * @return array
	 */
	public function getGoogleNewsPostTypesList() {
		return $this->searchOptionByKey( 'seopress_news_name_post_types_list' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCCartPageNoindexEnable() {
		return $this->searchOptionByKey( 'seopress_woocommerce_cart_page_no_index' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCCheckoutPageNoindexEnable() {
		return $this->searchOptionByKey( 'seopress_woocommerce_checkout_page_no_index' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCCustomerAccountPageNoindexEnable() {
		return $this->searchOptionByKey( 'seopress_woocommerce_customer_account_page_no_index' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCOGPriceEnable() {
		return $this->searchOptionByKey( 'seopress_woocommerce_product_og_price' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCOGCurrencyEnable() {
		return $this->searchOptionByKey( 'seopress_woocommerce_product_og_currency' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCDisableSchemaOutput() {
		return $this->searchOptionByKey( 'seopress_woocommerce_schema_output' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCDisableSchemaBreadcrumbsOutput() {
		return $this->searchOptionByKey( 'seopress_woocommerce_schema_breadcrumbs_output' );
	}

	/**
	 * Output a ProductGroup schema (instead of Product) for variable products.
	 *
	 * @since 10.1.0
	 *
	 * @return boolean
	 */
	public function getWCProductGroupEnable() {
		return $this->searchOptionByKey( 'seopress_woocommerce_product_group_schema' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWCDisableMetaGenerator() {
		return $this->searchOptionByKey( 'seopress_woocommerce_meta_generator' );
	}

	/**
	 * @since 7.8.0
	 *
	 * @return boolean
	 */
	public function getSEOAlertsNoIndex() {
		return $this->searchOptionByKey( 'seopress_seo_alerts_noindex' );
	}

	/**
	 * @since 7.8.0
	 *
	 * @return boolean
	 */
	public function getSEOAlertsRobotsTxt() {
		return $this->searchOptionByKey( 'seopress_seo_alerts_robots_txt' );
	}

	/**
	 * @since 7.8.0
	 *
	 * @return boolean
	 */
	public function getSEOAlertsXMLSitemaps() {
		return $this->searchOptionByKey( 'seopress_seo_alerts_xml_sitemaps' );
	}

	/**
	 * @since 7.8.0
	 *
	 * @return boolean
	 */
	public function getSEOAlertsRecipients() {
		return $this->searchOptionByKey( 'seopress_seo_alerts_recipients' );
	}

	/**
	 * @since 7.8.0
	 *
	 * @return boolean
	 */
	public function getSEOAlertsSlackWebhookUrl() {
		return $this->searchOptionByKey( 'seopress_seo_alerts_slack_webhook_url' );
	}


	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getEddOgPrice() {
		return $this->searchOptionByKey( 'seopress_edd_product_og_price' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getEddOgCurrency() {
		return $this->searchOptionByKey( 'seopress_edd_product_og_currency' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getEddMetaGenerator() {
		return $this->searchOptionByKey( 'seopress_edd_meta_generator' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getRobotsTxtEnable() {
		$url          = wp_parse_url( home_url() );
		$host         = isset( $url['host'] ) ? $url['host'] : '';
		$port         = isset( $url['port'] ) ? ':' . $url['port'] : '';
		$current_site = $host . $port;

		if ( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && false === constant( 'SUBDOMAIN_INSTALL' ) && defined( 'DOMAIN_CURRENT_SITE' ) && $current_site === constant( 'DOMAIN_CURRENT_SITE' ) ) {
			return $this->searchOptionByKey( 'seopress_mu_robots_enable', true );
		}

		return $this->searchOptionByKey( 'seopress_robots_enable' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getRobotsTxtFile() {
		$url          = wp_parse_url( home_url() );
		$host         = isset( $url['host'] ) ? $url['host'] : '';
		$port         = isset( $url['port'] ) ? ':' . $url['port'] : '';
		$current_site = $host . $port;

		if ( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && false === constant( 'SUBDOMAIN_INSTALL' ) && defined( 'DOMAIN_CURRENT_SITE' ) && $current_site === constant( 'DOMAIN_CURRENT_SITE' ) ) {
			return $this->searchOptionByKey( 'seopress_mu_robots_file', true );
		}

		return $this->searchOptionByKey( 'seopress_robots_file' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getLlmsTxtFile() {
		$url          = wp_parse_url( home_url() );
		$host         = isset( $url['host'] ) ? $url['host'] : '';
		$port         = isset( $url['port'] ) ? ':' . $url['port'] : '';
		$current_site = $host . $port;

		if ( is_multisite() && defined( 'SUBDOMAIN_INSTALL' ) && false === constant( 'SUBDOMAIN_INSTALL' ) && defined( 'DOMAIN_CURRENT_SITE' ) && $current_site === constant( 'DOMAIN_CURRENT_SITE' ) ) {
			return $this->searchOptionByKey( 'seopress_mu_llms_file', true );
		}

		return $this->searchOptionByKey( 'seopress_llms_file' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWhiteLabelAdminHeader() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_admin_header', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_admin_header' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelAdminMenu() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_admin_menu', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_admin_menu' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelAdminBarIcon() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_admin_bar_icon', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_admin_bar_icon' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelAdminTitle() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_admin_title', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_admin_title' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWhiteLabelHelpLinks() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_help_links', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_help_links' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelListTitle() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_plugin_list_title', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_plugin_list_title' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelListTitlePro() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_plugin_list_title_pro', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_plugin_list_title_pro' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelListDesc() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_plugin_list_desc', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_plugin_list_desc' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelListDescPro() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_plugin_list_desc_pro', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_plugin_list_desc_pro' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelListAuthor() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_plugin_list_author', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_plugin_list_author' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getWhiteLabelListWebsite() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_plugin_list_website', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_plugin_list_website' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWhiteLabelListViewDetails() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_plugin_list_view_details', true );
		}

		return $this->searchOptionByKey( 'seopress_white_label_plugin_list_view_details' );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getWhiteLabelMenuPages() {
		if ( is_network_admin() || is_multisite() ) {
			return $this->searchOptionByKey( 'seopress_mu_white_label_menu_pages', true );
		}

		return;
	}

	/**
	 * @since 6.6.0
	 *
	 * @return boolean
	 */
	public function getGSCDomainProperty() {
		return $this->searchOptionByKey( 'seopress_gsc_domain_property' );
	}

	/**
	 * Get the custom GSC site URL.
	 *
	 * @since 9.8
	 *
	 * @return string
	 */
	public function getGSCSiteUrl() {
		return $this->searchOptionByKey( 'seopress_gsc_site_url' );
	}

	/**
	 * Get the resolved GSC URL (custom URL or site_url, with sc-domain: prefix if domain property).
	 *
	 * @since 9.8
	 *
	 * @return string
	 */
	public function getGSCResolvedUrl() {
		$custom_url = $this->getGSCSiteUrl();
		$base_url   = ! empty( $custom_url ) ? $custom_url : site_url();

		if ( $this->getGSCDomainProperty() ) {
			$parsed = wp_parse_url( $base_url );

			return is_array( $parsed ) && isset( $parsed['host'] ) ? 'sc-domain:' . $parsed['host'] : $base_url;
		}

		return $base_url;
	}

	/**
	 * @since 6.6.0
	 *
	 * @return string
	 */
	public function getGSCDateRange() {
		return $this->searchOptionByKey( 'seopress_gsc_date_range' );
	}

	/**
	 * @since 7.1.0
	 *
	 * @return string
	 */
	public function getAIOpenaiAltText() {
		return $this->searchOptionByKey( 'seopress_ai_openai_alt_text' );
	}

	/**
	 * Whether to generate the alt text on image upload.
	 *
	 * Returns null when never saved (legacy installs), in which case the
	 * caller treats it as enabled for backward compatibility.
	 *
	 * @return string|null
	 */
	public function getAIGenFieldAltText() {
		return $this->searchOptionByKey( 'seopress_ai_gen_field_alt_text' );
	}

	/**
	 * Whether to generate the caption on image upload.
	 *
	 * @return string|null
	 */
	public function getAIGenFieldCaption() {
		return $this->searchOptionByKey( 'seopress_ai_gen_field_caption' );
	}

	/**
	 * Whether to generate the description on image upload.
	 *
	 * @return string|null
	 */
	public function getAIGenFieldDescription() {
		return $this->searchOptionByKey( 'seopress_ai_gen_field_description' );
	}

	/**
	 * @since 9.0.0
	 *
	 * @return string
	 */
	public function getAIOpenaiModel() {
		return $this->searchOptionByKey( 'seopress_ai_openai_model' );
	}

	/**
	 * @since 9.0.0
	 *
	 * @return string
	 */
	public function getAIDeepSeekModel() {
		return $this->searchOptionByKey( 'seopress_ai_deepseek_model' );
	}

	/**
	 * @since 9.0.0
	 *
	 * @return string
	 */
	public function getAIDeepSeekApiKey() {
		return $this->searchOptionByKey( 'seopress_ai_deepseek_api_key' );
	}

	/**
	 * @since 9.0.0
	 *
	 * @return string
	 */
	public function getAIOpenaiApiKey() {
		return $this->searchOptionByKey( 'seopress_ai_openai_api_key' );
	}

	/**
	 * @since 9.0.0
	 *
	 * @return string
	 */
	public function getAIProvider() {
		return $this->searchOptionByKey( 'seopress_ai_provider' );
	}

	/**
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getAIGeminiModel() {
		return $this->searchOptionByKey( 'seopress_ai_gemini_model' );
	}

	/**
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getAIGeminiApiKey() {
		return $this->searchOptionByKey( 'seopress_ai_gemini_api_key' );
	}

	/**
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getAIMistralModel() {
		return $this->searchOptionByKey( 'seopress_ai_mistral_model' );
	}

	/**
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getAIMistralApiKey() {
		return $this->searchOptionByKey( 'seopress_ai_mistral_api_key' );
	}

	/**
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getAIClaudeModel() {
		return $this->searchOptionByKey( 'seopress_ai_claude_model' );
	}

	/**
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getAIClaudeApiKey() {
		return $this->searchOptionByKey( 'seopress_ai_claude_api_key' );
	}

	/**
	 * Get AI Assistant enabled setting.
	 *
	 * @since 9.6.0
	 *
	 * @return string
	 */
	public function getAIAssistantEnabled() {
		// Stored with the other feature modules (`seopress_toggle`) rather than in
		// the PRO settings array, so the Dashboard can switch the assistant on
		// from its feature card like any other module. Installs from before 10.2
		// are moved over by seopress_pro_migrate_ai_assistant_default().
		if ( ! function_exists( 'seopress_get_toggle_option' ) ) {
			return null;
		}

		return seopress_get_toggle_option( 'ai-assistant' );
	}

	/**
	 * Auto-generate the meta description with AI on publish when empty.
	 *
	 * @since 10.1.0
	 *
	 * @return string
	 */
	public function getAIAutoGenDesc() {
		return $this->searchOptionByKey( 'seopress_ai_auto_gen_desc' );
	}

	/**
	 * Auto-generate the meta title with AI on publish when empty.
	 *
	 * @since 10.1.0
	 *
	 * @return string
	 */
	public function getAIAutoGenTitle() {
		return $this->searchOptionByKey( 'seopress_ai_auto_gen_title' );
	}

	/**
	 * Site-wide custom instructions (voice/tone) added to every AI
	 * title/description generation prompt.
	 *
	 * @since 10.1.0
	 *
	 * @return string
	 */
	public function getAICustomInstructions() {
		return $this->searchOptionByKey( 'seopress_ai_custom_instructions' );
	}
}
