<?php
/**
 * SEOPress PRO Google News XML Sitemap template.
 *
 * @package SEOPress PRO
 * @subpackage Google News
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Prints the headers for the Google News XML Sitemap.
 */
seopress_get_service( 'SitemapHeaders' )->printHeaders();

/**
 * Removes the primary category filter.
 */
remove_filter( 'post_link_category', 'seopress_titles_primary_cat_hook', 10, 3 );

/**
 * Adds a filter to the home URL if WPML is enabled.
 */
if ( 2 == apply_filters( 'wpml_setting', false, 'language_negotiation_type' ) ) {
	add_filter(
		'seopress_sitemaps_home_url',
		function ( $home_url ) {
			$home_url = apply_filters( 'wpml_home_url', get_option( 'home' ) );
			return trailingslashit( $home_url );
		}
	);
} else {
	add_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20, 5 );
}

add_filter(
	'seopress_sitemaps_single_gnews_query',
	function ( $args ) {
		global $sitepress, $sitepress_settings;

		$sitepress_settings['auto_adjust_ids'] = 0;
		remove_filter( 'terms_clauses', array( $sitepress, 'terms_clauses' ) );
		remove_filter( 'category_link', array( $sitepress, 'category_link_adjust_id' ), 1 );

		// If multidomain setup.
		if ( 2 == apply_filters( 'wpml_setting', false, 'language_negotiation_type' ) ) {
			$args['suppress_filters'] = false;
		}

		return $args;
	}
);

/**
 * Prints the Google News XML Sitemap.
 */
function seopress_xml_sitemap_news() {
	// Include Custom Post Types.
	$news_cpt = seopress_pro_get_service( 'OptionPro' )->getGoogleNewsPostTypesList();
	if ( ! empty( $news_cpt ) ) {
		$seopress_xml_sitemap_news_cpt_array = array();
		foreach ( $news_cpt as $cpt_key => $cpt_value ) {
			foreach ( $cpt_value as $_cpt_key => $_cpt_value ) {
				if ( '1' == $_cpt_value ) {
					array_push( $seopress_xml_sitemap_news_cpt_array, $cpt_key );
				}
			}
		}
	}

	remove_all_filters( 'pre_get_posts' );

	$home_url = home_url() . '/';

	$home_url = apply_filters( 'seopress_sitemaps_home_url', $home_url );

	$seopress_sitemaps        = '<?xml version="1.0" encoding="UTF-8"?>';
	$seopress_sitemaps       .= '<?xml-stylesheet type="text/xsl" href="' . $home_url . 'sitemaps_xsl.xsl"?>';
	$seopress_sitemaps       .= "\n";
	$seopress_sitemaps_urlset = apply_filters( 'seopress_sitemaps_urlset', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.google.com/schemas/sitemap-news/0.9 http://www.google.com/schemas/sitemap-news/0.9/sitemap-news.xsd" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' );
	$seopress_sitemaps       .= $seopress_sitemaps_urlset;
	$seopress_sitemaps       .= "\n";

	$args = array(
		'exclude'        => '',
		'posts_per_page' => 1000,
		'order'          => 'DESC',
		'orderby'        => 'date',
		'post_type'      => $seopress_xml_sitemap_news_cpt_array,
		'post_status'    => 'publish',
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => '_seopress_robots_index',
				'value'   => '',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_seopress_robots_index',
				'value'   => 'yes',
				'compare' => '!=',
			),
		),
		'date_query'     => array(
			array(
				'after' => '2 days ago',
			),
		),
		'post__not_in'   => get_option( 'sticky_posts' ),
		'lang'           => '',
		'has_password'   => false,
	);

	// Polylang: exclude hidden languages.
	if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'PLL' ) && isset( PLL()->model ) ) {
		$languages = PLL()->model->get_languages_list();
		if ( wp_list_filter( $languages, array( 'active' => false ) ) ) {
			$args['lang'] = wp_list_pluck( wp_list_filter( $languages, array( 'active' => false ), 'NOT' ), 'slug' );
		}
	}

	$args = apply_filters( 'seopress_sitemaps_single_gnews_query', $args );

	$postslist = get_posts( $args );
	foreach ( $postslist as $post ) {
		setup_postdata( $post );
		if ( 'yes' != get_post_meta( $post->ID, '_seopress_news_disabled', true ) ) {
			// Extract language. Use each post's own language when Polylang is
			// active, otherwise fall back to the site locale.
			$news_locale = get_locale();
			if ( function_exists( 'pll_get_post_language' ) ) {
				$post_locale = pll_get_post_language( $post->ID, 'locale' );
				if ( ! empty( $post_locale ) ) {
					$news_locale = $post_locale;
				}
			}
			$lang = explode( '_', $news_locale );
			$lang = $lang[0];

			// Extract modified date.
			if ( get_the_modified_date( 'c', $post ) ) {
				$seopress_mod = get_the_modified_date( 'c', $post );
			} else {
				$seopress_mod = get_post_modified_time( 'c', false, $post );
			}

			// Extract keywords.
			$seopress_keywords = false;
			$seopress_keywords = apply_filters( 'seopress_sitemaps_news_keywords', $seopress_keywords );
			if ( $seopress_keywords ) {
				$seopress_keywords = get_post_meta( $post->ID, '_seopress_analysis_target_kw', true );
				$seopress_keywords = apply_filters( 'seopress_sitemaps_news_keywords_value', $seopress_keywords );
			}

			// Extract images.
			$images_array = array();
			if ( '1' === seopress_get_service( 'SitemapOption' )->imageIsEnable() ) {
				// No image index?
				if ( 'yes' != get_post_meta( $post, '_seopress_robots_imageindex', true ) ) {
					// Standard images.
					if ( '' != get_post_field( 'post_content', $post ) ) {
						$dom            = new domDocument();
						$internalErrors = libxml_use_internal_errors( true );
						$run_shortcodes = apply_filters( 'seopress_sitemaps_single_shortcodes', false );

						if ( true === $run_shortcodes ) {
							$post_content = do_shortcode( get_post_field( 'post_content', $post ) );
						} else {
							$post_content = get_post_field( 'post_content', $post );
						}

						if ( '' != $post_content ) {
							$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $post_content );

							$dom->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

							if ( '' != $dom->getElementsByTagName( 'img' ) ) {
								$images = $dom->getElementsByTagName( 'img' );
							}
						}
						libxml_use_internal_errors( $internalErrors );
					}

					// Woocommerce images.
					global $product;
					if ( '' != $product && method_exists( $product, 'get_gallery_image_ids' ) ) {
						$product_img = $product->get_gallery_image_ids();
					}

					// Post thumbnail.
					$post_thumbnail    = get_the_post_thumbnail_url( $post, 'full' );
					$post_thumbnail_id = get_post_thumbnail_id( $post );

					// Images.
					if ( ( isset( $images ) && ! empty( $images ) && $images->length >= 1 ) || ( isset( $product ) && ! empty( $product_img ) ) || '' != $post_thumbnail ) {
						$i = 0;
						// Standard images.
						if ( isset( $images ) && ! empty( $images ) ) {
							if ( $images->length >= 1 ) {
								foreach ( $images as $img ) {
									$url = $img->getAttribute( 'src' );
									$url = apply_filters( 'seopress_sitemaps_single_img_url', $url );
									if ( '' != $url ) {
										// Exclude Base64 images.
										if ( false === strpos( $url, 'data:image/' ) ) {
											/*
											*  Initiate $seopress_url['images'] and needed data for the sitemap image template.
											*/

											if ( true === seopress_is_absolute( $url ) ) {
												// Do nothing.
											} else {
												$url = $home_url . $url;
											}

											// Cleaning URL.
											$url = htmlspecialchars( urldecode( esc_attr( wp_filter_nohtml_kses( $url ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

											// Remove query strings.
											$parse_url = wp_parse_url( $url );

											if ( ! empty( $parse_url['scheme'] ) && ! empty( $parse_url['host'] ) && ! empty( $parse_url['path'] ) ) {
												$images_array[ $i ]['loc'] = '<![CDATA[' . $parse_url['scheme'] . '://' . $parse_url['host'] . $parse_url['path'] . ']]>';
											} else {
												$images_array[ $i ]['loc'] = '<![CDATA[' . $url . ']]>';
											}
											++$i;
										}
									}
								}
							}
						}
						// WooCommerce images.
						if ( '' != $product && '' != $product_img ) {
							foreach ( $product_img as $product_attachment_id ) {
								$images_array[ $i ]['loc'] = '<![CDATA[' . esc_attr( wp_filter_nohtml_kses( wp_get_attachment_url( $product_attachment_id ) ) ) . ']]>';
								++$i;
							}
						}
						// Post thumbnail.
						if ( '' != $post_thumbnail ) {
							$images_array[ $i ]['loc'] = '<![CDATA[' . $post_thumbnail . ']]>';
							++$i;
						}
					} //...end extract images.
				} //... end noimageindex?
			} // ... end seopress_get_service('SitemapOption')->imageIsEnable()

			// Init return sitemap.
			$seopress_sitemap_url = '';

			// Array with all the information needed for a sitemap URL.

			$google_news_name = htmlspecialchars( urldecode( esc_attr( html_entity_decode( seopress_pro_get_service( 'OptionPro' )->getGoogleNewsName(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
			$google_news_name = apply_filters( 'seopress_sitemaps_xml_news_name', $google_news_name );

			$seopress_url = array(
				'loc'    => htmlspecialchars( urldecode( get_permalink( $post ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
				'mod'    => $seopress_mod,
				'images' => $images_array,
				'news'   => array(
					'name'             => $google_news_name,
					'language'         => $lang,
					'publication_date' => get_the_date( 'c', $post ),
					'title'            => htmlspecialchars( urldecode( esc_attr( html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
					'keywords'         => $seopress_keywords,
				),
			);

			$seopress_sitemap_url .= '<url>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '<loc>';
			$seopress_sitemap_url .= $seopress_url['loc'];
			$seopress_sitemap_url .= '</loc>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '<lastmod>';
			$seopress_sitemap_url .= $seopress_url['mod'];
			$seopress_sitemap_url .= '</lastmod>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '<news:news>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '<news:publication>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '<news:name>' . $seopress_url['news']['name'] . '</news:name>';
			$seopress_sitemap_url .= "\n";

			$seopress_sitemap_url .= '<news:language>' . $seopress_url['news']['language'] . '</news:language>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '</news:publication>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '<news:publication_date>';
			$seopress_sitemap_url .= $seopress_url['news']['publication_date'];
			$seopress_sitemap_url .= '</news:publication_date>';
			$seopress_sitemap_url .= "\n";
			if ( $seopress_url['news']['keywords'] ) {
				$seopress_sitemap_url .= '<news:keywords>';
				$seopress_sitemap_url .= $seopress_url['news']['keywords'];
				$seopress_sitemap_url .= '</news:keywords>';
				$seopress_sitemap_url .= "\n";
			}
			$seopress_sitemap_url .= '<news:title>';
			$seopress_sitemap_url .= $seopress_url['news']['title'];
			$seopress_sitemap_url .= '</news:title>';
			$seopress_sitemap_url .= "\n";
			$seopress_sitemap_url .= '</news:news>';
			$seopress_sitemap_url .= "\n";
			if ( $seopress_url['images'] ) {
				foreach ( $seopress_url['images'] as $img ) {
					$seopress_sitemap_url .= '<image:image>';
					$seopress_sitemap_url .= "\n";

					if ( '' != $img['loc'] ) {
						$seopress_sitemap_url .= '<image:loc>';
						$seopress_sitemap_url .= $img['loc'];
						$seopress_sitemap_url .= '</image:loc>';
						$seopress_sitemap_url .= "\n";
					}

					$seopress_sitemap_url .= '</image:image>';
					$seopress_sitemap_url .= "\n";
				}
			}
			$seopress_sitemap_url .= '</url>';
			$seopress_sitemap_url .= "\n";

			$seopress_sitemaps .= apply_filters( 'seopress_sitemaps_url', $seopress_sitemap_url, $seopress_url );
		}
	}
	wp_reset_postdata();

	$seopress_sitemaps .= '</urlset>';

	$seopress_sitemaps = apply_filters( 'seopress_sitemaps_xml_news', $seopress_sitemaps );

	return $seopress_sitemaps;
}
echo seopress_xml_sitemap_news();
