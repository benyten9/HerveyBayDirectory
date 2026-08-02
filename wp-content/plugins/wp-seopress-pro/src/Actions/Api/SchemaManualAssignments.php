<?php

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Actions\Api\Traits\ViewPreferencesTrait;

/**
 * REST API endpoint listing the content that has a manual schema assigned
 * through the SEO metabox (post meta `_seopress_pro_schemas_manual`).
 *
 * Powers the "Manual" tab of the Schemas DataViews screen. Unlike automatic
 * schemas (the `seopress_schemas` CPT, whose target content is computed from
 * rules), a manual schema is stored statically on a single post, so the list
 * of assignments can be queried directly.
 *
 * @since 10.1.0
 */
class SchemaManualAssignments implements ExecuteHooks {

	use ViewPreferencesTrait;

	/**
	 * Post meta storing the manual schemas attached to a post.
	 *
	 * @var string
	 */
	const META_KEY = '_seopress_pro_schemas_manual';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/schemas/manual-assignments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetAll' ),
				// The list exposes titles, statuses and edit links of every post
				// type and status, so it is gated on the same capability as the
				// Schemas screen (and the sibling automatic-schemas endpoints)
				// rather than the low-privilege `edit_posts` a contributor holds.
				'permission_callback' => function () {
					return current_user_can( 'edit_schemas' );
				},
				'args'                => array(
					'per_page' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0 && (int) $param <= 100;
						},
						'sanitize_callback' => 'absint',
						'default'           => 20,
					),
					'page'     => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
					'orderby'  => array(
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'title', 'date' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'title',
					),
					'order'    => array(
						'validate_callback' => function ( $param ) {
							return in_array( strtoupper( (string) $param ), array( 'ASC', 'DESC' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'ASC',
					),
					'search'   => array(
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/schemas/manual-assignments/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'processDelete' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Per-user DataViews view preferences (perPage, sort, fields, filters,
		// density, layout…), stored in a dedicated user meta so the Manual tab
		// keeps its display state across reloads — like the automatic list.
		register_rest_route(
			'seopress/v1',
			'/schemas/manual-assignments/view-preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGetViewPreferences' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_schemas' );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processSaveViewPreferences' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_schemas' );
					},
				),
			)
		);
	}

	/**
	 * User-meta key for the persisted DataViews view state.
	 *
	 * @return string
	 */
	protected function getViewPrefsMetaKey() {
		return 'seopress_schemas_manual_view';
	}

	/**
	 * Whitelist of view keys persisted for the manual assignments listing.
	 *
	 * @return string[]
	 */
	protected function getViewPrefsAllowedKeys() {
		return array(
			'perPage',
			'fields',
			'filters',
			'sort',
			'density',
			'layout',
			'titleField',
			'mediaField',
			'descriptionField',
			'showMedia',
			'groupByField',
		);
	}

	/**
	 * GET /seopress/v1/schemas/manual-assignments — List posts holding a
	 * manual schema.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetAll( \WP_REST_Request $request ) {
		$per_page = $request->get_param( 'per_page' ) ?: 20;
		$page     = $request->get_param( 'page' ) ?: 1;
		$orderby  = $request->get_param( 'orderby' ) ?: 'title';
		$order    = strtoupper( $request->get_param( 'order' ) ?: 'ASC' );
		$search   = $request->get_param( 'search' );

		$args = array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
			// A manual schema is stored as an array of schemas. The `!=` compare
			// requires the key to exist (rows without it never join) and, at the
			// same time, excludes posts whose array was emptied but left behind
			// (serialized empty array). No LIKE on the serialized blob.
			'meta_query'     => array(
				array(
					'key'     => self::META_KEY,
					'value'   => 'a:0:{}',
					'compare' => '!=',
				),
			),
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->build_item( $post );
		}

		return new \WP_REST_Response(
			array(
				'data'       => $items,
				'total'      => (int) $query->found_posts,
				'totalPages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * DELETE /seopress/v1/schemas/manual-assignments/{id} — Remove every
	 * manual schema from a post.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processDelete( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		delete_post_meta( $id, self::META_KEY );

		return new \WP_REST_Response( array( 'code' => 'success' ) );
	}

	/**
	 * Build the list item payload for a single post.
	 *
	 * Only the paginated rows are hydrated, so the serialized meta is read for
	 * at most `per_page` posts per request.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array
	 */
	private function build_item( \WP_Post $post ) {
		$schemas = get_post_meta( $post->ID, self::META_KEY, true );
		$types   = array();

		if ( is_array( $schemas ) ) {
			foreach ( $schemas as $schema ) {
				if ( is_array( $schema ) && ! empty( $schema['_seopress_pro_rich_snippets_type'] ) ) {
					$types[] = $schema['_seopress_pro_rich_snippets_type'];
				}
			}
		}

		$post_type_obj  = get_post_type_object( $post->post_type );
		$post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;

		return array(
			'id'            => (int) $post->ID,
			'title'         => $post->post_title,
			'postType'      => $post->post_type,
			'postTypeLabel' => $post_type_name,
			'status'        => $post->post_status,
			'types'         => $types,
			'count'         => count( $types ),
			'editUrl'       => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
		);
	}
}
