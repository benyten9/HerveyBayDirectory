<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Dublin Core.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( is_singular() || is_home() ) {
	/**
	 * Display Dublin Core title.
	 *
	 * @return void
	 */
	function seopress_dublin_core_title_hook() {
		if ( function_exists( 'seopress_titles_the_title' ) && seopress_titles_the_title() != '' ) {
			$seopress_dublin_core_title = '<meta name="dc.title" content="' . seopress_titles_the_title() . '">';

			// Hook on post Dublin Core Title - 'seopress_dublin_core_title'.
			if ( has_filter( 'seopress_dublin_core_title' ) ) {
				$seopress_dublin_core_title = apply_filters( 'seopress_dublin_core_title', $seopress_dublin_core_title );
			}
			echo $seopress_dublin_core_title . "\n";
		}
	}
	add_action( 'wp_head', 'seopress_dublin_core_title_hook', 1 );

	/**
	 * Display Dublin Core description.
	 *
	 * @return void
	 */
	function seopress_dublin_core_description_hook() {
		if ( function_exists( 'seopress_titles_the_description_content' ) && seopress_titles_the_description_content() != '' ) {
			$seopress_dublin_core_desc = '<meta name="dc.description" content="' . seopress_titles_the_description_content() . '">';

			// Hook on post Dublin Core Description - 'seopress_dublin_core_desc'.
			if ( has_filter( 'seopress_dublin_core_desc' ) ) {
				$seopress_dublin_core_desc = apply_filters( 'seopress_dublin_core_desc', $seopress_dublin_core_desc );
			}
			echo $seopress_dublin_core_desc . "\n";
		}
	}
	add_action( 'wp_head', 'seopress_dublin_core_description_hook', 1 );

	/**
	 * Display Dublin Core relation.
	 *
	 * @return void
	 */
	function seopress_dublin_core_relation_hook() {
		// Initialize relation.
		$seopress_dublin_core_relation = '';
		$page_id                       = get_option( 'page_for_posts' );

		if ( is_home() && get_post_meta( $page_id, '_seopress_robots_canonical', true ) != '' ) {
			$_seopress_robots_canonical    = get_post_meta( $page_id, '_seopress_robots_canonical', true );
			$seopress_dublin_core_relation = '<meta name="dc.relation" content="' . htmlspecialchars( urldecode( $_seopress_robots_canonical ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '">';
		} elseif ( get_post_meta( get_the_ID(), '_seopress_robots_canonical', true ) != '' ) { // Is metabox enabled.
			$_seopress_robots_canonical    = get_post_meta( get_the_ID(), '_seopress_robots_canonical', true );
			$seopress_dublin_core_relation = '<meta name="dc.relation" content="' . htmlspecialchars( urldecode( $_seopress_robots_canonical ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '">';
		} else {
			global $wp;
			$current_url                   = user_trailingslashit( home_url( add_query_arg( array(), $wp->request ) ) );
			$seopress_dublin_core_relation = '<meta name="dc.relation" content="' . htmlspecialchars( urldecode( $current_url ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '">';
		}
		// Hook on post Dublin Core Relation - 'seopress_dublin_core_relation'.
		if ( has_filter( 'seopress_dublin_core_relation' ) ) {
			$seopress_dublin_core_relation = apply_filters( 'seopress_dublin_core_relation', $seopress_dublin_core_relation );
		}

		if ( isset( $seopress_dublin_core_relation ) && '' != $seopress_dublin_core_relation ) {
			echo $seopress_dublin_core_relation . "\n";
		}
	}
	add_action( 'wp_head', 'seopress_dublin_core_relation_hook', 1 );

	/**
	 * Display Dublin Core source.
	 *
	 * @return void
	 */
	function seopress_dublin_core_source_hook() {
		$seopress_dublin_core_source = '<meta name="dc.source" content="' . htmlspecialchars( urldecode( user_trailingslashit( get_home_url() ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '">';

		// Hook on post Dublin Core Source - 'seopress_dublin_core_source'.
		if ( has_filter( 'seopress_dublin_core_source' ) ) {
			$seopress_dublin_core_source = apply_filters( 'seopress_dublin_core_source', $seopress_dublin_core_source );
		}
		echo $seopress_dublin_core_source . "\n";
	}
	add_action( 'wp_head', 'seopress_dublin_core_source_hook', 1 );

	/**
	 * Display Dublin Core language.
	 *
	 * @return void
	 */
	function seopress_dublin_core_language_hook() {
		if ( function_exists( 'seopress_normalized_locale' ) ) {
			$language = seopress_normalized_locale( get_locale() );
		} else {
			$language = get_locale();
		}

		$seopress_dc_language = '<meta name="dc.language" content="' . $language . '">';

		// Hook on post Dublin Core Language - 'seopress_dublin_core_language'.
		if ( has_filter( 'seopress_dublin_core_language' ) ) {
			$seopress_dc_language = apply_filters( 'seopress_dublin_core_language', $seopress_dc_language );
		}

		echo $seopress_dc_language . "\n";
	}
	add_action( 'wp_head', 'seopress_dublin_core_language_hook', 1 );
}
