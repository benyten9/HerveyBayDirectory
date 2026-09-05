<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Video Sitemap.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! $post ) {
	return false;
}

/*
 * Get post content
 *
 * @var string
 */
$content = apply_filters( 'seopress_pro_video_sitemap_content', $post->post_content, $post->ID );

/*
 * Get videos ID from content.
 *
 * Uses the shared detection helper so the sitemap and the Video schema auto-fill
 * stay aligned and only canonical 11-character YouTube IDs reach the Data API
 * (channel / handle URLs such as youtube.com/seopress are ignored).
 *
 * @var array
 */
$video_id = function_exists( 'seopress_pro_detect_youtube_video_ids' )
	? seopress_pro_detect_youtube_video_ids( $content )
	: array();

if ( empty( $video_id ) ) {
	delete_post_meta( $post->ID, '_seopress_video_xml_yt' );
}

/*
 * Get video details.
 *
 * Routed through the shared helper so both the Video sitemap and the auto
 * Video schema use the same fetch path: Data API v3 first, keyless oEmbed
 * fallback when the API key is rate-limited (quotaExceeded) or unset.
 *
 * @var array
 * return array
 */
$video_items = array();
if ( ! empty( $video_id ) && function_exists( 'seopress_pro_fetch_youtube_video_data' ) ) {
	$video_id = array_unique( $video_id );
	foreach ( $video_id as $id ) {
		$item = seopress_pro_fetch_youtube_video_data( $id );
		if ( null !== $item ) {
			$video_items[] = $item;
		}
	}
}

/*
 * Build Video XML Sitemaps
 * @var array
 * return string
 */
if ( ! empty( $video_items ) ) {
	$video_xml = '';
	foreach ( $video_items as $item ) {
		$video_xml .= '<video:video>';
		$video_xml .= "\n";

		if ( isset( $item->snippet->thumbnails->high->url ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$video_xml .= '<video:thumbnail_loc>' . htmlspecialchars( urldecode( esc_attr( wp_filter_nohtml_kses( $item->snippet->thumbnails->high->url ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) . '</video:thumbnail_loc>';
			$video_xml .= "\n";
		}

		if ( isset( $item->snippet->title ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$video_xml .= '<video:title><![CDATA[' . $item->snippet->title . ']]></video:title>';
			$video_xml .= "\n";
		}

		// Google requires a description: fall back to the video title when the
		// metadata source (oEmbed) does not provide one.
		$video_description = isset( $item->snippet->description ) && '' !== $item->snippet->description
			? $item->snippet->description
			: ( isset( $item->snippet->title ) ? $item->snippet->title : '' );
		if ( '' !== $video_description ) {
			$video_xml .= '<video:description><![CDATA[' . esc_attr( wp_filter_nohtml_kses( htmlentities( substr( $video_description, 0, 2000 ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) ) . ']]></video:description>';
			$video_xml .= "\n";
		}

		if ( isset( $item->id ) ) {
			$video_xml .= '<video:content_loc><![CDATA[https://www.youtube.com/watch?v=' . $item->id . ']]></video:content_loc>';
			$video_xml .= "\n";
			$video_xml .= '<video:player_loc><![CDATA[https://www.youtube.com/watch?v=' . $item->id . ']]></video:player_loc>';
			$video_xml .= "\n";
		}

		if ( isset( $item->contentDetails->duration ) && ! empty( $item->contentDetails->duration ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			try {
				$duration = new DateInterval( $item->contentDetails->duration ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$duration = $duration->days * 86400 + $duration->h * 3600 + $duration->i * 60 + $duration->s;

				$video_xml .= '<video:duration>' . $duration . '</video:duration>';
				$video_xml .= "\n";
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Skip duration if format is invalid.
			}
		}

		if ( isset( $item->statistics->viewCount ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$video_xml .= '<video:view_count><![CDATA[' . $item->statistics->viewCount . ']]></video:view_count>';
			$video_xml .= "\n";
		}

		if ( isset( $item->snippet->publishedAt ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$video_xml .= '<video:publication_date><![CDATA[' . $item->snippet->publishedAt . ']]></video:publication_date>';
			$video_xml .= "\n";
		}

		$video_xml .= '<video:family_friendly>yes</video:family_friendly>';
		$video_xml .= "\n";
		$video_xml .= '</video:video>';
		$video_xml .= "\n";
	}

	update_post_meta( $post->ID, '_seopress_video_xml_yt', $video_xml );
}
