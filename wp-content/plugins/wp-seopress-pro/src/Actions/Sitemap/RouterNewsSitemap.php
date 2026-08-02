<?php

namespace SEOPressPro\Actions\Sitemap;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

class RouterNewsSitemap implements ExecuteHooks {
	/**
	 * @since 4.3.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'query_vars', array( $this, 'queryVars' ) );
		add_action( 'seopress_re_register_sitemap_rules', array( $this, 'reRegisterRules' ) );

		// Re-flush after PRO options are saved so news rules reflect the new value.
		// The PRO pre_update_option hook flushes BEFORE the save, which uses stale init() rules.
		add_action( 'update_option_seopress_pro_option_name', array( $this, 'afterProOptionSave' ), 10, 2 );
	}

	/**
	 * @since 4.3.0
	 * @see init
	 *
	 * @return void
	 */
	public function init() {
		if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getGoogleNewsEnable() || ! function_exists( 'seopress_get_toggle_option' ) || '1' !== seopress_get_toggle_option( 'news' ) ) {
			return;
		}

		// XSL Sitemap
		add_rewrite_rule( '^sitemaps_xsl.xsl$', 'index.php?seopress_sitemap_xsl=1', 'top' );

		// Google News
		add_rewrite_rule( 'news.xml?$', 'index.php?seopress_news=1', 'top' );
	}

	/**
	 * Re-register news sitemap rewrite rules after sitemap settings are saved.
	 *
	 * Called by the free plugin's seopress_re_register_sitemap_rules action
	 * so that news rules survive the flush.
	 *
	 * @since 9.6.0
	 *
	 * @return void
	 */
	public function reRegisterRules() {
		$this->init();
	}

	/**
	 * Re-register and flush news sitemap rules after PRO options are saved.
	 *
	 * The PRO plugin flushes rewrite rules in pre_update_option (before save),
	 * which uses rules from init() based on the OLD option values. When the user
	 * enables/disables news, the new value is saved AFTER the flush, leaving
	 * the DB rules stale. This post-save hook corrects that.
	 *
	 * @since 9.6.0
	 *
	 * @param array $old_value The old option value.
	 * @param array $new_value The new option value.
	 *
	 * @return void
	 */
	public function afterProOptionSave( $old_value, $new_value ) {
		$old_news = isset( $old_value['seopress_news_enable'] ) ? $old_value['seopress_news_enable'] : '';
		$new_news = isset( $new_value['seopress_news_enable'] ) ? $new_value['seopress_news_enable'] : '';

		if ( $old_news === $new_news ) {
			return;
		}

		global $wp_rewrite;

		if ( '1' === $new_news && function_exists( 'seopress_get_toggle_option' ) && '1' === seopress_get_toggle_option( 'news' ) ) {
			// Enabling: register the news rewrite rules.
			add_rewrite_rule( '^sitemaps_xsl.xsl$', 'index.php?seopress_sitemap_xsl=1', 'top' );
			add_rewrite_rule( 'news.xml?$', 'index.php?seopress_news=1', 'top' );
		} else {
			// Disabling: remove stale news rules that init() may have registered.
			unset( $wp_rewrite->extra_rules_top['news.xml?$'] );
		}

		flush_rewrite_rules( false );
	}

	/**
	 * @since 4.3.0
	 * @see query_vars
	 *
	 * @param array $vars
	 *
	 * @return array
	 */
	public function queryVars( $vars ) {
		if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getGoogleNewsEnable() || ! function_exists( 'seopress_get_toggle_option' ) || '1' !== seopress_get_toggle_option( 'news' ) ) {
			return $vars;
		}

		$vars[] = 'seopress_sitemap_xsl';
		$vars[] = 'seopress_news';

		return $vars;
	}
}
