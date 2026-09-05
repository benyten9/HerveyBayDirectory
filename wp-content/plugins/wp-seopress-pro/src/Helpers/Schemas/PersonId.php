<?php

namespace SEOPressPro\Helpers\Schemas;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

/**
 * The `@id` identifying a Person across the site's structured data.
 *
 * The point of this identifier is consolidation: every schema referring to the
 * same author has to publish the same absolute IRI, so a consumer can tell they
 * describe one entity. A relative value defeats that entirely, because JSON-LD
 * resolves it against the document it was found in, which makes every page
 * declare a Person of its own.
 */
abstract class PersonId {

	/**
	 * Build the Person `@id` for a user.
	 *
	 * The author archive URL is used when there is one, which keeps a
	 * multi-author site publishing one identifier per author.
	 *
	 * `get_author_posts_url()` runs through the `author_link` filter, and
	 * disabling author archives is commonly implemented by emptying it. The
	 * empty string used to be concatenated as-is, and since
	 * `trailingslashit( '' )` returns `/`, the identifier came out as the
	 * relative `/#person` on every page. The site root is used instead: still
	 * absolute, and still identical from one page to the next.
	 *
	 * @param int $user_id The user ID.
	 * @return string An absolute IRI ending in `#person`.
	 */
	public static function get( $user_id ) {
		$user_id = (int) $user_id;
		$base    = $user_id > 0 ? get_author_posts_url( $user_id ) : '';

		// esc_url() also returns an empty string for anything it rejects, so it
		// runs before the fallback rather than after it.
		$base = esc_url( $base );

		if ( '' === $base ) {
			$base = esc_url( home_url( '/' ) );
		}

		$person_id = trailingslashit( $base ) . '#person';

		/**
		 * Filter the Person `@id` used across the site's structured data.
		 *
		 * @since 10.1.1
		 *
		 * @param string $person_id The absolute IRI ending in `#person`.
		 * @param int    $user_id   The user it identifies, 0 when unknown.
		 */
		return apply_filters( 'seopress_schemas_person_id', $person_id, $user_id );
	}

	/**
	 * Build the Person `@id` from an already-resolved profile URL.
	 *
	 * Same contract as get(), for the callers that hold a URL rather than a
	 * user ID.
	 *
	 * @param string $url     Profile URL. Falls back to the site root when empty.
	 * @param int    $user_id The user it identifies, when known.
	 * @return string An absolute IRI ending in `#person`.
	 */
	public static function getFromUrl( $url, $user_id = 0 ) { // phpcs:ignore -- camelCase matches the surrounding helper classes.
		$base = esc_url( (string) $url );

		if ( '' === $base ) {
			$base = esc_url( home_url( '/' ) );
		}

		$person_id = trailingslashit( $base ) . '#person';

		/** This filter is documented in src/Helpers/Schemas/PersonId.php */
		return apply_filters( 'seopress_schemas_person_id', $person_id, (int) $user_id );
	}
}
