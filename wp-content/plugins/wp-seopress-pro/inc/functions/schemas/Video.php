<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Video Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets videos option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_videos_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		$videos_name          = $schema_datas['name'];
		$videos_description   = $schema_datas['description'];
		$videos_img           = $schema_datas['img'];
		$videos_duration      = $schema_datas['duration'];
		$videos_url           = $schema_datas['url'];
		$videos_uploaded_date = $schema_datas['date_posted'];

		$json = array(
			'@context'     => seopress_check_ssl() . 'schema.org',
			'@type'        => 'VideoObject',
			'name'         => $videos_name,
			'description'  => $videos_description,
			'thumbnailUrl' => $videos_img,
			'contentUrl'   => $videos_url,
			'embedUrl'     => function_exists( 'seopress_pro_get_youtube_embed_url' ) ? seopress_pro_get_youtube_embed_url( $videos_url ) : $videos_url,
		);

		if ( ! empty( $videos_uploaded_date ) ) {
			$json['uploadDate'] = gmdate( 'c', strtotime( $videos_uploaded_date ) );
		} elseif ( get_the_date() ) {
			$json['uploadDate'] = get_the_date( 'c' );
		}

		if ( '' != $videos_duration ) {
			$time = explode( ':', $videos_duration );
			$sec  = isset( $time[2] ) && is_numeric( $time[2] ) ? (int) $time[2] : 0;

			// Ensure we have numeric values before performing arithmetic.
			$hours   = isset( $time[0] ) && is_numeric( $time[0] ) ? (int) $time[0] : 0;
			$minutes = isset( $time[1] ) && is_numeric( $time[1] ) ? (int) $time[1] : 0;
			$min     = ( $hours * 60 + $minutes );

			$json['duration'] = 'PT' . $min . 'M' . $sec . 'S';
		}

		if ( ! empty( seopress_get_service( 'SocialOption' )->getSocialKnowledgeName() ) ) {
			$json['publisher'] = array(
				'@type' => 'Organization',
				'name'  => seopress_get_service( 'SocialOption' )->getSocialKnowledgeName(),
			);

			// Same business as the Knowledge Graph node the free plugin prints,
			// so both carry one identifier instead of standing as two nodes
			// nothing connects. Empty whenever they would not match.
			$publisher_id = \SEOPressPro\Helpers\PublisherEntity::get_id();
			if ( '' !== $publisher_id ) {
				$json['publisher']['@id'] = $publisher_id;
			}

			// Only with a publisher logo set: `array_filter()` below does not reach
			// nested arrays, so an empty logo used to print `"url":null`.
			$publisher_logo = seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogo();
			if ( ! empty( $publisher_logo ) ) {
				$json['publisher']['logo'] = array_filter(
					array(
						'@type'  => 'ImageObject',
						'url'    => $publisher_logo,
						'width'  => seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoWidth(),
						'height' => seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoHeight(),
					)
				);
			}
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_video_json', $json );

		$json = '<script type="application/ld+json">' . seopress_pro_json_ld_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_video_html', $json );

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD document encoded with wp_json_encode() above, filterable by third parties.
	}
}
