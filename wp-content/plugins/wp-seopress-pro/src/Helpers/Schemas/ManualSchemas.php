<?php

namespace SEOPressPro\Helpers\Schemas;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

/**
 * Reading the manual schemas attached to a post.
 *
 * A post can carry the `_seopress_pro_schemas_manual` meta without carrying any
 * schema: the metabox stores a row as soon as one is added, and the type of
 * that row can be left on "None". Older versions offered "None" as the default
 * choice, so the meta ended up on nearly every post of a site that never used
 * manual schemas at all.
 *
 * Such a row produces no output on the front end, so anything reading this meta
 * has to tell an actual assignment from an empty one.
 */
abstract class ManualSchemas {

	/**
	 * Post meta storing the manual schemas attached to a post.
	 *
	 * @var string
	 */
	const META_KEY = '_seopress_pro_schemas_manual';

	/**
	 * The schema type meaning "no schema".
	 *
	 * @var string
	 */
	const TYPE_NONE = 'none';

	/**
	 * Extract the schema types that actually produce output.
	 *
	 * Rows with no type, or typed "none", are dropped: they render nothing and
	 * are not an assignment.
	 *
	 * @param mixed $schemas The raw meta value.
	 * @return string[] Schema types, in the order they are stored.
	 */
	public static function getEffectiveTypes( $schemas ) { // phpcs:ignore -- camelCase matches the surrounding service/helper classes.
		$types = array();

		if ( ! is_array( $schemas ) ) {
			return $types;
		}

		foreach ( $schemas as $schema ) {
			if ( ! is_array( $schema ) || empty( $schema['_seopress_pro_rich_snippets_type'] ) ) {
				continue;
			}

			$type = (string) $schema['_seopress_pro_rich_snippets_type'];

			if ( self::TYPE_NONE === $type ) {
				continue;
			}

			$types[] = $type;
		}

		return $types;
	}

	/**
	 * Whether a stored meta value holds at least one schema that renders.
	 *
	 * @param mixed $schemas The raw meta value.
	 * @return bool
	 */
	public static function hasEffectiveSchema( $schemas ) { // phpcs:ignore -- camelCase matches the surrounding service/helper classes.
		return ! empty( self::getEffectiveTypes( $schemas ) );
	}

	/**
	 * IDs of every post holding at least one schema that renders.
	 *
	 * The types live inside a serialized array, which `meta_query` cannot look
	 * into without a LIKE on the blob that would match "none" appearing
	 * anywhere. The meta is read in one query and filtered in PHP instead, and
	 * the result is handed to WP_Query as `post__in` so search, ordering,
	 * pagination and counts stay WP_Query's job.
	 *
	 * Only the first row per post is considered, matching `get_post_meta( …,
	 * true )`. A post can carry several rows under this key, and the single
	 * value is the one the front end renders and the one the list displays, so
	 * looking at the others would list a post on the strength of a schema that
	 * is never used: it would appear with an em dash, having been selected on a
	 * row nothing reads.
	 *
	 * @return int[]
	 */
	public static function getAssignedPostIds() { // phpcs:ignore -- camelCase matches the surrounding service/helper classes.
		global $wpdb;

		// Direct query, uncached: this backs an on-demand admin screen that has
		// to show a save straight away, and the rows are already narrowed to a
		// single meta key.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN (
					SELECT post_id, MIN(meta_id) AS meta_id
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s
					GROUP BY post_id
				) first_row ON first_row.meta_id = pm.meta_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::META_KEY
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$ids = array();

		foreach ( $rows as $row ) {
			if ( self::hasEffectiveSchema( maybe_unserialize( $row->meta_value ) ) ) {
				$ids[] = (int) $row->post_id;
			}
		}

		return $ids;
	}
}
