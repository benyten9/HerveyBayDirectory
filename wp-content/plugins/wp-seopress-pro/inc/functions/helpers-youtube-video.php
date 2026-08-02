<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO YouTube Video Helpers.
 *
 * Shared YouTube detection + Data API fetch used by:
 *  - The XML Video Sitemap (inc/functions/options-video-sitemap.php).
 *  - The automatic Video schema YouTube auto-fill (inc/functions/options-automatic-rich-snippets.php).
 *  - The %%youtube_video_*%% dynamic variables for custom Schemas.
 *
 * @package SEOPress PRO
 * @subpackage Helpers
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! function_exists( 'seopress_pro_detect_youtube_video_ids' ) ) {
	/**
	 * Detect YouTube video IDs in a string of content.
	 *
	 * Matches youtu.be / youtube.com / youtube-nocookie.com URLs and returns
	 * the unique list of IDs in the order they appear in the content.
	 *
	 * @param string $content Raw HTML/text to scan.
	 * @return string[] Unique video IDs (empty array when none).
	 */
	function seopress_pro_detect_youtube_video_ids( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return array();
		}

		$ids = array();

		/*
		 * Only treat a path segment as a video ID when it is a canonical
		 * 11-character YouTube ID reached through a known video path
		 * (watch / embed / v / shorts / live), or a youtu.be short link.
		 *
		 * The path prefix is required for youtube.com / youtube-nocookie.com so
		 * channel or handle URLs (e.g. youtube.com/seopress, youtube.com/@seopress,
		 * youtube.com/c/foo) are no longer mistaken for a video ID and sent to the
		 * Data API, which would answer 403 Forbidden. The trailing lookahead keeps
		 * us from truncating a longer token into a false 11-character match.
		 */
		$pattern = '/(?:youtu\.be\/|(?:youtube\.com|youtube-nocookie\.com)\/(?:v\/|watch\?v=|embed\/|shorts\/|live\/))([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/';

		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $video ) {
				if ( ! empty( $video[1] ) ) {
					$ids[] = $video[1];
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}
}

if ( ! function_exists( 'seopress_pro_get_youtube_api_key' ) ) {
	/**
	 * Get the YouTube Data API v3 key used to query video metadata.
	 *
	 * Falls back to the bundled SEOPress key and is filterable so site owners
	 * can plug their own quota.
	 *
	 * @return string
	 */
	function seopress_pro_get_youtube_api_key() {
		return apply_filters( 'seopress_video_youtube_api_key', 'AIzaSyAQ6Dy36nerzetLbAt3xCClcOpOwAnpRu0' );
	}
}

if ( ! function_exists( 'seopress_pro_fetch_youtube_oembed_item' ) ) {
	/**
	 * Build a Data-API-shaped item from YouTube's public oEmbed endpoint.
	 *
	 * oEmbed needs no API key and consumes no Data API quota, so it keeps the
	 * Video sitemap and the auto Video schema working when the shared/bundled
	 * Data API key is rate-limited (HTTP 403 quotaExceeded) or when the site
	 * never set its own key. It only exposes the title and the thumbnail, so
	 * the returned object mimics just the fields both consumers read; missing
	 * fields (description, duration, publishedAt, viewCount) are left unset and
	 * the downstream code already guards them with isset()/empty().
	 *
	 * @param string $video_id YouTube video ID.
	 * @return object|null stdClass shaped like a Data API "item", or null.
	 */
	function seopress_pro_fetch_youtube_oembed_item( $video_id ) {
		$endpoint = add_query_arg(
			array(
				'url'    => rawurlencode( 'https://www.youtube.com/watch?v=' . $video_id ),
				'format' => 'json',
			),
			'https://www.youtube.com/oembed'
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'     => 5,
				'redirection' => 5,
				'blocking'    => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $body->title ) ) {
			return null;
		}

		$thumbnail = isset( $body->thumbnail_url ) && ! empty( $body->thumbnail_url )
			? (string) $body->thumbnail_url
			: 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg';

		$item                       = new stdClass();
		$item->id                   = $video_id;
		$item->snippet              = new stdClass();
		$item->snippet->title       = (string) $body->title;
		$item->snippet->thumbnails  = new stdClass();
		$thumb_obj                  = new stdClass();
		$thumb_obj->url             = $thumbnail;
		// Expose the same thumbnail under every size key both consumers look up.
		foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) {
			$item->snippet->thumbnails->{$size} = $thumb_obj;
		}

		return $item;
	}
}

if ( ! function_exists( 'seopress_pro_fetch_youtube_video_data' ) ) {
	/**
	 * Fetch one YouTube video's metadata, with caching.
	 *
	 * Tries the Data API v3 first (richest metadata). When that call fails for
	 * any reason (network error, non-200, quotaExceeded, empty payload) it falls
	 * back to the keyless oEmbed endpoint so the feature keeps working with at
	 * least a title and a thumbnail. Results are cached in a transient keyed by
	 * the video ID. The default TTL is 12 hours and can be filtered with
	 * seopress_pro_youtube_video_data_ttl; the leaner oEmbed fallback is cached
	 * for a shorter window so a recovered Data API quota is picked up sooner.
	 *
	 * @param string $video_id YouTube video ID.
	 * @return object|null Decoded "item" object on success, null otherwise.
	 */
	function seopress_pro_fetch_youtube_video_data( $video_id ) {
		if ( empty( $video_id ) || ! is_string( $video_id ) ) {
			return null;
		}

		// YouTube video IDs are exactly 11 characters. Reject anything else up
		// front so a malformed ID never triggers a doomed (403) Data API call.
		if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $video_id ) ) {
			return null;
		}

		$cache_key = 'seopress_pro_yt_data_' . md5( $video_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			// A cached non-object is the negative-result sentinel (see below).
			return is_object( $cached ) ? $cached : null;
		}

		$item       = null;
		$is_partial = false;

		$url = add_query_arg(
			array(
				'key'  => rawurlencode( seopress_pro_get_youtube_api_key() ),
				'part' => 'snippet,contentDetails,statistics,status,player',
				'id'   => rawurlencode( $video_id ),
			),
			'https://www.googleapis.com/youtube/v3/videos'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 5,
				'blocking'    => true,
			)
		);

		// Try the Data API first (richest metadata).
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ) );
			if ( ! empty( $data->items[0] ) ) {
				$item = $data->items[0];
			}
		}

		// Data API unavailable (quota, no key, error): fall back to oEmbed.
		if ( null === $item ) {
			$item       = seopress_pro_fetch_youtube_oembed_item( $video_id );
			$is_partial = true;
		}

		// Both the Data API and oEmbed failed: the video is invalid / deleted /
		// private (or there is a temporary outage). Cache a negative sentinel so
		// the lookup is not retried on every page render.
		if ( null === $item ) {
			/**
			 * Filter the cache TTL (in seconds) used to remember a *failed* lookup so an
			 * invalid / deleted / private video (or a temporary API outage) is not
			 * re-queried on every page render. Stored as a non-object sentinel.
			 *
			 * @param int    $ttl      Default HOUR_IN_SECONDS.
			 * @param string $video_id YouTube video ID.
			 */
			$negative_ttl = (int) apply_filters( 'seopress_pro_youtube_video_data_negative_ttl', HOUR_IN_SECONDS, $video_id );
			set_transient( $cache_key, '', $negative_ttl );
			return null;
		}

		/**
		 * Filter the cache TTL (in seconds) for YouTube video metadata.
		 *
		 * @param int    $ttl      Default 12 * HOUR_IN_SECONDS.
		 * @param string $video_id YouTube video ID.
		 */
		$ttl = (int) apply_filters( 'seopress_pro_youtube_video_data_ttl', 12 * HOUR_IN_SECONDS, $video_id );

		// Cache the leaner oEmbed payload for less time so richer Data API
		// metadata is fetched again once the quota recovers.
		if ( $is_partial ) {
			$ttl = (int) apply_filters( 'seopress_pro_youtube_video_data_partial_ttl', HOUR_IN_SECONDS, $video_id );
		}

		set_transient( $cache_key, $item, $ttl );

		return $item;
	}
}

if ( ! function_exists( 'seopress_pro_get_post_youtube_video_data' ) ) {
	/**
	 * Get normalized YouTube metadata for the first YouTube video detected
	 * in a post's content.
	 *
	 * Returns an associative array of pre-formatted fields ready to be fed
	 * into the Video schema, dynamic variables, etc. Uses a per-request
	 * static cache plus the transient set by seopress_pro_fetch_youtube_video_data().
	 *
	 * @param int|null $post_id Post ID (defaults to current post).
	 * @return array Normalized fields. Empty array when no YouTube video is found
	 *               or the API call fails.
	 */
	function seopress_pro_get_post_youtube_video_data( $post_id = null ) {
		static $memo = array();

		/**
		 * Whether the automatic YouTube metadata resolution is enabled — i.e. the
		 * auto-filled Video schema and the %%youtube_video_*%% dynamic variables.
		 *
		 * Disabled by default as a hotfix: this resolution runs on every front-end
		 * render and queried the shared bundled YouTube Data API key, whose daily
		 * quota is permanently exhausted across all installs (HTTP 403
		 * quotaExceeded). With it off, the Video schema falls back to manual entry
		 * (pre-9.9 behaviour) and the Video sitemap — which fetches at save time,
		 * not on render — keeps working and lets the shared quota recover. The full
		 * fix (per-site API key, save-time fetching) is tracked in
		 * wp-seopress-pro#1474; set this filter to true to re-enable the auto-fill.
		 *
		 * @param bool     $enabled Default false.
		 * @param int|null $post_id Post ID being resolved.
		 */
		if ( ! apply_filters( 'seopress_pro_youtube_video_autofill', false, $post_id ) ) {
			return array();
		}

		if ( null === $post_id ) {
			$post_id = (int) get_the_ID();
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}

		if ( isset( $memo[ $post_id ] ) ) {
			return $memo[ $post_id ];
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			$memo[ $post_id ] = array();
			return $memo[ $post_id ];
		}

		// Allow filtering of the content scanned, mirroring the existing
		// video sitemap behavior so both features stay aligned.
		$content = apply_filters( 'seopress_pro_video_sitemap_content', $post->post_content, $post->ID );

		$ids = seopress_pro_detect_youtube_video_ids( $content );
		if ( empty( $ids ) ) {
			$memo[ $post_id ] = array();
			return $memo[ $post_id ];
		}

		$item = seopress_pro_fetch_youtube_video_data( $ids[0] );
		if ( null === $item ) {
			$memo[ $post_id ] = array();
			return $memo[ $post_id ];
		}

		$name        = isset( $item->snippet->title ) ? (string) $item->snippet->title : '';
		$description = isset( $item->snippet->description ) ? (string) $item->snippet->description : '';
		$published   = isset( $item->snippet->publishedAt ) ? (string) $item->snippet->publishedAt : '';
		$view_count  = isset( $item->statistics->viewCount ) ? (string) $item->statistics->viewCount : '';

		$thumbnail = '';
		if ( isset( $item->snippet->thumbnails ) ) {
			foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) {
				if ( isset( $item->snippet->thumbnails->{$size}->url ) ) {
					$thumbnail = (string) $item->snippet->thumbnails->{$size}->url;
					break;
				}
			}
		}

		// Convert YouTube's ISO-8601 duration (e.g. PT4M30S) into the
		// hh:mm:ss format expected by seopress_automatic_rich_snippets_videos_option().
		$duration_hms = '';
		if ( isset( $item->contentDetails->duration ) && ! empty( $item->contentDetails->duration ) ) {
			try {
				$interval     = new DateInterval( (string) $item->contentDetails->duration );
				$total_hours  = $interval->days * 24 + $interval->h;
				$duration_hms = sprintf( '%02d:%02d:%02d', $total_hours, $interval->i, $interval->s );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$duration_hms = '';
			}
		}

		$id          = isset( $item->id ) ? (string) $item->id : $ids[0];
		$content_url = 'https://www.youtube.com/watch?v=' . $id;
		$embed_url   = 'https://www.youtube.com/embed/' . $id;

		$data = array(
			'id'           => $id,
			'name'         => $name,
			'description'  => $description,
			'img'          => $thumbnail,
			'duration'     => $duration_hms,
			'url'          => $content_url,
			'content_url'  => $content_url,
			'embed_url'    => $embed_url,
			'date_posted'  => $published,
			'upload_date'  => $published,
			'view_count'   => $view_count,
		);

		/**
		 * Filter the normalized YouTube data array used by the Video schema
		 * auto-fill and the %%youtube_video_*%% dynamic variables.
		 *
		 * @param array  $data    Normalized data.
		 * @param object $item    Raw decoded API item.
		 * @param int    $post_id Post ID being processed.
		 */
		$data = apply_filters( 'seopress_pro_youtube_video_data', $data, $item, $post_id );

		$memo[ $post_id ] = $data;
		return $memo[ $post_id ];
	}
}

if ( ! function_exists( 'seopress_pro_collect_builder_youtube_urls' ) ) {
	/**
	 * Build a synthetic content string carrying every YouTube URL detected
	 * in known page builder postmeta.
	 *
	 * Page builders use incompatible storage shapes for embedded videos:
	 *
	 *  - Bricks stores only the bare YouTube ID under a "youTubeId" key in
	 *    a PHP-serialized settings array (no URL on disk). We pattern-match
	 *    that signature and synthesize a youtube.com URL from each ID so
	 *    the downstream regex in seopress_pro_detect_youtube_video_ids()
	 *    keeps working unchanged.
	 *  - Elementor stores the full YouTube URL in its JSON-encoded
	 *    _elementor_data meta, with slashes escaped as "\/". Once the
	 *    escaped slashes are normalized the URL becomes matchable.
	 *  - Oxygen stores its layout as a shortcode string that already
	 *    contains plain URLs.
	 *
	 * The result is appended to the content fed to
	 * seopress_pro_video_sitemap_content, which backs both the XML Video
	 * Sitemap and the automatic Video schema YouTube auto-fill.
	 *
	 * @param int $post_id Post ID to inspect.
	 * @return string Synthetic content (empty when no builder data is found).
	 */
	function seopress_pro_collect_builder_youtube_urls( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}

		$buffer = '';

		// Bricks Builder: serialized settings array with a bare "youTubeId" key.
		$bricks_keys = array(
			'_bricks_page_content_2',
			'_bricks_page_header_2',
			'_bricks_page_footer_2',
		);
		foreach ( $bricks_keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( empty( $value ) ) {
				continue;
			}
			$haystack = is_array( $value ) ? serialize( $value ) : (string) $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			if ( preg_match_all( '/"youTubeId";s:\d+:"([A-Za-z0-9_\-]{6,})"/', $haystack, $matches ) ) {
				foreach ( $matches[1] as $id ) {
					$buffer .= ' https://www.youtube.com/watch?v=' . $id;
				}
			}
		}

		// Elementor + Oxygen: pass the layout through after normalizing
		// JSON-escaped slashes so any full youtube.com / youtu.be URL inside
		// the JSON or shortcode markup becomes matchable by the regex.
		$passthrough_keys = array(
			'_elementor_data',
			'_ct_builder_shortcodes',
			'ct_builder_shortcodes',
		);
		foreach ( $passthrough_keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( empty( $value ) ) {
				continue;
			}
			$value   = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
			$value   = str_replace( '\/', '/', $value );
			$buffer .= ' ' . $value;
		}

		/**
		 * Filter the synthetic content appended to the YouTube scan for
		 * page-builder-built posts. Use this to add support for additional
		 * builders (Breakdance, Divi blocks, custom storage, etc.).
		 *
		 * @param string $buffer  Synthetic content built from known builders.
		 * @param int    $post_id Post being processed.
		 */
		return (string) apply_filters( 'seopress_pro_video_sitemap_builder_content', $buffer, $post_id );
	}
}

if ( ! function_exists( 'seopress_pro_append_page_builders_content' ) ) {
	/**
	 * Append page builder content to the string scanned for YouTube embeds.
	 *
	 * Hooked into seopress_pro_video_sitemap_content, which feeds both the
	 * XML Video Sitemap and the automatic Video schema YouTube auto-fill.
	 *
	 * @param string $content Default content (usually $post->post_content).
	 * @param int    $post_id Post ID being processed.
	 * @return string
	 */
	function seopress_pro_append_page_builders_content( $content, $post_id ) {
		$extra = seopress_pro_collect_builder_youtube_urls( $post_id );
		if ( '' !== $extra ) {
			$content .= ' ' . $extra;
		}
		return $content;
	}
	add_filter( 'seopress_pro_video_sitemap_content', 'seopress_pro_append_page_builders_content', 5, 2 );
}
