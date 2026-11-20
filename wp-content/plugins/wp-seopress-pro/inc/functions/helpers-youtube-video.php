<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO YouTube Video Helpers.
 *
 * Shared YouTube detection + metadata fetch used by:
 *  - The XML Video Sitemap (inc/functions/options-video-sitemap.php).
 *  - The automatic Video schema YouTube auto-fill (inc/functions/options-automatic-rich-snippets.php).
 *  - The youtube_video_* dynamic variables of automatic schemas.
 *
 * YouTube is only queried when a post is saved, or from a background WP-Cron
 * job for posts saved before their metadata was stored. Rendering a page reads
 * the _seopress_pro_youtube_video_data post meta and never calls YouTube.
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

if ( ! function_exists( 'seopress_pro_get_youtube_embed_url' ) ) {
	/**
	 * Get the player URL of a YouTube video: what embedUrl and player_loc expect.
	 *
	 * Every place printing a video's player URL goes through this, so a YouTube
	 * video gets the same https://www.youtube.com/embed/{ID} URL in the Video
	 * schemas (automatic and manual) and in the XML Video sitemap, whatever
	 * form the URL was entered in (watch, youtu.be, shorts, live, embed,
	 * youtube-nocookie).
	 *
	 * @since 10.3.0
	 *
	 * @param string $url Video URL.
	 * @return string The embed URL, or $url unchanged when it is not a YouTube video URL.
	 */
	function seopress_pro_get_youtube_embed_url( $url ) {
		$ids = seopress_pro_detect_youtube_video_ids( (string) $url );

		return empty( $ids ) ? (string) $url : 'https://www.youtube.com/embed/' . $ids[0];
	}
}

if ( ! function_exists( 'seopress_pro_detect_post_youtube_video_ids' ) ) {
	/**
	 * Detect the YouTube video IDs of a post, page builder data included.
	 *
	 * @since 10.3.0
	 *
	 * @param WP_Post $post Post to scan.
	 * @return string[] Unique video IDs (empty array when none).
	 */
	function seopress_pro_detect_post_youtube_video_ids( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		// Filterable so the Video sitemap and the Video schema scan the same content.
		$content = apply_filters( 'seopress_pro_video_sitemap_content', $post->post_content, $post->ID );

		return seopress_pro_detect_youtube_video_ids( $content );
	}
}

if ( ! function_exists( 'seopress_pro_youtube_api_key_is_constant' ) ) {
	/**
	 * Whether the YouTube Data API key is set with the SEOPRESS_YOUTUBE_API_KEY constant.
	 *
	 * A constant defined but left empty is ignored, so it does not lock the
	 * settings field either.
	 *
	 * @since 10.3.0
	 *
	 * @return bool
	 */
	function seopress_pro_youtube_api_key_is_constant() {
		return defined( 'SEOPRESS_YOUTUBE_API_KEY' ) && is_string( SEOPRESS_YOUTUBE_API_KEY ) && '' !== trim( SEOPRESS_YOUTUBE_API_KEY );
	}
}

if ( ! function_exists( 'seopress_pro_get_youtube_api_key' ) ) {
	/**
	 * Get the site's own YouTube Data API v3 key.
	 *
	 * Resolved from the SEOPRESS_YOUTUBE_API_KEY constant, then from the
	 * Structured Data Types settings. There is no bundled fallback key: a key
	 * shipped with the plugin is shared by every install, and its daily quota
	 * ran out for all of them. Without a key, metadata comes from the keyless
	 * oEmbed endpoint, which only knows the title and the thumbnail.
	 *
	 * @return string Empty string when no key is configured.
	 */
	function seopress_pro_get_youtube_api_key() {
		if ( seopress_pro_youtube_api_key_is_constant() ) {
			$key = SEOPRESS_YOUTUBE_API_KEY;
		} else {
			$key = seopress_pro_get_service( 'OptionPro' )->getVideoYouTubeApiKey();
		}

		/**
		 * Filter the YouTube Data API v3 key.
		 *
		 * @param string $key API key, empty when none is configured.
		 */
		return trim( (string) apply_filters( 'seopress_video_youtube_api_key', (string) $key ) );
	}
}

if ( ! function_exists( 'seopress_pro_youtube_api_key_fingerprint' ) ) {
	/**
	 * Salted fingerprint of an API key, to tell keys apart without storing them.
	 *
	 * Used in the cache keys, the pause transient and the stored post meta,
	 * all readable in the database.
	 *
	 * @since 10.3.0
	 *
	 * @param string $api_key API key.
	 * @return string Empty string for an empty key.
	 */
	function seopress_pro_youtube_api_key_fingerprint( $api_key ) {
		return '' === (string) $api_key ? '' : wp_hash( 'seopress_pro_youtube_api_key|' . $api_key );
	}
}

if ( ! function_exists( 'seopress_pro_youtube_api_is_paused' ) ) {
	/**
	 * Whether Data API calls are paused for a key.
	 *
	 * YouTube refusing the key (quota exhausted, invalid key, API not enabled
	 * for the project, key restricted to other referrers or IPs) means every
	 * other video would fail the same way, so the calls are skipped for a
	 * while instead of being retried video by video. The pause is tied to the
	 * key: entering another one lifts it straight away.
	 *
	 * @since 10.3.0
	 *
	 * @param string $api_key API key.
	 * @return bool
	 */
	function seopress_pro_youtube_api_is_paused( $api_key ) {
		$paused = get_transient( 'seopress_pro_yt_api_paused' );

		return is_array( $paused ) && isset( $paused['key'] ) && seopress_pro_youtube_api_key_fingerprint( $api_key ) === $paused['key'];
	}
}

if ( ! function_exists( 'seopress_pro_pause_youtube_api' ) ) {
	/**
	 * Pause Data API calls for a key YouTube refused.
	 *
	 * @since 10.3.0
	 *
	 * @param string $api_key API key.
	 * @param string $reason  Error reason reported by YouTube.
	 * @return void
	 */
	function seopress_pro_pause_youtube_api( $api_key, $reason ) {
		/**
		 * Filter how long (in seconds) Data API calls stay paused after YouTube refused the key.
		 *
		 * @since 10.3.0
		 *
		 * @param int    $ttl    Default 6 hours.
		 * @param string $reason Error reason reported by YouTube, e.g. quotaExceeded or API_KEY_INVALID.
		 */
		$ttl = (int) apply_filters( 'seopress_pro_youtube_api_pause_ttl', 6 * HOUR_IN_SECONDS, $reason );

		set_transient(
			'seopress_pro_yt_api_paused',
			array(
				'key'    => seopress_pro_youtube_api_key_fingerprint( $api_key ),
				'reason' => (string) $reason,
			),
			$ttl
		);
	}
}

if ( ! function_exists( 'seopress_pro_request_youtube_data_api' ) ) {
	/**
	 * Query the YouTube Data API v3 for one video.
	 *
	 * @since 10.3.0
	 *
	 * @param string $video_id YouTube video ID.
	 * @param string $api_key  API key.
	 * @return array {
	 *     @type object|null $item   Decoded video item, null when none came back.
	 *     @type int         $code   HTTP status code, 0 on a transport error.
	 *     @type string      $reason Error reason reported by YouTube, empty on success.
	 * }
	 */
	function seopress_pro_request_youtube_data_api( $video_id, $api_key ) {
		$url = add_query_arg(
			array(
				'part' => 'snippet,contentDetails,statistics,status,player',
				'id'   => rawurlencode( $video_id ),
			),
			'https://www.googleapis.com/youtube/v3/videos'
		);

		// The key goes in a header rather than in the URL, which HTTP loggers,
		// proxies and debugging plugins record.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 5,
				'blocking'    => true,
				'headers'     => array( 'X-Goog-Api-Key' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'item'   => null,
				'code'   => 0,
				'reason' => $response->get_error_code(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( 200 === $code ) {
			return array(
				'item'   => ! empty( $body->items[0] ) ? $body->items[0] : null,
				'code'   => $code,
				'reason' => '',
			);
		}

		// The ErrorInfo detail (API_KEY_INVALID, SERVICE_DISABLED,
		// API_KEY_HTTP_REFERRER_BLOCKED...) is the most precise reason; quota
		// errors only carry the legacy one (quotaExceeded).
		$reason = '';
		if ( isset( $body->error->details ) && is_array( $body->error->details ) ) {
			foreach ( $body->error->details as $detail ) {
				if ( ! empty( $detail->reason ) ) {
					$reason = (string) $detail->reason;
					break;
				}
			}
		}
		if ( '' === $reason && isset( $body->error->errors[0]->reason ) ) {
			$reason = (string) $body->error->errors[0]->reason;
		}

		return array(
			'item'   => null,
			'code'   => $code,
			'reason' => $reason,
		);
	}
}

if ( ! function_exists( 'seopress_pro_fetch_youtube_oembed_item' ) ) {
	/**
	 * Build a Data-API-shaped item from YouTube's public oEmbed endpoint.
	 *
	 * oEmbed needs no API key and consumes no Data API quota, so it keeps the
	 * Video sitemap and the auto Video schema working when the site has not set
	 * its own key, or when YouTube refuses it. It only exposes the title and the
	 * thumbnail, so the returned object mimics just the fields both consumers
	 * read; missing fields (description, duration, publishedAt, viewCount) are
	 * left unset and the downstream code already guards them with isset()/empty().
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
		$item->seopress_source      = 'oembed';
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
	 * Queries the Data API v3 (richest metadata) only when the site has its own
	 * key and YouTube has not refused that key recently. Otherwise, or when the
	 * call fails, it falls back to the keyless oEmbed endpoint so the feature
	 * keeps working with at least a title and a thumbnail. Results are cached in
	 * a transient keyed by the video ID and the API key. The default TTL is 12
	 * hours and can be filtered with seopress_pro_youtube_video_data_ttl; the
	 * leaner oEmbed fallback is cached for a shorter window.
	 *
	 * Callers are the save-time paths only (post save, Video sitemap scan,
	 * background refresh), never the page render.
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

		$api_key = seopress_pro_get_youtube_api_key();

		// The key is part of the cache key, so title-and-thumbnail data cached
		// before a key was entered is not served in place of the full metadata.
		$cache_key = 'seopress_pro_yt_data_' . md5( $video_id . '|' . seopress_pro_youtube_api_key_fingerprint( $api_key ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			// A cached non-object is the negative-result sentinel (see below).
			return is_object( $cached ) ? $cached : null;
		}

		$item       = null;
		$is_partial = false;

		if ( '' !== $api_key && ! seopress_pro_youtube_api_is_paused( $api_key ) ) {
			$result = seopress_pro_request_youtube_data_api( $video_id, $api_key );
			$item   = $result['item'];

			// 400, 403 and 429 are about the key, not the video: an unknown or
			// private video comes back as a 200 with no item.
			if ( in_array( $result['code'], array( 400, 403, 429 ), true ) ) {
				seopress_pro_pause_youtube_api( $api_key, $result['reason'] );
			}
		}

		// No key, key refused, or no item: fall back to oEmbed.
		if ( null === $item ) {
			$item       = seopress_pro_fetch_youtube_oembed_item( $video_id );
			$is_partial = true;
		}

		// Both the Data API and oEmbed failed: the video is invalid / deleted /
		// private (or there is a temporary outage). Cache a negative sentinel so
		// the lookup is not retried for every post embedding the same video.
		if ( null === $item ) {
			/**
			 * Filter the cache TTL (in seconds) used to remember a *failed* lookup so an
			 * invalid / deleted / private video (or a temporary API outage) is not
			 * re-queried straight away. Stored as a non-object sentinel.
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
		// metadata is fetched again once the key works.
		if ( $is_partial ) {
			$ttl = (int) apply_filters( 'seopress_pro_youtube_video_data_partial_ttl', HOUR_IN_SECONDS, $video_id );
		}

		set_transient( $cache_key, $item, $ttl );

		return $item;
	}
}

if ( ! function_exists( 'seopress_pro_normalize_youtube_video_item' ) ) {
	/**
	 * Turn a YouTube video item into the fields read by the Video schema
	 * auto-fill and the youtube_video_* dynamic variables.
	 *
	 * @since 10.3.0
	 *
	 * @param object $item     Data API item, or the oEmbed item shaped like one.
	 * @param string $video_id Video ID the item was fetched for.
	 * @param int    $post_id  Post the video was found in.
	 * @return array Normalized fields.
	 */
	function seopress_pro_normalize_youtube_video_item( $item, $video_id, $post_id ) {
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

		$id          = isset( $item->id ) ? (string) $item->id : $video_id;
		$content_url = 'https://www.youtube.com/watch?v=' . $id;
		$embed_url   = seopress_pro_get_youtube_embed_url( $content_url );

		$data = array(
			'id'          => $id,
			'name'        => $name,
			'description' => $description,
			'img'         => $thumbnail,
			'duration'    => $duration_hms,
			'url'         => $content_url,
			'content_url' => $content_url,
			'embed_url'   => $embed_url,
			'date_posted' => $published,
			'upload_date' => $published,
			'view_count'  => $view_count,
		);

		/**
		 * Filter the normalized YouTube data array used by the Video schema
		 * auto-fill and the youtube_video_* dynamic variables.
		 *
		 * Runs when the metadata is fetched (post save or background refresh),
		 * and the result is what gets stored.
		 *
		 * @param array  $data    Normalized data.
		 * @param object $item    Raw decoded API item.
		 * @param int    $post_id Post ID being processed.
		 */
		return (array) apply_filters( 'seopress_pro_youtube_video_data', $data, $item, $post_id );
	}
}

if ( ! function_exists( 'seopress_pro_youtube_video_autofill_is_enabled' ) ) {
	/**
	 * Whether YouTube metadata is resolved for a post.
	 *
	 * @since 10.3.0
	 *
	 * @param int|null $post_id Post ID.
	 * @return bool
	 */
	function seopress_pro_youtube_video_autofill_is_enabled( $post_id = null ) {
		if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getRichSnippetEnable() ) {
			return false;
		}

		/**
		 * Whether YouTube metadata is resolved for the auto-filled Video schema and
		 * the youtube_video_* dynamic variables.
		 *
		 * Return false to switch it off entirely: nothing is fetched when posts are
		 * saved and nothing is printed. The XML Video sitemap is not affected.
		 *
		 * @param bool     $enabled Default true.
		 * @param int|null $post_id Post ID being resolved.
		 */
		return (bool) apply_filters( 'seopress_pro_youtube_video_autofill', true, $post_id );
	}
}

if ( ! function_exists( 'seopress_pro_schemas_use_youtube_video_data' ) ) {
	/**
	 * Whether one of these automatic schemas prints YouTube metadata.
	 *
	 * That is a Video schema auto-filled from YouTube (global toggle or its own
	 * checkbox), or any schema with a field mapped to a youtube_video_* variable.
	 *
	 * @since 10.3.0
	 *
	 * @param int[] $schema_ids IDs of seopress_schemas posts.
	 * @return bool
	 */
	function seopress_pro_schemas_use_youtube_video_data( array $schema_ids ) {
		$autofill_everywhere = '1' === seopress_pro_get_service( 'OptionPro' )->getVideoSchemaYouTubeEnable();

		foreach ( $schema_ids as $schema_id ) {
			if ( 'videos' === get_post_meta( $schema_id, '_seopress_pro_rich_snippets_type', true )
				&& ( $autofill_everywhere || '1' === get_post_meta( $schema_id, '_seopress_pro_rich_snippets_videos_youtube_autofill', true ) ) ) {
				return true;
			}

			foreach ( (array) get_post_meta( $schema_id ) as $values ) {
				foreach ( (array) $values as $value ) {
					if ( is_string( $value ) && false !== strpos( $value, 'youtube_video_' ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( 'seopress_pro_youtube_video_data_is_used' ) ) {
	/**
	 * Whether any automatic schema of the site prints YouTube metadata.
	 *
	 * @since 10.3.0
	 *
	 * @return bool
	 */
	function seopress_pro_youtube_video_data_is_used() {
		$schema_ids = get_posts(
			array(
				'post_type'      => 'seopress_schemas',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $schema_ids ) ) {
			return false;
		}

		update_postmeta_cache( $schema_ids );

		return seopress_pro_schemas_use_youtube_video_data( $schema_ids );
	}
}

if ( ! function_exists( 'seopress_pro_refresh_post_youtube_video_data' ) ) {
	/**
	 * Fetch the metadata of the first YouTube video of a post and store it.
	 *
	 * Stored in the _seopress_pro_youtube_video_data post meta, which is all
	 * the front end reads:
	 *
	 *  - id:      the video the metadata belongs to,
	 *  - source:  "api" (full metadata), "oembed" (title and thumbnail only),
	 *             or empty when nothing could be fetched,
	 *  - key:     fingerprint of the API key in use at the time, so data
	 *             fetched without a key is refreshed once one is entered,
	 *  - checked: when YouTube was last asked,
	 *  - data:    the normalized fields.
	 *
	 * @since 10.3.0
	 *
	 * @param int  $post_id      Post ID.
	 * @param bool $record_empty Record the check even when no video is found.
	 *                           The refresh queue sets it: the post was queued
	 *                           because the page showed a video, and without a
	 *                           record the page would queue it again at once.
	 * @return array Stored fields, empty array when there is nothing to store.
	 */
	function seopress_pro_refresh_post_youtube_video_data( $post_id, $record_empty = false ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$ids = seopress_pro_detect_post_youtube_video_ids( $post );
		if ( empty( $ids ) ) {
			if ( $record_empty ) {
				update_post_meta(
					$post->ID,
					'_seopress_pro_youtube_video_data',
					array(
						'id'      => '',
						'source'  => '',
						'data'    => array(),
						'key'     => '',
						'checked' => time(),
					)
				);
			} elseif ( '' !== get_post_meta( $post->ID, '_seopress_pro_youtube_video_data', true ) ) {
				delete_post_meta( $post->ID, '_seopress_pro_youtube_video_data' );
			}
			return array();
		}

		$item   = seopress_pro_fetch_youtube_video_data( $ids[0] );
		$stored = get_post_meta( $post->ID, '_seopress_pro_youtube_video_data', true );

		if ( null === $item ) {
			// Keep what an earlier fetch stored for this same video rather than
			// dropping it over a temporary outage.
			if ( ! is_array( $stored ) || ! isset( $stored['id'] ) || $ids[0] !== $stored['id'] ) {
				$stored = array(
					'id'     => $ids[0],
					'source' => '',
					'data'   => array(),
				);
			}
		} else {
			$stored = array(
				'id'     => $ids[0],
				'source' => isset( $item->seopress_source ) ? (string) $item->seopress_source : 'api',
				'data'   => seopress_pro_normalize_youtube_video_item( $item, $ids[0], $post->ID ),
			);
		}

		$stored['key']     = seopress_pro_youtube_api_key_fingerprint( seopress_pro_get_youtube_api_key() );
		$stored['checked'] = time();

		update_post_meta( $post->ID, '_seopress_pro_youtube_video_data', wp_slash( $stored ) );

		return is_array( $stored['data'] ) ? $stored['data'] : array();
	}
}

if ( ! function_exists( 'seopress_pro_youtube_video_data_save_post' ) ) {
	/**
	 * Refresh the stored YouTube metadata when a post is saved.
	 *
	 * @since 10.3.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	function seopress_pro_youtube_video_data_save_post( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Schemas are only printed on viewable content.
		if ( ! $post instanceof WP_Post || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		if ( ! seopress_pro_youtube_video_autofill_is_enabled( $post_id ) || ! seopress_pro_youtube_video_data_is_used() ) {
			return;
		}

		seopress_pro_refresh_post_youtube_video_data( $post_id );
	}
}

if ( ! function_exists( 'seopress_pro_queue_youtube_video_data_refresh' ) ) {
	/**
	 * Queue a background refresh of a post's stored YouTube metadata.
	 *
	 * Called from the page render, which must not query YouTube itself. All
	 * posts share one queue option and one WP-Cron event, so a crawler going
	 * through thousands of pages saved before this existed cannot flood the
	 * cron array or the options table.
	 *
	 * @since 10.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	function seopress_pro_queue_youtube_video_data_refresh( $post_id ) {
		$post_id = (int) $post_id;
		$queue   = get_option( 'seopress_pro_youtube_video_data_queue', array() );
		$queue   = is_array( $queue ) ? $queue : array();

		if ( ! isset( $queue[ $post_id ] ) ) {
			/**
			 * Filter how many posts can wait in the YouTube metadata refresh queue.
			 * A post past the limit is queued on a later visit.
			 *
			 * @since 10.3.0
			 *
			 * @param int $size Default 500.
			 */
			if ( count( $queue ) >= (int) apply_filters( 'seopress_pro_youtube_video_data_queue_size', 500 ) ) {
				return;
			}

			$queue[ $post_id ] = time();
			update_option( 'seopress_pro_youtube_video_data_queue', $queue, false );
		}

		if ( ! wp_next_scheduled( 'seopress_pro_process_youtube_video_data_queue' ) ) {
			wp_schedule_single_event( time(), 'seopress_pro_process_youtube_video_data_queue' );
		}
	}
}

if ( ! function_exists( 'seopress_pro_purge_post_page_cache' ) ) {
	/**
	 * Purge the cached page of a post whose YouTube metadata changed in the background.
	 *
	 * The page that queued the refresh was rendered without the metadata, and a
	 * page cache keeps serving that version: nothing else tells it the page
	 * changed, since only post meta was updated. Saving a post needs none of
	 * this, page caches already purge a saved post.
	 *
	 * @since 10.4.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	function seopress_pro_purge_post_page_cache( $post_id ) {
		$post_id = (int) $post_id;

		// WP Super Cache purges the page on this hook.
		clean_post_cache( $post_id );

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_post', $post_id );

		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}

		// SiteGround Speed Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( get_permalink( $post_id ) );
		}

		// WP-Optimize.
		if ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'delete_single_post_cache' ) ) {
			WPO_Page_Cache::delete_single_post_cache( $post_id );
		}

		/**
		 * Fires when the YouTube metadata of a post changed in a background
		 * refresh, after the page caches above were purged. Hook a cache this
		 * function does not know about (host cache, CDN) here.
		 *
		 * @since 10.4.0
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'seopress_pro_youtube_video_data_refreshed', $post_id );
	}
}

if ( ! function_exists( 'seopress_pro_process_youtube_video_data_queue' ) ) {
	/**
	 * WP-Cron callback refreshing a batch of queued posts.
	 *
	 * Reschedules itself a minute later while posts are left, which spreads
	 * the requests to YouTube instead of sending them all in one cron run.
	 * A post whose stored metadata changed has its cached page purged, so the
	 * page cached without the metadata is not served any longer.
	 *
	 * @since 10.3.0
	 *
	 * @return void
	 */
	function seopress_pro_process_youtube_video_data_queue() {
		$queue = get_option( 'seopress_pro_youtube_video_data_queue', array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		/**
		 * Filter how many queued posts one run refreshes. Each one can take up to
		 * two requests to YouTube.
		 *
		 * @since 10.3.0
		 *
		 * @param int $batch Default 20.
		 */
		$batch = max( 1, (int) apply_filters( 'seopress_pro_youtube_video_data_queue_batch', 20 ) );

		asort( $queue );
		$post_ids = array_slice( array_keys( $queue ), 0, $batch );

		foreach ( $post_ids as $post_id ) {
			if ( ! seopress_pro_youtube_video_autofill_is_enabled( $post_id ) ) {
				continue;
			}

			$before = get_post_meta( $post_id, '_seopress_pro_youtube_video_data', true );
			$before = is_array( $before ) ? $before : array();

			$data = seopress_pro_refresh_post_youtube_video_data( $post_id, true );

			// The page prints the data stored for its first video: purge it when either changed.
			$before_id   = isset( $before['id'] ) ? $before['id'] : '';
			$before_data = isset( $before['data'] ) && is_array( $before['data'] ) ? $before['data'] : array();
			$after       = get_post_meta( $post_id, '_seopress_pro_youtube_video_data', true );
			$after_id    = is_array( $after ) && isset( $after['id'] ) ? $after['id'] : '';

			if ( $before_id !== $after_id || $before_data !== $data ) {
				seopress_pro_purge_post_page_cache( $post_id );
			}
		}

		// Read the queue again: pages may have added posts while this batch ran.
		wp_cache_delete( 'seopress_pro_youtube_video_data_queue', 'options' );
		$queue = get_option( 'seopress_pro_youtube_video_data_queue', array() );
		$queue = is_array( $queue ) ? array_diff_key( $queue, array_flip( $post_ids ) ) : array();

		if ( empty( $queue ) ) {
			delete_option( 'seopress_pro_youtube_video_data_queue' );
			return;
		}

		update_option( 'seopress_pro_youtube_video_data_queue', $queue, false );

		if ( ! wp_next_scheduled( 'seopress_pro_process_youtube_video_data_queue' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'seopress_pro_process_youtube_video_data_queue' );
		}
	}
	add_action( 'seopress_pro_process_youtube_video_data_queue', 'seopress_pro_process_youtube_video_data_queue' );
}

if ( ! function_exists( 'seopress_pro_get_post_youtube_video_data' ) ) {
	/**
	 * Get normalized YouTube metadata for the first YouTube video detected
	 * in a post's content.
	 *
	 * Read-only: returns what was stored when the post was saved and never
	 * queries YouTube. When nothing usable is stored yet (post saved before the
	 * metadata was stored, first video changed, data incomplete while a key is
	 * now available), the post is queued for a background refresh and the
	 * stored state is used meanwhile.
	 *
	 * @param int|null $post_id Post ID (defaults to current post).
	 * @return array Normalized fields. Empty array when no YouTube video is found
	 *               or no metadata is stored for it.
	 */
	function seopress_pro_get_post_youtube_video_data( $post_id = null ) {
		static $memo = array();

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

		$memo[ $post_id ] = array();

		if ( ! seopress_pro_youtube_video_autofill_is_enabled( $post_id ) ) {
			return $memo[ $post_id ];
		}

		$ids = seopress_pro_detect_post_youtube_video_ids( get_post( $post_id ) );
		if ( empty( $ids ) ) {
			return $memo[ $post_id ];
		}

		$stored  = get_post_meta( $post_id, '_seopress_pro_youtube_video_data', true );
		$stored  = is_array( $stored ) ? $stored : array();
		$checked = isset( $stored['checked'] ) ? (int) $stored['checked'] : 0;

		// Never fetched, or fetched for a video that is no longer the first one.
		// Not queued again within 15 minutes of the last check, which bounds a
		// refresh that cannot see the video this page sees.
		if ( ! isset( $stored['id'] ) || $ids[0] !== $stored['id'] ) {
			if ( time() - $checked > 15 * MINUTE_IN_SECONDS ) {
				seopress_pro_queue_youtube_video_data_refresh( $post_id );
			}
			return $memo[ $post_id ];
		}

		$data = isset( $stored['data'] ) && is_array( $stored['data'] ) ? $stored['data'] : array();

		if ( empty( $data ) || ! isset( $stored['source'] ) || 'api' !== $stored['source'] ) {
			$api_key     = seopress_pro_get_youtube_api_key();
			$key_changed = '' !== $api_key && ( ! isset( $stored['key'] ) || seopress_pro_youtube_api_key_fingerprint( $api_key ) !== $stored['key'] );

			/**
			 * Filter how long (in seconds) incomplete stored YouTube metadata waits
			 * before a background refresh is attempted again.
			 *
			 * @since 10.3.0
			 *
			 * @param int $interval Default DAY_IN_SECONDS.
			 * @param int $post_id  Post ID.
			 */
			$retry_interval = (int) apply_filters( 'seopress_pro_youtube_video_data_retry_interval', DAY_IN_SECONDS, $post_id );

			if ( $key_changed || time() - $checked > $retry_interval ) {
				seopress_pro_queue_youtube_video_data_refresh( $post_id );
			}
		}

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
