<?php
/**
 * Posts for the Latest Posts email block.
 *
 * One query used by both the email renderer (at send time) and the builder
 * preview, so what the editor shows is what contacts receive. Results are
 * cached per settings for a few minutes: a campaign renders the block once
 * per contact, and every contact gets the same posts.
 *
 * @package DoubleScale\Pro\Modules\Emails\Services
 */

namespace DoubleScale\Pro\Modules\Emails\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Latest posts query and item shaping.
 */
class LatestPosts {

	public const MAX_POSTS = 12;

	private const CACHE_TTL = 300;

	/** @var array<string, array<int, array<string, mixed>>> */
	private static $request_cache = array();

	/**
	 * Post types people can pick: public, with a UI, and not attachments.
	 *
	 * @return array<int, array{name:string,label:string,taxonomies:array<int,array{name:string,label:string}>}>
	 */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$taxonomies = array();
			foreach ( get_object_taxonomies( $type->name, 'objects' ) as $tax ) {
				if ( empty( $tax->public ) || empty( $tax->show_ui ) || 'post_format' === $tax->name ) {
					continue;
				}
				$taxonomies[] = array(
					'name'  => $tax->name,
					'label' => (string) $tax->labels->name,
				);
			}
			$out[] = array(
				'name'       => $type->name,
				'label'      => (string) $type->labels->name,
				'taxonomies' => $taxonomies,
			);
		}
		return $out;
	}

	/**
	 * Terms of one taxonomy for the picker (at most 200, by name).
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, array{id:int,name:string,count:int}>
	 */
	public static function terms( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 200,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static function ( $t ) {
				return array(
					'id'    => (int) $t->term_id,
					'name'  => (string) $t->name,
					'count' => (int) $t->count,
				);
			},
			$terms
		);
	}

	/**
	 * Normalised query settings from block props.
	 *
	 * @param array<string, mixed> $props Block props.
	 * @return array<string, mixed>
	 */
	public static function query_args( array $props ): array {
		$type = isset( $props['postType'] ) ? sanitize_key( (string) $props['postType'] ) : 'post';
		if ( ! post_type_exists( $type ) || ! is_post_type_viewable( $type ) ) {
			$type = 'post';
		}
		$taxonomy = isset( $props['taxonomy'] ) ? sanitize_key( (string) $props['taxonomy'] ) : '';
		if ( '' !== $taxonomy && ! is_object_in_taxonomy( $type, $taxonomy ) ) {
			$taxonomy = '';
		}
		$terms = array_values( array_filter( array_map( 'absint', (array) ( $props['terms'] ?? array() ) ) ) );
		$order = isset( $props['orderBy'] ) && in_array( $props['orderBy'], array( 'date', 'modified', 'title', 'comment_count', 'rand' ), true )
			? (string) $props['orderBy'] : 'date';

		return array(
			'type'     => $type,
			'taxonomy' => $taxonomy,
			'terms'    => '' === $taxonomy ? array() : $terms,
			'count'    => max( 1, min( self::MAX_POSTS, (int) ( $props['count'] ?? 3 ) ) ),
			'offset'   => max( 0, min( 50, (int) ( $props['offset'] ?? 0 ) ) ),
			'order_by' => $order,
			'days'     => ! empty( $props['onlyRecent'] ) ? max( 1, min( 365, (int) ( $props['recentDays'] ?? 7 ) ) ) : 0,
			'excerpt'  => max( 0, min( 80, (int) ( $props['excerptLength'] ?? 24 ) ) ),
			'image'    => isset( $props['imageSize'] ) && in_array( $props['imageSize'], array( 'medium', 'medium_large', 'large' ), true )
				? (string) $props['imageSize'] : 'medium_large',
		);
	}

	/**
	 * The posts to show, shaped for rendering.
	 *
	 * @param array<string, mixed> $props Block props.
	 * @param bool                 $fresh Skip the 5-minute cache (builder preview).
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( array $props, bool $fresh = false ): array {
		$args = self::query_args( $props );

		/**
		 * Filter the posts the Latest Posts block shows (after the query and cache).
		 *
		 * @param array $items Shaped posts: id, title, url, image, excerpt, date, author, label.
		 * @param array $args  Normalised block settings.
		 */
		return (array) apply_filters( 'doublescale_email_latest_posts_items', self::fetch( $args, $fresh ), $args );
	}

	/**
	 * Query (or cache) the posts for normalised settings.
	 *
	 * @param array<string, mixed> $args  Normalised settings.
	 * @param bool                 $fresh Skip the cache.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch( array $args, bool $fresh ): array {
		$key  = 'doublescale_latest_posts_' . md5( (string) wp_json_encode( $args ) );

		if ( ! $fresh && isset( self::$request_cache[ $key ] ) ) {
			return self::$request_cache[ $key ];
		}
		if ( ! $fresh && 'rand' !== $args['order_by'] ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				self::$request_cache[ $key ] = $cached;
				return $cached;
			}
		}

		$query = array(
			'post_type'              => $args['type'],
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $args['count'],
			'offset'                 => $args['offset'],
			'orderby'                => $args['order_by'],
			'order'                  => 'title' === $args['order_by'] ? 'ASC' : 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'suppress_filters'       => false,
			'update_post_term_cache' => true,
		);
		if ( '' !== $args['taxonomy'] && ! empty( $args['terms'] ) ) {
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded by posts_per_page ≤ 12.
				array(
					'taxonomy' => $args['taxonomy'],
					'field'    => 'term_id',
					'terms'    => $args['terms'],
				),
			);
		}
		if ( $args['days'] > 0 ) {
			$query['date_query'] = array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', time() - $args['days'] * DAY_IN_SECONDS ),
					'column'    => 'post_date_gmt',
					'inclusive' => true,
				),
			);
		}

		/**
		 * Filter the WP_Query arguments of the Latest Posts email block.
		 *
		 * @param array $query Query arguments.
		 * @param array $args  Normalised block settings.
		 */
		$query = apply_filters( 'doublescale_email_latest_posts_query', $query, $args );

		$items = array();
		foreach ( ( new \WP_Query( $query ) )->posts as $post ) {
			$items[] = self::shape( $post, $args );
		}

		self::$request_cache[ $key ] = $items;
		if ( ! $fresh && 'rand' !== $args['order_by'] ) {
			set_transient( $key, $items, self::CACHE_TTL );
		}
		return $items;
	}

	/**
	 * One post as plain data (all strings already safe to print as text).
	 *
	 * @param \WP_Post             $post Post.
	 * @param array<string, mixed> $args Normalised settings.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_Post $post, array $args ): array {
		$image = '';
		$thumb = get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$src = wp_get_attachment_image_src( $thumb, $args['image'] );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$image = (string) $src[0];
			}
		}

		$label = '';
		$tax   = '' !== $args['taxonomy'] ? $args['taxonomy'] : ( is_object_in_taxonomy( $post->post_type, 'category' ) ? 'category' : '' );
		if ( '' !== $tax ) {
			$terms = get_the_terms( $post, $tax );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$label = (string) $terms[0]->name;
			}
		}

		$excerpt = '';
		if ( $args['excerpt'] > 0 ) {
			$raw     = has_excerpt( $post ) ? $post->post_excerpt : strip_shortcodes( $post->post_content );
			$excerpt = wp_trim_words( wp_strip_all_tags( excerpt_remove_blocks( $raw ) ), $args['excerpt'], '…' );
		}

		return array(
			'id'      => (int) $post->ID,
			'title'   => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'url'     => (string) get_permalink( $post ),
			'image'   => $image,
			'excerpt' => html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' ),
			'date'    => (string) get_the_date( '', $post ),
			'author'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'label'   => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
		);
	}
}
