<?php

namespace SEOPressPro\Services\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Match `seopress_schemas` CPT posts against a target post.
 *
 * Evaluates the per-schema rules stored in `_seopress_pro_rich_snippets_rules`
 * (post_type / taxonomy / postId filters combined with equal / not_equal
 * conditions) and returns the list of schema IDs that should apply to the
 * given post.
 *
 * Single source of truth for both the classic PHP metabox template
 * (`inc/admin/metaboxes/admin-metaboxes-schemas.php`) and the upcoming
 * per-post REST endpoint consumed by the React universal metabox.
 *
 * @since 9.8
 */
class AutomaticSchemasMatcher {

	/**
	 * Return the list of `seopress_schemas` IDs matching a post.
	 *
	 * The returned array preserves the original classic-metabox behavior: if a
	 * schema defines several rule groups (OR branches) that all match, its ID
	 * is repeated in the output — callers that want a unique list should wrap
	 * the result in `array_unique()`.
	 *
	 * @param int $post_id The post ID to match against.
	 *
	 * @return int[] Schema post IDs whose rules match the given post.
	 */
	public function forPost( int $post_id ) {
		if ( $post_id <= 0 ) {
			return array();
		}

		$target_post = get_post( $post_id );
		if ( ! $target_post instanceof \WP_Post ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'seopress_schemas',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return array();
		}

		$target_cpt   = get_post_type( $target_post );
		$target_terms = $this->getTargetTermIds( $target_post );

		$matching_ids = array();

		foreach ( $query->posts as $schema_post ) {
			$schema_id = (int) $schema_post->ID;
			$rules     = get_post_meta( $schema_id, '_seopress_pro_rich_snippets_rules', true );

			if ( ! is_array( $rules ) ) {
				$rules = seopress_get_default_schemas_rules( $rules );
			}

			foreach ( $rules as $rule_group ) {
				if ( ! is_array( $rule_group ) || empty( $rule_group ) ) {
					continue;
				}

				$flag  = 0;
				$total = count( $rule_group );

				foreach ( $rule_group as $rule ) {
					if ( ! is_array( $rule ) || empty( $rule['filter'] ) || empty( $rule['cond'] ) ) {
						continue;
					}

					if ( $this->ruleMatches( $rule, $target_cpt, $target_terms, $post_id ) ) {
						++$flag;
					}

					if ( $flag === $total ) {
						$matching_ids[] = $schema_id;
					}
				}
			}
		}

		return $matching_ids;
	}

	/**
	 * Collect the term IDs of the target post for taxonomies tracked by
	 * SEOPress, returned as an associative array keyed by term ID for O(1)
	 * lookups (matches the original classic implementation).
	 *
	 * @param \WP_Post $target_post The post being edited.
	 *
	 * @return array<int,int>
	 */
	private function getTargetTermIds( \WP_Post $target_post ) {
		$service_wp_data = seopress_get_service( 'WordPressData' );
		if ( ! $service_wp_data || ! method_exists( $service_wp_data, 'getTaxonomies' ) ) {
			return array();
		}

		$taxonomies = array_keys( $service_wp_data->getTaxonomies() );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $target_post->ID, $taxonomies );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_flip( wp_list_pluck( $terms, 'term_id' ) );
	}

	/**
	 * Evaluate a single rule against the target post context.
	 *
	 * @param array          $rule         The rule definition.
	 * @param string         $target_cpt   The target post type.
	 * @param array<int,int> $target_terms Target term IDs keyed by term ID.
	 * @param int            $post_id      The target post ID.
	 *
	 * @return bool
	 */
	private function ruleMatches( array $rule, $target_cpt, array $target_terms, $post_id ) {
		$filter = $rule['filter'];
		$cond   = $rule['cond'];

		if ( 'post_type' === $filter && isset( $rule['cpt'] ) && post_type_exists( $rule['cpt'] ) ) {
			if ( 'equal' === $cond && $rule['cpt'] === $target_cpt ) {
				return true;
			}
			if ( 'not_equal' === $cond && $rule['cpt'] !== $target_cpt ) {
				return true;
			}
			return false;
		}

		if ( 'taxonomy' === $filter && isset( $rule['taxo'] ) && term_exists( (int) $rule['taxo'] ) ) {
			$has_term = isset( $target_terms[ $rule['taxo'] ] );
			if ( 'equal' === $cond && $has_term ) {
				return true;
			}
			if ( 'not_equal' === $cond && ! $has_term ) {
				return true;
			}
			return false;
		}

		if ( 'postId' === $filter && isset( $rule['postId'] ) ) {
			$target = (int) $rule['postId'];
			if ( 'equal' === $cond && $target === (int) $post_id ) {
				return true;
			}
			if ( 'not_equal' === $cond && $target !== (int) $post_id ) {
				return true;
			}
			return false;
		}

		return false;
	}
}
