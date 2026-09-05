<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Article Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets articles option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_articles_option( $schema_datas ) {

	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		$article_type                = $schema_datas['type'];
		$article_title               = $schema_datas['title'];
		$article_desc                = $schema_datas['desc'];
		$article_author              = $schema_datas['author'];
		$article_img                 = $schema_datas['img'];
		$article_coverage_start_date = $schema_datas['coverage_start_date'];
		$article_coverage_start_time = $schema_datas['coverage_start_time'];
		$article_coverage_end_date   = $schema_datas['coverage_end_date'];
		$article_coverage_end_time   = $schema_datas['coverage_end_time'];
		$article_speakable           = $schema_datas['speakable'];

		$json = array(
			'@context'      => seopress_check_ssl() . 'schema.org/',
			'@type'         => $article_type,
			'datePublished' => get_the_date( 'c' ),
			'dateModified'  => get_the_modified_date( 'c' ),
		);

		$article_canonical = '';
		if ( get_post_meta( get_the_ID(), '_seopress_robots_canonical', true ) ) {
			$article_canonical = get_post_meta( get_the_ID(), '_seopress_robots_canonical', true );
		} else {
			global $wp;
			if ( isset( $wp->request ) ) {
				$article_canonical = user_trailingslashit( home_url( add_query_arg( array(), $wp->request ) ) );
			}
		}

		if ( ! empty( $article_canonical ) ) {
			$json['mainEntityOfPage'] = array(
				'@type' => 'WebPage',
				'@id'   => $article_canonical,
			);
		}

		$json['headline'] = $article_title;

		// When the user explicitly picks "None" for the author field, the legacy
		// resolver in options-automatic-rich-snippets.php returns null (it short-
		// circuits on a literal 'none' meta value). An empty string means the
		// field was never configured, in which case we keep the historical
		// behavior of falling back to the WP post author.
		if ( null !== $article_author ) {
			$author = get_the_author();
			if ( '' != $article_author ) {
				$author = $article_author;
			}

			$author_url = get_the_author_meta( 'user_url' );

			if ( ! empty( $author ) && empty( $author_url ) ) {
				$author_url = get_author_posts_url( get_the_author_meta( 'ID' ) );
			} elseif ( is_author() && is_int( get_queried_object_id() ) && empty( $author_url ) ) {
				$author_url = get_author_posts_url( get_queried_object_id() );
			}

			$json['author'] = array(
				'@type' => 'Person',
				'name'  => $author,
			);

			if ( ! empty( $author_url ) ) {
				$json['author']['url'] = $author_url;
			}

			// Match the manual schema behavior by emitting the post author's
			// social profiles as `sameAs`. The SocialProfiles helper reads the
			// SEOPress user meta keys (_seopress_user_social_*) plus
			// Yoast / Rank Math / AIOSEO fallbacks so existing migrations keep
			// working without re-entering anything. Purely additive: an author
			// with no social profile fields filled in produces no `sameAs` key,
			// matching the previous output.
			$author_user_id = (int) get_the_author_meta( 'ID' );
			if ( ! $author_user_id && isset( $GLOBALS['post']->post_author ) ) {
				$author_user_id = (int) $GLOBALS['post']->post_author;
			}
			if ( $author_user_id && class_exists( '\SEOPressPro\Helpers\SocialProfiles' ) ) {
				$same_as = \SEOPressPro\Helpers\SocialProfiles::getSameAs( $author_user_id );
				if ( ! empty( $same_as ) ) {
					$json['author']['sameAs'] = array_values( array_unique( $same_as ) );
				}
			}
		}

		if ( '' != $article_img ) {
			$json['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $article_img,
			);
		}

		if ( ! empty( seopress_get_service( 'SocialOption' )->getSocialKnowledgeName() ) ) {
			$json['publisher'] = array(
				'@type' => 'Organization',
				'name'  => seopress_get_service( 'SocialOption' )->getSocialKnowledgeName(),
			);
			if ( ! empty( seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogo() ) ) {
				$json['publisher']['logo'] = array(
					'@type'  => 'ImageObject',
					'url'    => seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogo(),
					'width'  => seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoWidth(),
					'height' => seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoHeight(),
				);

				if ( empty( seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoWidth() ) && empty( seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoHeight() ) ) {
					unset( $json['publisher']['logo']['width'] );
					unset( $json['publisher']['logo']['height'] );
				}
			}

			$facebook  = seopress_get_service( 'SocialOption' )->getSocialAccountsFacebook();
			$twitter   = seopress_get_service( 'SocialOption' )->getSocialAccountsTwitter();
			$pinterest = seopress_get_service( 'SocialOption' )->getSocialAccountsPinterest();
			$instagram = seopress_get_service( 'SocialOption' )->getSocialAccountsInstagram();
			$youtube   = seopress_get_service( 'SocialOption' )->getSocialAccountsYoutube();
			$linkedin  = seopress_get_service( 'SocialOption' )->getSocialAccountsLinkedin();

			$extra = '';

			if ( version_compare( SEOPRESS_VERSION, '6.5', '>=' ) ) {
				$extra = seopress_get_service( 'SocialOption' )->getSocialAccountsExtra();
			}

			$accounts = array();

			if ( '' != $facebook ) {
				array_push( $accounts, $facebook );
			}
			if ( '' != $twitter ) {
				// The stored value is normalized to "@handle" on save
				// (free plugin, Sanitize.php). Strip the leading @ before
				// composing the URL so we emit "https://x.com/handle" and
				// not "https://x.com/@handle". Align the domain with the
				// free plugin's social_account_twitter tag (x.com).
				$twitter = 'https://x.com/' . ltrim( trim( $twitter ), '@' );
				array_push( $accounts, $twitter );
			}
			if ( '' != $pinterest ) {
				array_push( $accounts, $pinterest );
			}
			if ( '' != $instagram ) {
				array_push( $accounts, $instagram );
			}
			if ( '' != $youtube ) {
				array_push( $accounts, $youtube );
			}
			if ( '' != $linkedin ) {
				array_push( $accounts, $linkedin );
			}

			if ( '' != $extra ) {
				$extra    = preg_split( '/\r\n|\r|\n/', $extra );
				$accounts = array_merge( $accounts, $extra );
			}

			if ( ! empty( $accounts ) ) {
				$json['publisher']['sameAs'] = $accounts;
			}
		}

		if ( $article_coverage_start_date && $article_coverage_start_time && 'LiveBlogPosting' == $article_type ) {
			$json['coverageStartTime'] = $article_coverage_start_date . 'T' . $article_coverage_start_time;
		}

		if ( $article_coverage_end_date && $article_coverage_end_time && 'LiveBlogPosting' == $article_type ) {
			$json['coverageEndTime'] = $article_coverage_end_date . 'T' . $article_coverage_end_time;
		}

		if ( 'ReviewNewsArticle' == $article_type ) {
			$json['itemReviewed'] = array(
				'@type' => 'Thing',
				'name'  => get_the_title(),
			);
		}

		// Trimming an escaped string can cut through an entity and leave `&am`
		// behind, and the escape buys nothing here: the excerpt is encoded as
		// JSON-LD, not as HTML. wp_trim_words() strips the tags itself.
		$desc = wp_trim_words( get_the_excerpt(), 30 );
		if ( '' != $article_desc ) {
			$desc = $article_desc;
		}

		$json['description'] = $desc;

		if ( '' != $article_speakable ) {
			$json['speakable'] = array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => $article_speakable,
			);
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_article_json', $json );

		$json = '<script type="application/ld+json">' . seopress_pro_json_ld_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_article_html', $json );

		echo $json;
	}
}
