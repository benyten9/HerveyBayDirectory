<?php

namespace SEOPressPro\Actions\Sitemap;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooksFrontend;

class RenderIndexSitemap implements ExecuteHooksFrontend {
	/**
	 * @since 7.0.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'pre_get_posts', array( $this, 'render' ), 0 );
	}

	/**
	 * @since 7.0.0
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
	 * @since 7.0.0
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

		if ( '1' === get_query_var( 'seopress_sitemap' ) ) {
			$filename = 'template-xml-sitemaps.php';

			$this->hooksWPMLCompatibility();

			if ( null !== $filename && file_exists( SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/sitemap/' . $filename ) ) {
				include SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/sitemap/' . $filename;
			}
		}
	}
}
