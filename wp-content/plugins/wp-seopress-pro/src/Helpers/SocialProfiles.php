<?php

namespace SEOPressPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized helper for author social profile meta keys.
 *
 * @since 9.8
 */
class SocialProfiles {

	/**
	 * SEOPress user meta keys for social profiles.
	 *
	 * @var array<string, string> Meta key => Label.
	 */
	const META_KEYS = array(
		'_seopress_user_social_facebook'  => 'Facebook',
		'_seopress_user_social_x'         => 'X (Twitter)',
		'_seopress_user_social_linkedin'  => 'LinkedIn',
		'_seopress_user_social_instagram' => 'Instagram',
		'_seopress_user_social_youtube'   => 'YouTube',
		'_seopress_user_social_pinterest' => 'Pinterest',
	);

	/**
	 * Yoast / Rank Math contact method keys (stored directly in wp_usermeta).
	 *
	 * @var array<string, string|null> Meta key => handle domain (null = already a URL).
	 */
	const YOAST_META_KEYS = array(
		'facebook'   => null,
		'twitter'    => 'x.com',
		'instagram'  => null,
		'linkedin'   => null,
		'youtube'    => null,
		'pinterest'  => null,
		'myspace'    => null,
		'soundcloud' => null,
		'tumblr'     => null,
		'wikipedia'  => null,
	);

	/**
	 * Rank Math OpenGraph-specific user meta keys.
	 *
	 * @var array<string, string|null>
	 */
	const RANK_MATH_META_KEYS = array(
		'rank_math_facebook_author' => null,
		'rank_math_twitter_author'  => 'x.com',
	);

	/**
	 * All in One SEO user meta keys.
	 *
	 * @var array<string, string|null>
	 */
	const AIOSEO_META_KEYS = array(
		'aioseo_facebook_page_url' => null,
		'aioseo_twitter_url'       => 'x.com',
		'aioseo_instagram_url'     => null,
		'aioseo_linkedin_url'      => null,
		'aioseo_youtube_url'       => null,
		'aioseo_pinterest_url'     => null,
		'aioseo_tumblr_url'        => null,
		'aioseo_wikipedia_url'     => null,
		'aioseo_myspace_url'       => null,
		'aioseo_sound_cloud_url'   => null,
		'aioseo_tiktok_url'        => null,
		'aioseo_bluesky_url'       => null,
		'aioseo_threads_url'       => null,
	);

	/**
	 * Get all sameAs URLs for a user from all sources.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array<string> Deduplicated array of URLs.
	 */
	public static function getSameAs( $user_id ) {
		$same_as = array();

		// User website URL.
		$user_url = get_the_author_meta( 'user_url', $user_id );
		if ( ! empty( $user_url ) ) {
			$same_as[] = esc_url( $user_url );
		}

		// SEOPress social profile fields.
		foreach ( self::META_KEYS as $key => $label ) {
			$url = get_user_meta( $user_id, $key, true );
			if ( ! empty( $url ) ) {
				$same_as[] = esc_url( $url );
			}
		}

		// Competitor fallbacks.
		$competitor_keys = array_merge(
			self::YOAST_META_KEYS,
			self::RANK_MATH_META_KEYS,
			self::AIOSEO_META_KEYS
		);

		foreach ( $competitor_keys as $key => $handle_domain ) {
			$url = get_user_meta( $user_id, $key, true );
			if ( ! empty( $url ) ) {
				if ( null !== $handle_domain && 0 !== strpos( $url, 'http' ) ) {
					$url = 'https://' . $handle_domain . '/' . ltrim( $url, '@' );
				}
				if ( ! in_array( esc_url( $url ), $same_as, true ) ) {
					$same_as[] = esc_url( $url );
				}
			}
		}

		return array_unique( array_filter( $same_as ) );
	}
}
