<?php

namespace SEOPressPro\Actions\Sitemap;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooksFrontend;

class RenderNewsSitemap implements ExecuteHooksFrontend {
	/**
	 * @since 4.3.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'pre_get_posts', array( $this, 'render' ), 1 );
	}

	/**
	 * @since 6.6.0
	 *
	 * @return void
	 */
	protected function hooksWPMLCompatibility() {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return;
		}

		// Check if WPML is not setup as multidomain
		if ( 2 != apply_filters( 'wpml_setting', false, 'language_negotiation_type' ) ) {
			add_filter(
				'request',
				function ( $q ) {
					$current_language = apply_filters( 'wpml_current_language', false );
					$default_language = apply_filters( 'wpml_default_language', false );
					if ( $current_language !== $default_language ) {
						unset( $q['seopress_news'] );
					}

					return $q;
				}
			);
		}
	}

	/**
	 * @since 4.3.0
	 * @see @pre_get_posts
	 *
	 * @param Query $query
	 *
	 * @return void
	 */
	public function render( $query ) {
		if ( ! $query->is_main_query() ) {
			return;
		}

		if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getGoogleNewsEnable() || ! function_exists( 'seopress_get_toggle_option' ) || '1' !== seopress_get_toggle_option( 'news' ) ) {
			return;
		}

		$filename = null;
		if ( '1' === get_query_var( 'seopress_news' ) ) {
			$filename = 'template-xml-sitemaps-news.php';
		} elseif ( '1' === get_query_var( 'seopress_sitemap_xsl' ) ) {
			$filename = 'template-xml-sitemaps-xsl.php';
		}

		$this->hooksWPMLCompatibility();

		if ( $filename === 'template-xml-sitemaps-xsl.php' ) {
			include SEOPRESS_PLUGIN_DIR_PATH . 'inc/functions/sitemap/' . $filename;
			exit();
		} elseif ( isset( $filename ) && file_exists( SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/google-news/' . $filename ) ) {
			include SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/google-news/' . $filename;
			exit();
		}
	}
}
