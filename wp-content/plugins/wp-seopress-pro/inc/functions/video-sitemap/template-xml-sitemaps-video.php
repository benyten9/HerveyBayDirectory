<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Video Sitemap XML Sitemap.
 *
 * @package SEOPress PRO
 * @subpackage Video Sitemap
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

// Headers.
seopress_get_service( 'SitemapHeaders' )->printHeaders();

// Remove primary category.
remove_filter( 'post_link_category', 'seopress_titles_primary_cat_hook', 10, 3 );

// WPML - Home URL.
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
	'seopress_sitemaps_video_query',
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
 * XML Sitemap video template.
 *
 * @return string XML Sitemap video template.
 */
function seopress_xml_sitemap_video() {
	$offset = basename( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '.xml' );
	$offset = preg_match_all( '/\d+/', $offset, $matches );
	$offset = end( $matches[0] );

	// Max posts per paginated sitemap.
	$max = 1000;
	$max = apply_filters( 'seopress_sitemaps_max_videos_per_sitemap', $max );

	if ( isset( $offset ) && absint( $offset ) && '' != $offset && 0 != $offset ) {
		$offset = ( ( $offset - 1 ) * $max );
	} else {
		$offset = 0;
	}

	$home_url = home_url() . '/';

	$home_url = apply_filters( 'seopress_sitemaps_home_url', $home_url );

	$seopress_sitemaps  = '<?xml version="1.0" encoding="UTF-8"?>';
	$seopress_sitemaps .= '<?xml-stylesheet type="text/xsl" href="' . $home_url . 'sitemaps_video_xsl.xsl"?>';
	$seopress_sitemaps .= "\n";
	$seopress_sitemaps .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';

	// CPT.
	if ( ! empty( seopress_get_service( 'SitemapOption' )->getPostTypesList() ) ) {
		$cpt = array();
		foreach ( seopress_get_service( 'SitemapOption' )->getPostTypesList() as $cpt_key => $cpt_value ) {
			foreach ( $cpt_value as $_cpt_key => $_cpt_value ) {
				if ( '1' == $_cpt_value ) {
					$cpt[] = $cpt_key;
				}
			}
		}

		// Use direct SQL with EXISTS subqueries instead of meta_query LEFT JOINs
		// to avoid cartesian product on sites with large postmeta tables.
		global $wpdb;

		$cpt_placeholders = implode( ',', array_fill( 0, count( $cpt ), '%s' ) );

		// Polylang: exclude posts assigned to a hidden (inactive) language.
		// Built from cast integers (term_taxonomy_id), so it is injection-safe
		// and contains no printf placeholder for the surrounding prepare().
		$pll_hidden_clause = '';
		if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'PLL' ) && isset( PLL()->model ) ) {
			$hidden_languages = wp_list_filter( PLL()->model->get_languages_list(), array( 'active' => false ) );
			if ( ! empty( $hidden_languages ) ) {
				$hidden_tt_ids = array();
				foreach ( $hidden_languages as $hidden_language ) {
					$hidden_tt_id = (int) $hidden_language->get_tax_prop( 'language', 'term_taxonomy_id' );
					if ( $hidden_tt_id > 0 ) {
						$hidden_tt_ids[] = $hidden_tt_id;
					}
				}
				if ( ! empty( $hidden_tt_ids ) ) {
					$pll_hidden_clause = sprintf(
						' AND p.ID NOT IN ( SELECT tr.object_id FROM %1$s tr WHERE tr.term_taxonomy_id IN ( %2$s ) )',
						$wpdb->term_relationships,
						implode( ',', $hidden_tt_ids )
					);
				}
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.post_type IN ($cpt_placeholders)
			AND p.post_status = 'publish'
			AND p.post_password = ''
			AND (
				NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_noindex
					WHERE pm_noindex.post_id = p.ID
					AND pm_noindex.meta_key = '_seopress_robots_index'
					AND pm_noindex.meta_value = 'yes'
				)
			)
			AND (
				EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_video
					WHERE pm_video.post_id = p.ID
					AND pm_video.meta_key = '_seopress_video'
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_yt
					WHERE pm_yt.post_id = p.ID
					AND pm_yt.meta_key = '_seopress_video_xml_yt'
				)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_disabled
				WHERE pm_disabled.post_id = p.ID
				AND pm_disabled.meta_key = '_seopress_video_disabled'
				AND pm_disabled.meta_value = 'yes'
			)
			{$pll_hidden_clause}
			ORDER BY p.post_modified DESC
			LIMIT %d OFFSET %d",
			array_merge( $cpt, array( 1000, $offset ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$postslist = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $postslist as $id ) {
			// If noindex, continue to next post.
			if ( get_post_meta( $id, '_seopress_robots_index', true ) === 'yes' ) {
				continue;
			}

			$seopress_video_disabled = get_post_meta( $id, '_seopress_video_disabled', true );
			$seopress_video          = get_post_meta( $id, '_seopress_video' );
			$seopress_video_xml_yt   = get_post_meta( $id, '_seopress_video_xml_yt', true );

			if ( ( ! empty( $seopress_video[0][0]['url'] ) || ! empty( $seopress_video_xml_yt ) ) && 'yes' != $seopress_video_disabled ) {
				$seopress_sitemaps .= "\n";
				$seopress_sitemaps .= '<url>';
				$seopress_sitemaps .= "\n";
				$seopress_sitemaps .= '<loc>';
				$seopress_sitemaps .= htmlspecialchars( urldecode( get_permalink( $id ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
				$seopress_sitemaps .= '</loc>';
				$seopress_sitemaps .= "\n";

				if ( isset( $seopress_video_xml_yt ) ) {
					$seopress_sitemaps .= $seopress_video_xml_yt;
				}

				if ( ! empty( $seopress_video[0][0]['url'] ) ) {
					foreach ( $seopress_video[0] as $key => $value ) {
						$seopress_sitemaps .= '<video:video>';
						$seopress_sitemaps .= "\n";

						// Thumbnail.
						$thumbnail = isset( $seopress_video[0][ $key ]['thumbnail'] ) ? $seopress_video[0][ $key ]['thumbnail'] : null;
						if ( '' != $thumbnail ) {// Video Thumbnail.
							$seopress_sitemaps .= '<video:thumbnail_loc>' . htmlspecialchars( urldecode( esc_attr( wp_filter_nohtml_kses( $thumbnail ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '</video:thumbnail_loc>';
							$seopress_sitemaps .= "\n";
						} elseif ( '' != get_the_post_thumbnail_url( $id, 'full' ) ) {// Post Thumbnail.
							$seopress_sitemaps .= '<video:thumbnail_loc>' . htmlspecialchars( urldecode( esc_attr( wp_filter_nohtml_kses( get_the_post_thumbnail_url( $id, 'full' ) ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '</video:thumbnail_loc>';
							$seopress_sitemaps .= "\n";
						}

						// Post Title.
						$title = isset( $seopress_video[0][ $key ]['title'] ) ? $seopress_video[0][ $key ]['title'] : null;
						if ( '' != $title ) {// Video Title.
							$seopress_sitemaps .= '<video:title><![CDATA[' . $title . ']]></video:title>';
							$seopress_sitemaps .= "\n";
						} elseif ( '' != get_post_meta( $id, '_seopress_titles_title', true ) ) {// SEO Custom Title.
							$seopress_sitemaps .= '<video:title><![CDATA[' . get_post_meta( $id, '_seopress_titles_title', true ) . ']]></video:title>';
							$seopress_sitemaps .= "\n";
						} elseif ( '' != get_the_title( $id ) ) {// Post title.
							$seopress_sitemaps .= '<video:title><![CDATA[' . get_the_title( $id ) . ']]></video:title>';
							$seopress_sitemaps .= "\n";
						}

						// Description.
						$desc = isset( $seopress_video[0][ $key ]['desc'] ) ? $seopress_video[0][ $key ]['desc'] : null;
						if ( '' != $desc ) {// Video Description.
							$seopress_sitemaps .= '<video:description><![CDATA[' . $desc . ']]></video:description>';
							$seopress_sitemaps .= "\n";
						} elseif ( '' != get_post_meta( $id, '_seopress_titles_desc', true ) ) {// SEO Custom Meta desc.
							$seopress_sitemaps .= '<video:description><![CDATA[' . get_post_meta( $id, '_seopress_titles_desc', true ) . ']]></video:description>';
							$seopress_sitemaps .= "\n";
						} elseif ( '' != get_the_excerpt( $id ) ) {// Excerpt.
							$seopress_sitemaps .= '<video:description><![CDATA[' . wp_trim_words( esc_attr( wp_filter_nohtml_kses( htmlentities( get_the_excerpt( $id ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) ), 60 ) . ']]></video:description>';
							$seopress_sitemaps .= "\n";
						}

						// URL.
						$internal_video = isset( $seopress_video[0][ $key ]['internal_video'] ) ? $seopress_video[0][ $key ]['internal_video'] : null;
						$url            = isset( $seopress_video[0][ $key ]['url'] ) ? $seopress_video[0][ $key ]['url'] : null;

						if ( '' != $url && '' != $internal_video ) {
							$seopress_sitemaps .= '<video:content_loc><![CDATA[' . $url . ']]></video:content_loc>';
							$seopress_sitemaps .= "\n";
						} elseif ( '' != $url ) {// URL.
							$seopress_sitemaps .= '<video:player_loc><![CDATA[' . $url . ']]></video:player_loc>';
							$seopress_sitemaps .= "\n";
						}

						// Duration.
						$duration = isset( $seopress_video[0][ $key ]['duration'] ) ? $seopress_video[0][ $key ]['duration'] : null;
						if ( '' != $duration ) {
							$seopress_sitemaps .= '<video:duration>' . $duration . '</video:duration>';
							$seopress_sitemaps .= "\n";
						}

						// Rating.
						$rating = isset( $seopress_video[0][ $key ]['rating'] ) ? $seopress_video[0][ $key ]['rating'] : null;
						if ( '' != $rating ) {
							$seopress_sitemaps .= '<video:rating>' . $rating . '</video:rating>';
							$seopress_sitemaps .= "\n";
						}

						// View count.
						$view_count = isset( $seopress_video[0][ $key ]['view_count'] ) ? $seopress_video[0][ $key ]['view_count'] : null;
						if ( '' != $view_count ) {
							$seopress_sitemaps .= '<video:view_count>' . $view_count . '</video:view_count>';
							$seopress_sitemaps .= "\n";
						}

						// Publication date.
						$seopress_sitemaps .= '<video:publication_date>' . get_the_modified_date( 'c', $id ) . '</video:publication_date>';
						$seopress_sitemaps .= "\n";

						// Family Friendly.
						$family_friendly = isset( $seopress_video[0][ $key ]['family_friendly'] ) ? $seopress_video[0][ $key ]['family_friendly'] : null;
						if ( '' != $family_friendly ) {
							$seopress_sitemaps .= '<video:family_friendly>no</video:family_friendly>';
							$seopress_sitemaps .= "\n";
						} else {
							$seopress_sitemaps .= '<video:family_friendly>yes</video:family_friendly>';
							$seopress_sitemaps .= "\n";
						}
						// Tags.
						$tag                = isset( $seopress_video[0][ $key ]['tag'] ) ? $seopress_video[0][ $key ]['tag'] : null;
						$seopress_target_kw = '';
						if ( '' != get_post_meta( $id, '_seopress_analysis_target_kw', true ) ) {
							$seopress_target_kw = get_post_meta( $id, '_seopress_analysis_target_kw', true ) . ',';
						}

						if ( '' != $tag ) {// Video tags.
							$seopress_sitemaps .= '<video:tag>' . esc_attr( wp_filter_nohtml_kses( $tag ) ) . '</video:tag>';
							$seopress_sitemaps .= "\n";
						} else { // Post tags.
							$tags = get_the_tags( $id );
							if ( ! empty( $tags ) ) {
								$count     = count( $tags );
								$i         = 1;
								$tags_list = '';
								foreach ( $tags as $tag ) {
									$tags_list .= $tag->name;
									if ( $i < $count ) {
										$tags_list .= ',';
									}
									++$i;
								}
								$seopress_sitemaps .= '<video:tag>' . $seopress_target_kw . $tags_list . '</video:tag>';
								$seopress_sitemaps .= "\n";
							}
						}

						$seopress_sitemaps .= '</video:video>';
						$seopress_sitemaps .= "\n";
					}
				}
				$seopress_sitemaps .= '</url>';
			}
		}
	}
	$seopress_sitemaps .= "\n";
	$seopress_sitemaps .= '</urlset>';

	$seopress_sitemaps = apply_filters( 'seopress_sitemaps_xml_video', $seopress_sitemaps );

	return $seopress_sitemaps;
}
echo seopress_xml_sitemap_video();
