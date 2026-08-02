<?php

namespace SEOPressPro\Helpers\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SEOIssueDesc {
	public static function getIssueDescI18n( $issueName, $issueDesc ) {
		$html = '';

		switch ( $issueName ) {
			case 'json_schemas_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $schema ) {
						$schema_name  = $schema[0] ? $schema[0] : '';
						$schema_count = $schema[1] ? $schema[1] : '';
						$html        .= '<li>';
						$html        .= sprintf(
							/* translators: %1$s: schema name %2$d: schema count */
							wp_kses_post( __( '<strong>%1$s</strong> is duplicated <strong>%2$d</strong> times', 'wp-seopress-pro' ) ),
							esc_html( $schema_name ),
							absint( $schema_count )
						);
						$html .= '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'json_schemas_not_found':
				$html = esc_html__( 'No schemas found in the source code of this page. Get rich snippets in Google Search results and improve your visibility by adding structured data types (schemas) to your page.', 'wp-seopress-pro' );
				break;
			case 'old_post':
				$issueDesc = $issueDesc ? wp_date( get_option( 'date_format' ), $issueDesc ) : '';
				$html      = sprintf(
					/* translators: %s: post last update */
					wp_kses_post( __( 'Last update: <strong>%s</strong>.', 'wp-seopress-pro' ) ),
					esc_html( $issueDesc )
				);
				break;
			case 'keywords_permalink':
				$html = esc_html__( 'You should add one of your target keyword in your permalink.', 'wp-seopress-pro' );
				break;
			case 'headings_not_found':
				$html = esc_html__( 'Headings are great way to structure your content to make it easily understandable for both humans and crawlers.', 'wp-seopress-pro' );
				break;
			case 'headings_h1_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $h1 ) {
						$h1_value = $h1 ? $h1 : '';
						$html    .= '<li>';
						$html    .= esc_html( $h1_value );
						$html    .= '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'headings_h1_not_found':
				$html = esc_html__( 'No Heading 1 (H1) found in your content. This is required for both SEO and Accessibility!', 'wp-seopress-pro' );
				break;
			case 'headings_h1_without_target_kw':
				$html = esc_html__( 'None of your target keywords were found in Heading 1 (H1).', 'wp-seopress-pro' );
				break;
			case 'headings_h2_without_target_kw':
				$html = esc_html__( 'None of your target keywords were found in Heading 2 (H2).', 'wp-seopress-pro' );
				break;
			case 'headings_h3_without_target_kw':
				$html = esc_html__( 'None of your target keywords were found in Heading 3 (H3).', 'wp-seopress-pro' );
				break;
			case 'title_without_target_kw':
				$html = esc_html__( 'None of your target keywords were found in the Meta Title.', 'wp-seopress-pro' );
				break;
			case 'title_too_long':
				$issueDesc = $issueDesc ? $issueDesc - 60 : '';
				$html      = sprintf(
					/* translators: %d: title length */
					wp_kses_post( __( 'Your title tag is <strong>%d</strong> characters too long.', 'wp-seopress-pro' ) ),
					absint( $issueDesc )
				);
				break;
			case 'title_not_custom':
				$html = esc_html__( 'No custom title is set for this post. If the global meta title suits you, you can ignore this recommendation.', 'wp-seopress-pro' );
				break;
			case 'description_without_target_kw':
				$html = esc_html__( 'None of your target keywords were found in the Meta description.', 'wp-seopress-pro' );
				break;
			case 'description_too_long':
				$issueDesc = $issueDesc ? $issueDesc - 160 : '';
				$html      = sprintf(
					/* translators: %d: description length */
					wp_kses_post( __( 'Your meta description is <strong>%d</strong> characters too long.', 'wp-seopress-pro' ) ),
					absint( $issueDesc )
				);
				break;
			case 'description_not_custom':
				$html = esc_html__( 'No custom meta description is set for this post. If the global meta description suits you, you can ignore this recommendation.', 'wp-seopress-pro' );
				break;
			case 'og_title_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $og_title ) {
						$og_title_value = $og_title ? $og_title : '';
						$html          .= '<li>';
						$html          .= esc_html( $og_title_value );
						$html          .= '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'og_title_empty':
				$html = esc_html__( 'Your Open Graph Title tag is empty!', 'wp-seopress-pro' );
				break;
			case 'og_title_missing':
				$html = esc_html__( 'Your Open Graph Title is missing!', 'wp-seopress-pro' );
				break;
			case 'og_desc_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $og_desc ) {
						$og_desc_value = $og_desc ? $og_desc : '';
						$html         .= '<li>';
						$html         .= esc_html( $og_desc_value );
						$html         .= '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'og_desc_empty':
				$html = esc_html__( 'Your Open Graph Description tag is empty!', 'wp-seopress-pro' );
				break;
			case 'og_desc_missing':
				$html = esc_html__( 'Your Open Graph Description is missing!', 'wp-seopress-pro' );
				break;
			case 'og_img_empty':
				$html = esc_html__( 'Your Open Graph Image tag is empty!', 'wp-seopress-pro' );
				break;
			case 'og_img_missing':
				$html = esc_html__( 'Your Open Graph Image is missing!', 'wp-seopress-pro' );
				break;
			case 'og_url_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $og_url ) {
						$og_url_value = $og_url ? esc_url( $og_url ) : '';
						if ( '' === $og_url_value ) {
							continue;
						}
						$html .= '<li><a href="' . $og_url_value . '" target="_blank" rel="noreferrer noopener">' . $og_url_value . '</a></li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'og_url_empty':
				$html = esc_html__( 'Your Open Graph URL tag is empty!', 'wp-seopress-pro' );
				break;
			case 'og_url_missing':
				$html = esc_html__( 'Your Open Graph URL is missing!', 'wp-seopress-pro' );
				break;
			case 'og_sitename_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $og_sitename ) {
						$og_sitename_value = $og_sitename ? $og_sitename : '';
						$html             .= '<li>';
						$html             .= esc_html( $og_sitename_value );
						$html             .= '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'og_sitename_empty':
				$html = esc_html__( 'Your Open Graph Site Name tag is empty!', 'wp-seopress-pro' );
				break;
			case 'og_sitename_missing':
				$html = esc_html__( 'Your Open Graph Site Name is missing!', 'wp-seopress-pro' );
				break;
			case 'x_title_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $x_title ) {
						$x_title_value = $x_title ? $x_title : '';
						$html         .= '<li>';
						$html         .= esc_html( $x_title_value );
						$html         .= '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'x_title_empty':
				$html = esc_html__( 'Your X Title tag is empty!', 'wp-seopress-pro' );
				break;
			case 'x_title_missing':
				$html = esc_html__( 'Your X Title is missing!', 'wp-seopress-pro' );
				break;
			case 'x_desc_duplicated':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $x_desc ) {
						$x_desc_value = $x_desc ? $x_desc : '';
						$html        .= '<li>';
						$html        .= esc_html( $x_desc_value );
						$html        .= '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'x_desc_empty':
				$html = esc_html__( 'Your X Description tag is empty!', 'wp-seopress-pro' );
				break;
			case 'x_desc_missing':
				$html = esc_html__( 'Your X Description is missing!', 'wp-seopress-pro' );
				break;
			case 'x_img_empty':
				$html = esc_html__( 'Your X Image tag is empty!', 'wp-seopress-pro' );
				break;
			case 'x_img_missing':
				$html = esc_html__( 'Your X Image is missing!', 'wp-seopress-pro' );
				break;
			case 'meta_robots_duplicated':
				$html = sprintf(
					/* translators: %d: meta robots count */
					wp_kses_post( __( 'We found <strong>%d</strong> meta robots in your page. There is probably something wrong with your theme!', 'wp-seopress-pro' ) ),
					absint( $issueDesc )
				);
				break;
			case 'meta_robots_noindex':
				$html = wp_kses_post( __( '<strong>noindex</strong> is on! Search engines can\'t index this page.', 'wp-seopress-pro' ) );
				break;
			case 'meta_robots_nofollow':
				$html = wp_kses_post( __( '<strong>nofollow</strong> is on! Search engines can\'t follow your links on this page.', 'wp-seopress-pro' ) );
				break;
			case 'meta_robots_noimageindex':
				$html = wp_kses_post( __( '<strong>noimageindex</strong> is on! Google will not index your images on this page (but if someone makes a direct link to one of your image in this page, it will be indexed).', 'wp-seopress-pro' ) );
				break;
			case 'meta_robots_nosnippet':
				$html = wp_kses_post( __( '<strong>nosnippet</strong> is on! Search engines will not display a snippet of this page in search results.', 'wp-seopress-pro' ) );
				break;
			case 'img_alt_missing':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $img ) {
						$img_value = $img ? esc_url( $img ) : '';
						if ( '' === $img_value ) {
							continue;
						}
						$html .= '<li><a href="' . $img_value . '" target="_blank" rel="noreferrer noopener">' . $img_value . '</a></li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'img_alt_no_media':
				$html = esc_html__( 'We could not find any image in your content. Content with media is a plus for your SEO.', 'wp-seopress-pro' );
				break;
			case 'nofollow_links_too_many':
				$issueDesc = maybe_unserialize( $issueDesc );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $links ) {
						$links_value = $links ? esc_url( $links ) : '';
						if ( '' === $links_value ) {
							continue;
						}
						$html .= '<li><a href="' . $links_value . '" target="_blank" rel="noreferrer noopener">' . $links_value . '</a></li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'outbound_links_missing':
				$html = esc_html__( 'This page doesn\'t have any outbound links.', 'wp-seopress-pro' );
				break;
			case 'internal_links_missing':
				$html = esc_html__( 'This page doesn\'t have any internal links from other content. Links from archive pages are not considered internal links due to lack of context.', 'wp-seopress-pro' );
				break;
			case 'canonical_duplicated':
				$html = esc_html__( 'You must fix this. Canonical URL duplication is bad for SEO.', 'wp-seopress-pro' );
				break;
			case 'canonical_missing':
				$html = esc_html__( 'This page doesn\'t have any canonical URL.', 'wp-seopress-pro' );
				break;
			case 'content_too_thin':
				$issueDesc  = maybe_unserialize( $issueDesc );
				$word_count = is_array( $issueDesc ) && isset( $issueDesc[0] ) ? absint( $issueDesc[0] ) : 0;
				$html       = sprintf(
					/* translators: %d: word count */
					esc_html__( 'Your content is quite thin (%d words). Aim for more in-depth content with a unique point of view.', 'wp-seopress-pro' ),
					$word_count
				);
				break;
			case 'heading_hierarchy_skipped':
				$issueDesc = maybe_unserialize( $issueDesc );
				$html      = esc_html__( 'Your heading levels are not sequential (a level is skipped):', 'wp-seopress-pro' );
				if ( is_array( $issueDesc ) && ! empty( $issueDesc ) ) {
					$html .= '<ul>';
					foreach ( $issueDesc as $skip ) {
						$html .= '<li>' . esc_html( $skip ) . '</li>';
					}
					$html .= '</ul>';
				}
				break;
			case 'heading_hierarchy_too_few':
				$issueDesc   = maybe_unserialize( $issueDesc );
				$subheadings = is_array( $issueDesc ) && isset( $issueDesc[0] ) ? absint( $issueDesc[0] ) : 0;
				$html        = sprintf(
					/* translators: %d: number of subheadings */
					esc_html__( 'This long content uses only %d subheadings. Break it into more sections with H2/H3 headings.', 'wp-seopress-pro' ),
					$subheadings
				);
				break;
			case 'content_media_missing':
				$html = esc_html__( 'This long content does not contain any image or video. Add relevant, high-quality media to enrich it.', 'wp-seopress-pro' );
				break;
			case 'content_wall_of_text':
				$issueDesc = maybe_unserialize( $issueDesc );
				$ratio     = is_array( $issueDesc ) && isset( $issueDesc[0] ) ? absint( $issueDesc[0] ) : 0;
				$html      = sprintf(
					/* translators: %d: average number of words per section */
					esc_html__( 'Your sections average %d words. Add more subheadings to keep sections shorter and easier to read.', 'wp-seopress-pro' ),
					$ratio
				);
				break;
		}

		$data = array(
			'json_schemas_duplicated'       => __( 'Duplicated JSON schemas', 'wp-seopress-pro' ),
			'json_schemas_not_found'        => __( 'No JSON schemas found', 'wp-seopress-pro' ),
			'old_post'                      => __( 'Post too old', 'wp-seopress-pro' ),
			'keywords_permalink'            => __( 'Target keyword not found in permalink', 'wp-seopress-pro' ),
			'headings_not_found'            => __( 'No headings found', 'wp-seopress-pro' ),
			'headings_h1_duplicated'        => __( 'Duplicated H1', 'wp-seopress-pro' ),
			'headings_h1_not_found'         => __( 'H1 not found', 'wp-seopress-pro' ),
			'headings_h1_without_target_kw' => __( 'H1 without target keyword', 'wp-seopress-pro' ),
			'headings_h2_without_target_kw' => __( 'H2 without target keyword', 'wp-seopress-pro' ),
			'headings_h3_without_target_kw' => __( 'H3 without target keyword', 'wp-seopress-pro' ),
			'title_without_target_kw'       => __( 'Meta title without target keyword', 'wp-seopress-pro' ),
			'title_too_long'                => __( 'Meta title too long', 'wp-seopress-pro' ),
			'title_not_custom'              => __( 'Meta title not customized', 'wp-seopress-pro' ),
			'description_without_target_kw' => __( 'Meta desc without target keyword', 'wp-seopress-pro' ),
			'description_too_long'          => __( 'Meta desc too long', 'wp-seopress-pro' ),
			'description_not_custom'        => __( 'Meta desc not customized', 'wp-seopress-pro' ),
			'og_title_duplicated'           => __( 'OG title duplicated', 'wp-seopress-pro' ),
			'og_title_empty'                => __( 'OG title empty', 'wp-seopress-pro' ),
			'og_title_missing'              => __( 'OG title missing', 'wp-seopress-pro' ),
			'og_desc_duplicated'            => __( 'OG description duplicated', 'wp-seopress-pro' ),
			'og_desc_empty'                 => __( 'OG description empty', 'wp-seopress-pro' ),
			'og_desc_missing'               => __( 'OG description missing', 'wp-seopress-pro' ),
			'og_img_empty'                  => __( 'OG image empty', 'wp-seopress-pro' ),
			'og_img_missing'                => __( 'OG image missing', 'wp-seopress-pro' ),
			'og_url_duplicated'             => __( 'OG URL duplicated', 'wp-seopress-pro' ),
			'og_url_empty'                  => __( 'OG URL empty', 'wp-seopress-pro' ),
			'og_url_missing'                => __( 'OG URL missing', 'wp-seopress-pro' ),
			'og_sitename_duplicated'        => __( 'OG sitename duplicated', 'wp-seopress-pro' ),
			'og_sitename_empty'             => __( 'OG sitename empty', 'wp-seopress-pro' ),
			'og_sitename_missing'           => __( 'OG sitename missing', 'wp-seopress-pro' ),
			'x_title_duplicated'            => __( 'X title duplicated', 'wp-seopress-pro' ),
			'x_title_empty'                 => __( 'X title empty', 'wp-seopress-pro' ),
			'x_title_missing'               => __( 'X title missing', 'wp-seopress-pro' ),
			'x_desc_duplicated'             => __( 'X description duplicated', 'wp-seopress-pro' ),
			'x_desc_empty'                  => __( 'X description empty', 'wp-seopress-pro' ),
			'x_desc_missing'                => __( 'X description missing', 'wp-seopress-pro' ),
			'x_img_empty'                   => __( 'X image empty', 'wp-seopress-pro' ),
			'x_img_missing'                 => __( 'X image missing', 'wp-seopress-pro' ),
			'meta_robots_duplicated'        => __( 'Meta robots duplicated', 'wp-seopress-pro' ),
			'meta_robots_noindex'           => __( 'noindex is ON', 'wp-seopress-pro' ),
			'meta_robots_nofollow'          => __( 'nofollow is ON', 'wp-seopress-pro' ),
			'meta_robots_noimageindex'      => __( 'noimageindex is ON', 'wp-seopress-pro' ),
			'meta_robots_nosnippet'         => __( 'nosnippet is ON', 'wp-seopress-pro' ),
			'img_alt_missing'               => __( 'Alt text missing', 'wp-seopress-pro' ),
			'img_alt_no_media'              => __( 'No media found', 'wp-seopress-pro' ),
			'nofollow_links_too_many'       => __( 'Too many nofollow links', 'wp-seopress-pro' ),
			'outbound_links_missing'        => __( 'Outbound links missing', 'wp-seopress-pro' ),
			'internal_links_missing'        => __( 'Internal links missing', 'wp-seopress-pro' ),
			'canonical_duplicated'          => __( 'Duplicated canonical tag', 'wp-seopress-pro' ),
			'canonical_missing'             => __( 'Canonical tag missing', 'wp-seopress-pro' ),
			'content_too_thin'              => __( 'Content too thin', 'wp-seopress-pro' ),
			'heading_hierarchy_skipped'     => __( 'Heading level skipped', 'wp-seopress-pro' ),
			'heading_hierarchy_too_few'     => __( 'Too few subheadings', 'wp-seopress-pro' ),
			'content_media_missing'         => __( 'No media in content', 'wp-seopress-pro' ),
			'content_wall_of_text'          => __( 'Wall of text', 'wp-seopress-pro' ),
		);

		return $html;
	}
}
