<?php

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Actions\Api\Traits\ViewPreferencesTrait;

/**
 * REST API endpoint for automatic schemas (seopress_schemas CPT).
 *
 * @since 9.7
 */
class SchemaAutomatic implements ExecuteHooks {

	use ViewPreferencesTrait;

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
			'/schemas',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetAll' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_schemas' );
				},
				'args'                => array(
					'type'     => array(
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
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
							return in_array( $param, array( 'title', 'date', 'type' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'title',
					),
					'order'    => array(
						'validate_callback' => function ( $param ) {
							return in_array( strtoupper( $param ), array( 'ASC', 'DESC' ), true );
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
			'/schemas/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_schema', (int) $request['id'] );
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

		register_rest_route(
			'seopress/v1',
			'/schemas',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processCreate' ),
				'permission_callback' => function () {
					return current_user_can( 'publish_schemas' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/schemas/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'processUpdate' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_schema', (int) $request['id'] );
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

		register_rest_route(
			'seopress/v1',
			'/schemas/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'processDelete' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'delete_schema', (int) $request['id'] );
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

		register_rest_route(
			'seopress/v1',
			'/schemas/bulk',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'processBulkDelete' ),
				'permission_callback' => function () {
					return current_user_can( 'delete_others_schemas' );
				},
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_array( $param ) && ! empty( $param );
						},
						'sanitize_callback' => function ( $param ) {
							return array_map( 'absint', $param );
						},
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/schemas/matching-posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processMatchingPosts' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_schemas' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/schemas/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processExport' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_schemas' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/schemas/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processImport' ),
				'permission_callback' => function () {
					return current_user_can( 'publish_schemas' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/schemas/post-context/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processPostContext' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_schemas' );
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

		register_rest_route(
			'seopress/v1',
			'/schemas/editor-data',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processEditorData' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_schemas' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/schemas/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processDuplicate' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_schema', (int) $request['id'] ) && current_user_can( 'publish_schemas' );
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

		register_rest_route(
			'seopress/v1',
			'/schemas/preferences',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processUpdatePreferences' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_schemas' );
				},
				'args'                => array(
					'showPreview' => array(
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_bool( $param ) || in_array( $param, array( 0, 1, '0', '1', 'true', 'false' ), true );
						},
					),
				),
			)
		);

		// Per-user DataViews view preferences (perPage, sort, fields, filters,
		// density, layout, …). Without this endpoint, every reload reverts to
		// the default view — every other DataViews page already persists it.
		register_rest_route(
			'seopress/v1',
			'/schemas/view-preferences',
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
		return 'seopress_schemas_view';
	}

	/**
	 * Whitelist of view keys persisted for the Schemas listing.
	 *
	 * @return string[]
	 */
	protected function getViewPrefsAllowedKeys() {
		return array(
			'type',
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
	 * Get all meta prefixes keyed by schema type.
	 *
	 * @return array<string, string>
	 */
	private function get_all_meta_prefixes() {
		return array(
			'articles'      => '_seopress_pro_rich_snippets_article',
			'localbusiness' => '_seopress_pro_rich_snippets_lb',
			'faq'           => '_seopress_pro_rich_snippets_faq',
			'howto'         => '_seopress_pro_rich_snippets_how_to',
			'courses'       => '_seopress_pro_rich_snippets_courses',
			'recipes'       => '_seopress_pro_rich_snippets_recipes',
			'jobs'          => '_seopress_pro_rich_snippets_jobs',
			'videos'        => '_seopress_pro_rich_snippets_videos',
			'events'        => '_seopress_pro_rich_snippets_events',
			'products'      => '_seopress_pro_rich_snippets_product',
			'softwareapp'   => '_seopress_pro_rich_snippets_softwareapp',
			'services'      => '_seopress_pro_rich_snippets_service',
			'review'        => '_seopress_pro_rich_snippets_review',
			'custom'        => '_seopress_pro_rich_snippets_custom',
		);
	}

	/**
	 * Get the meta key prefix for a given schema type.
	 *
	 * @param string $type The schema type.
	 *
	 * @return string
	 */
	private function get_meta_keys_for_type( $type ) {
		$prefixes = $this->get_all_meta_prefixes();

		return isset( $prefixes[ $type ] ) ? $prefixes[ $type ] : '';
	}

	/**
	 * Normalize rules from the legacy g0/i0 keyed format to numeric arrays.
	 *
	 * The old PHP form stores rules as:
	 *   { 'g0' => { 'i0' => { 'filter' => ..., 'cpt' => ... }, 'i1' => ... }, 'g1' => ... }
	 *
	 * The React editor uses simple numeric arrays:
	 *   [ [ { 'filter' => ..., 'cpt' => ... }, ... ], ... ]
	 *
	 * Both formats work with foreach() in the frontend output.
	 *
	 * @param mixed $rules The raw rules from the database.
	 *
	 * @return array Normalized numeric arrays.
	 */
	private function normalize_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			// Retrocompat: old string format (just a CPT slug).
			if ( is_string( $rules ) && ! empty( $rules ) && function_exists( 'seopress_get_default_schemas_rules' ) ) {
				return seopress_get_default_schemas_rules( $rules );
			}
			return array();
		}

		$normalized = array();

		foreach ( $rules as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$normalized_group = array();
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['filter'] ) ) {
					continue;
				}
				$normalized_group[] = $rule;
			}

			if ( ! empty( $normalized_group ) ) {
				$normalized[] = $normalized_group;
			}
		}

		return $normalized;
	}

	/**
	 * Term IDs a set of rule groups points at, as strings.
	 *
	 * @param array $rules Rule groups.
	 *
	 * @return string[]
	 */
	private function term_ids_in_rules( $rules ) {
		$ids = array();

		foreach ( $rules as $group ) {
			foreach ( $group as $rule ) {
				if ( ! isset( $rule['filter'], $rule['taxo'] ) || 'taxonomy' !== $rule['filter'] ) {
					continue;
				}

				$id = (int) $rule['taxo'];
				if ( $id > 0 ) {
					$ids[ (string) $id ] = true;
				}
			}
		}

		return array_keys( $ids );
	}

	/**
	 * Attach the term names the listing needs to render its Rules column.
	 *
	 * The column used to resolve them against a map of every term on the site,
	 * inlined into the page. That map is gone: the picker fetches its terms
	 * lazily now, and the listing has no picker. What it needs instead is the
	 * handful of terms its own rules actually point at — one query for the
	 * whole page, rather than a payload that grows with the site.
	 *
	 * A term that no longer exists is simply left out, and the interface falls
	 * back to showing the raw ID. The rule really is dangling at that point,
	 * and saying so beats inventing a name for it.
	 *
	 * @param array[] $schemas Schemas, as built by `build_schema_data()`.
	 *
	 * @return array[] The same schemas, each with a `termLabels` map.
	 */
	private function add_term_labels( array $schemas ) {
		$per_schema = array();
		$wanted     = array();

		foreach ( $schemas as $index => $schema ) {
			$per_schema[ $index ] = $this->term_ids_in_rules( $schema['rules'] );
			$wanted               = array_merge( $wanted, $per_schema[ $index ] );
		}

		$labels = array();
		$wanted = array_values( array_unique( $wanted ) );

		if ( ! empty( $wanted ) ) {
			$names = get_terms(
				array(
					'include'    => array_map( 'intval', $wanted ),
					'hide_empty' => false,
					'fields'     => 'id=>name',
				)
			);

			if ( ! is_wp_error( $names ) ) {
				foreach ( $names as $term_id => $term_name ) {
					$labels[ (string) $term_id ] = html_entity_decode( $term_name, ENT_QUOTES | ENT_SUBSTITUTE );
				}
			}
		}

		foreach ( $schemas as $index => $schema ) {
			$schemas[ $index ]['termLabels'] = array_intersect_key(
				$labels,
				array_flip( $per_schema[ $index ] )
			);
		}

		return $schemas;
	}

	/**
	 * Build schema data from a post.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array
	 */
	private function build_schema_data( $post ) {
		$id    = $post->ID;
		$type  = get_post_meta( $id, '_seopress_pro_rich_snippets_type', true );
		$rules = get_post_meta( $id, '_seopress_pro_rich_snippets_rules', true );

		$data = array(
			'id'      => $id,
			'title'   => $post->post_title,
			'type'    => sanitize_text_field( $type ),
			'rules'   => $this->normalize_rules( $rules ),
			'meta'    => array(),
			'enabled' => 'publish' === $post->post_status,
		);

		// Collect all meta starting with the type prefix.
		$prefix = $this->get_meta_keys_for_type( $type );
		if ( ! empty( $prefix ) ) {
			$all_meta = get_post_meta( $id );
			foreach ( $all_meta as $key => $values ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$data['meta'][ $key ] = maybe_unserialize( $values[0] );
				}
			}
		}

		return $data;
	}

	/**
	 * GET /seopress/v1/schemas — List all automatic schemas.
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
			'post_type'      => 'seopress_schemas',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => array( 'publish', 'draft' ),
		);

		// Handle ordering.
		if ( 'type' === $orderby ) {
			$args['meta_key'] = '_seopress_pro_rich_snippets_type';
			$args['orderby']  = 'meta_value';
		} else {
			$args['orderby'] = $orderby;
		}
		$args['order'] = $order;

		// Handle search.
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		// Handle type filter.
		$type = $request->get_param( 'type' );
		if ( ! empty( $type ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_seopress_pro_rich_snippets_type',
					'value' => $type,
				),
			);
		}

		$query   = new \WP_Query( $args );
		$schemas = array();

		foreach ( $query->posts as $post ) {
			$schemas[] = $this->build_schema_data( $post );
		}

		$schemas = $this->add_term_labels( $schemas );

		return new \WP_REST_Response(
			array(
				'data'       => $schemas,
				'total'      => $query->found_posts,
				'totalPages' => $query->max_num_pages,
			)
		);
	}

	/**
	 * GET /seopress/v1/schemas/{id} — Get a single automatic schema.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processGet( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_schemas' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Schema not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( $this->build_schema_data( $post ) );
	}

	/**
	 * Sanitize a single meta value.
	 *
	 * Uses esc_html() to match the legacy PHP save handler, except for
	 * custom schema content (manual_custom_global) which allows JSON-LD
	 * script tags, and opening_hours which stores arrays.
	 *
	 * @param string $key   The meta key.
	 * @param mixed  $value The meta value.
	 *
	 * @return mixed The sanitized value.
	 */
	private function sanitize_meta_value( $key, $value ) {
		// Opening hours is stored as an array — sanitize each leaf.
		if ( false !== strpos( $key, '_opening_hours' ) && is_array( $value ) ) {
			return map_deep( $value, 'sanitize_text_field' );
		}

		// Custom schema allows <script type="application/ld+json"> blocks.
		if ( false !== strpos( $key, '_manual_custom_global' ) ) {
			return wp_kses(
				$value,
				array(
					'script' => array( 'type' => array() ),
				)
			);
		}

		// Default: esc_html() like the legacy PHP save handler.
		return esc_html( $value );
	}

	/**
	 * Save meta values for a schema.
	 *
	 * - Deletes meta when value is empty or null (treated as "unset").
	 * - Persists the literal string 'none' so the runtime renderer can
	 *   distinguish "user explicitly chose None" from "field never configured".
	 *   The legacy resolver in options-automatic-rich-snippets.php already
	 *   short-circuits on 'none', so other fields keep their fallback behavior.
	 * - Only allows keys prefixed with _seopress_pro_rich_snippets_.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $meta    The meta key/value pairs.
	 *
	 * @return void
	 */
	private function save_schema_meta( $post_id, $meta ) {
		if ( empty( $meta ) || ! is_array( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $value ) {
			// Only allow our own meta keys.
			if ( 0 !== strpos( $key, '_seopress_pro_rich_snippets_' ) ) {
				continue;
			}

			if ( '' === $value || null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}

			$sanitized = $this->sanitize_meta_value( $key, $value );
			update_post_meta( $post_id, $key, $sanitized );
		}

		// The runtime resolver in options-automatic-rich-snippets.php only
		// returns the custom JSON when the mapping pointer
		// (_seopress_pro_rich_snippets_custom) equals 'manual_custom_global'.
		// The React editor skips that intermediate dropdown for the custom
		// schema field, so set the pointer here whenever the user supplies
		// (or clears) the JSON body.
		if ( array_key_exists( '_seopress_pro_rich_snippets_custom_manual_custom_global', $meta ) ) {
			$custom_value = $meta['_seopress_pro_rich_snippets_custom_manual_custom_global'];
			if ( '' !== $custom_value && null !== $custom_value ) {
				update_post_meta( $post_id, '_seopress_pro_rich_snippets_custom', 'manual_custom_global' );
			} else {
				delete_post_meta( $post_id, '_seopress_pro_rich_snippets_custom' );
			}
		}
	}

	/**
	 * Delete all meta for a given schema type prefix.
	 *
	 * Used when switching schema type to clean up orphaned meta.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $prefix  The meta key prefix to remove.
	 *
	 * @return void
	 */
	private function delete_type_meta( $post_id, $prefix ) {
		if ( empty( $prefix ) ) {
			return;
		}

		$all_meta = get_post_meta( $post_id );
		foreach ( array_keys( $all_meta ) as $key ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * POST /seopress/v1/schemas — Create a new automatic schema.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processCreate( \WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['title'] ) || empty( $params['type'] ) ) {
			return new \WP_Error(
				'missing_parameters',
				__( 'Title and type are required.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$schema_type = sanitize_text_field( $params['type'] );

		// Validate schema type against whitelist.
		if ( ! array_key_exists( $schema_type, $this->get_all_meta_prefixes() ) && 'none' !== $schema_type ) {
			return new \WP_Error(
				'invalid_type',
				__( 'Invalid schema type.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => sanitize_text_field( $params['title'] ),
				'post_type'   => 'seopress_schemas',
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_seopress_pro_rich_snippets_type', $schema_type );

		if ( ! empty( $params['rules'] ) ) {
			update_post_meta( $post_id, '_seopress_pro_rich_snippets_rules', map_deep( $params['rules'], 'sanitize_text_field' ) );
		}

		$this->save_schema_meta( $post_id, $params['meta'] ?? array() );

		return new \WP_REST_Response(
			array(
				'code' => 'created',
				'id'   => $post_id,
				'data' => $this->build_schema_data( get_post( $post_id ) ),
			),
			201
		);
	}

	/**
	 * PUT /seopress/v1/schemas/{id} — Update an automatic schema.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processUpdate( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_schemas' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Schema not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		$params = $request->get_json_params();

		$post_update = array();
		if ( ! empty( $params['title'] ) ) {
			$post_update['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( isset( $params['enabled'] ) ) {
			$post_update['post_status'] = $params['enabled'] ? 'publish' : 'draft';
		}
		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $id;
			wp_update_post( $post_update );
		}

		if ( isset( $params['type'] ) ) {
			$new_type = sanitize_text_field( $params['type'] );
			$old_type = get_post_meta( $id, '_seopress_pro_rich_snippets_type', true );

			// Validate schema type against whitelist.
			$prefixes = $this->get_all_meta_prefixes();
			if ( ! array_key_exists( $new_type, $prefixes ) && 'none' !== $new_type ) {
				return new \WP_Error(
					'invalid_type',
					__( 'Invalid schema type.', 'wp-seopress-pro' ),
					array( 'status' => 400 )
				);
			}

			// Clean up the previous type's meta only on a real switch to another
			// schema type. Never wipe it when switching to 'none': an editor that
			// can't resolve a (legacy) type loads it as 'none', and deleting here
			// would silently destroy the user's data on save. Keeping the meta is
			// safe (nothing is output while the type is 'none') and the data is
			// recovered if the correct type is selected again.
			if ( $old_type && $old_type !== $new_type && 'none' !== $new_type && isset( $prefixes[ $old_type ] ) ) {
				$this->delete_type_meta( $id, $prefixes[ $old_type ] );
			}

			update_post_meta( $id, '_seopress_pro_rich_snippets_type', $new_type );
		}

		if ( isset( $params['rules'] ) ) {
			update_post_meta( $id, '_seopress_pro_rich_snippets_rules', map_deep( $params['rules'], 'sanitize_text_field' ) );
		}

		$this->save_schema_meta( $id, $params['meta'] ?? array() );

		return new \WP_REST_Response(
			array(
				'code' => 'updated',
				'data' => $this->build_schema_data( get_post( $id ) ),
			)
		);
	}

	/**
	 * Fetch all custom field meta keys (normal + hidden), cached via transient.
	 *
	 * Performance:
	 * - Single indexed SQL query (DISTINCT on meta_key, which is indexed in WP core).
	 * - Separates normal and hidden fields in one pass.
	 * - 15-minute transient cache.
	 * - Filterable limit (default 1000, raised from legacy 250).
	 *
	 * Unlike seopress_get_custom_fields(), this method INCLUDES hidden fields
	 * (keys starting with underscore) by default. No filter required.
	 *
	 * @return array{ normal: string[], hidden: string[] }
	 */
	private function get_all_custom_fields() {
		$cache_key = 'seopress_schemas_all_custom_fields';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		/**
		 * Filter the maximum number of custom field keys returned.
		 *
		 * @param int $limit Default: 1000.
		 */
		$limit = (int) apply_filters( 'seopress_schemas_custom_fields_limit', 1000 );

		// Single indexed query on meta_key.
		$all_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} ORDER BY meta_key ASC LIMIT %d",
				$limit
			)
		);

		// Merge Toolset Types fields if plugin active.
		if ( is_plugin_active( 'types/wpcf.php' ) ) {
			$wpcf_fields = get_option( 'wpcf-fields' );
			if ( ! empty( $wpcf_fields ) ) {
				foreach ( $wpcf_fields as $field ) {
					if ( ! empty( $field['meta_key'] ) && ! in_array( $field['meta_key'], $all_keys, true ) ) {
						$all_keys[] = $field['meta_key'];
					}
				}
			}
		}

		/**
		 * Filter the raw list of custom field keys.
		 *
		 * @param string[] $all_keys Full list (normal + hidden).
		 */
		$all_keys = apply_filters( 'seopress_schemas_all_custom_fields', $all_keys );

		// Partition normal vs hidden (prefix "_").
		$normal = array();
		$hidden = array();
		foreach ( $all_keys as $key ) {
			if ( isset( $key[0] ) && '_' === $key[0] ) {
				$hidden[] = $key;
			} else {
				$normal[] = $key;
			}
		}

		$data = array(
			'normal' => $normal,
			'hidden' => $hidden,
		);

		set_transient( $cache_key, $data, 15 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * POST /seopress/v1/schemas/matching-posts — Count posts matching rules.
	 *
	 * Runs WP_Query for each OR group, intersects AND conditions inside each
	 * group, and unions the resulting IDs. Returns total count + sample posts.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processMatchingPosts( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$rules  = isset( $params['rules'] ) ? $params['rules'] : array();

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return new \WP_REST_Response(
				array(
					'count'   => 0,
					'samples' => array(),
				)
			);
		}
		$matched_ids = array();

		foreach ( $rules as $group ) {
			if ( ! is_array( $group ) || empty( $group ) ) {
				continue;
			}

			$group_ids = null; // null = not yet computed.

			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['filter'] ) ) {
					continue;
				}

				$rule_ids = $this->get_post_ids_for_rule( $rule );

				if ( null === $group_ids ) {
					$group_ids = $rule_ids;
				} else {
					$group_ids = array_intersect( $group_ids, $rule_ids );
				}

				if ( empty( $group_ids ) ) {
					break; // No point continuing this group.
				}
			}

			if ( ! empty( $group_ids ) ) {
				$matched_ids = array_unique( array_merge( $matched_ids, $group_ids ) );
			}
		}

		$matched_ids = array_values( $matched_ids );
		$count       = count( $matched_ids );

		// Fetch up to 5 sample posts.
		$samples = array();
		if ( $count > 0 ) {
			$sample_ids   = array_slice( $matched_ids, 0, 5 );
			$sample_posts = get_posts(
				array(
					'post__in'       => $sample_ids,
					'posts_per_page' => 5,
					'orderby'        => 'post__in',
					'post_type'      => 'any',
					'post_status'    => 'publish',
				)
			);
			foreach ( $sample_posts as $sp ) {
				$samples[] = array(
					'id'    => $sp->ID,
					'title' => $sp->post_title,
					'url'   => get_permalink( $sp->ID ),
					'type'  => $sp->post_type,
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'count'   => $count,
				'samples' => $samples,
			)
		);
	}

	/**
	 * Get post IDs matching a single rule.
	 *
	 * @param array $rule The rule: { filter, cond, cpt|taxo|postId }.
	 *
	 * @return array Post IDs.
	 */
	private function get_post_ids_for_rule( $rule ) {
		$filter = isset( $rule['filter'] ) ? $rule['filter'] : '';
		$cond   = isset( $rule['cond'] ) ? $rule['cond'] : 'equal';
		$is_not = 'not_equal' === $cond;

		$query_args = array(
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( 'post_type' === $filter && ! empty( $rule['cpt'] ) ) {
			if ( $is_not ) {
				// "NOT equal" → get all public CPTs except this one.
				$all_pts = array_keys( get_post_types( array( 'public' => true ) ) );
				$pts     = array_values( array_diff( $all_pts, array( $rule['cpt'] ) ) );
				if ( empty( $pts ) ) {
					return array();
				}
				$query_args['post_type'] = $pts;
			} else {
				$query_args['post_type'] = sanitize_key( $rule['cpt'] );
			}
		} elseif ( 'taxonomy' === $filter && ! empty( $rule['taxo'] ) ) {
			$term = get_term( (int) $rule['taxo'] );
			if ( ! $term || is_wp_error( $term ) ) {
				return array();
			}
			$query_args['post_type'] = 'any';
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => $term->taxonomy,
					'field'    => 'term_id',
					'terms'    => (int) $rule['taxo'],
					'operator' => $is_not ? 'NOT IN' : 'IN',
				),
			);
		} elseif ( 'postId' === $filter && ! empty( $rule['postId'] ) ) {
			$post_id = (int) $rule['postId'];
			if ( $is_not ) {
				$query_args['post_type']    = 'any';
				$query_args['post__not_in'] = array( $post_id );
			} else {
				return array( $post_id );
			}
		} else {
			return array();
		}

		// Cap results to avoid memory issues.
		$query_args['posts_per_page'] = 500;

		$query = new \WP_Query( $query_args );
		return $query->posts;
	}

	/**
	 * GET /seopress/v1/schemas/export — Export all schemas as JSON.
	 *
	 * @return \WP_REST_Response
	 */
	public function processExport() {
		$query = new \WP_Query(
			array(
				'post_type'      => 'seopress_schemas',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
			)
		);

		$schemas = array();
		foreach ( $query->posts as $post ) {
			$data = $this->build_schema_data( $post );
			// Drop the ID so imports can create new records.
			unset( $data['id'] );
			$schemas[] = $data;
		}

		return new \WP_REST_Response(
			array(
				'version'    => '1.0',
				'exportedAt' => gmdate( 'c' ),
				'schemas'    => $schemas,
			)
		);
	}

	/**
	 * POST /seopress/v1/schemas/import — Import schemas from JSON.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processImport( \WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$schemas = isset( $params['schemas'] ) ? $params['schemas'] : array();

		if ( empty( $schemas ) || ! is_array( $schemas ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'No schemas to import.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$prefixes = $this->get_all_meta_prefixes();
		$imported = 0;
		$errors   = array();

		foreach ( $schemas as $index => $schema ) {
			if ( empty( $schema['title'] ) || empty( $schema['type'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: schema index in the imported file */
					__( 'Schema #%d: missing title or type.', 'wp-seopress-pro' ),
					$index + 1
				);
				continue;
			}

			$type = sanitize_text_field( $schema['type'] );
			if ( ! array_key_exists( $type, $prefixes ) && 'none' !== $type ) {
				$errors[] = sprintf(
					/* translators: 1: schema index, 2: invalid type */
					__( 'Schema #%1$d: invalid type "%2$s".', 'wp-seopress-pro' ),
					$index + 1,
					$type
				);
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_title'  => sanitize_text_field( $schema['title'] ),
					'post_type'   => 'seopress_schemas',
					'post_status' => ( isset( $schema['enabled'] ) && ! $schema['enabled'] ) ? 'draft' : 'publish',
				)
			);

			if ( is_wp_error( $post_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: schema index, 2: error message */
					__( 'Schema #%1$d: %2$s', 'wp-seopress-pro' ),
					$index + 1,
					$post_id->get_error_message()
				);
				continue;
			}

			update_post_meta( $post_id, '_seopress_pro_rich_snippets_type', $type );

			if ( ! empty( $schema['rules'] ) && is_array( $schema['rules'] ) ) {
				update_post_meta(
					$post_id,
					'_seopress_pro_rich_snippets_rules',
					map_deep( $schema['rules'], 'sanitize_text_field' )
				);
			}

			if ( ! empty( $schema['meta'] ) && is_array( $schema['meta'] ) ) {
				$this->save_schema_meta( $post_id, $schema['meta'] );
			}

			++$imported;
		}

		return new \WP_REST_Response(
			array(
				'imported' => $imported,
				'errors'   => $errors,
			)
		);
	}

	/**
	 * GET /seopress/v1/schemas/post-context/{id} — Post data for JSON-LD preview.
	 *
	 * Returns values that the schema rendering would resolve for a given post,
	 * so the editor can show a realistic JSON-LD preview with real data.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processPostContext( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Post not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		$author_id = (int) $post->post_author;

		$thumbnail_url = '';
		if ( has_post_thumbnail( $id ) ) {
			$thumb = wp_get_attachment_image_src( get_post_thumbnail_id( $id ), 'full' );
			if ( $thumb ) {
				$thumbnail_url = $thumb[0];
			}
		}

		// Knowledge Graph logo from SEO, Social Networks, Knowledge Graph.
		// Surfaced here so the JSON-LD preview can interpolate the
		// `knowledge_graph_logo` mapping with the real URL instead of falling
		// back to the placeholder string in buildJsonLd.js.
		$knowledge_graph_logo = '';
		if ( function_exists( 'seopress_get_service' ) ) {
			$social_option = seopress_get_service( 'SocialOption' );
			if ( is_object( $social_option ) && method_exists( $social_option, 'getSocialKnowledgeImage' ) ) {
				$knowledge_graph_logo = (string) $social_option->getSocialKnowledgeImage();
			}
		}

		return new \WP_REST_Response(
			array(
				// Site meta.
				'site_title'           => get_bloginfo( 'name' ),
				'tagline'              => get_bloginfo( 'description' ),
				'site_url'             => home_url(),
				// Post meta.
				'post_id'              => (string) $id,
				'post_title'           => get_the_title( $id ),
				'post_excerpt'         => get_the_excerpt( $id ),
				'post_content'         => wp_strip_all_tags( wp_trim_words( $post->post_content, 30, '…' ) ),
				'post_permalink'       => get_permalink( $id ),
				'post_author_name'     => get_the_author_meta( 'display_name', $author_id ),
				'post_date'            => get_the_date( 'c', $id ),
				'post_updated'         => get_the_modified_date( 'c', $id ),
				'post_thumbnail'       => $thumbnail_url,
				'post_author_picture'  => get_avatar_url( $author_id ),
				// Global SEOPress settings used as schema sources.
				'knowledge_graph_logo' => $knowledge_graph_logo,
			)
		);
	}

	/**
	 * GET /seopress/v1/schemas/editor-data — Lazy-loaded data for the editor.
	 *
	 * Returns custom fields (normal + hidden, separated), local business types
	 * and the terms behind the Term Taxonomy rule filter. Avoids running
	 * expensive queries on the listing page.
	 * Results are cached for 15 minutes via a transient.
	 *
	 * @return \WP_REST_Response
	 */
	public function processEditorData() {
		$custom_fields = $this->get_all_custom_fields();

		$lb_types = array();
		if ( function_exists( 'seopress_lb_types_list' ) ) {
			$lb_types = seopress_lb_types_list();
		}

		// The term list used to be inlined into every Schemas page load. It is
		// the one payload here that scales with the site's content rather than
		// its configuration, so it belongs behind this route more than any of
		// the others.
		$terms = array(
			'terms'     => array(),
			'truncated' => false,
			'limit'     => 0,
		);
		if ( function_exists( 'seopress_pro_schema_rule_terms' ) ) {
			$terms = seopress_pro_schema_rule_terms();
		}

		return new \WP_REST_Response(
			array(
				'customFields' => $custom_fields,
				'lbTypes'      => $lb_types,
				'terms'        => $terms,
			)
		);
	}

	/**
	 * DELETE /seopress/v1/schemas/{id} — Delete an automatic schema.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processDelete( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_schemas' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Schema not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		wp_delete_post( $id, true );

		return new \WP_REST_Response(
			array(
				'code' => 'deleted',
				'id'   => $id,
			)
		);
	}

	/**
	 * DELETE /seopress/v1/schemas/bulk — Delete multiple schemas.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processBulkDelete( \WP_REST_Request $request ) {
		$ids     = $request->get_param( 'ids' );
		$deleted = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && 'seopress_schemas' === $post->post_type && current_user_can( 'delete_schema', $id ) ) {
				wp_delete_post( $id, true );
				$deleted[] = $id;
			}
		}

		return new \WP_REST_Response(
			array(
				'code'    => 'deleted',
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * POST /seopress/v1/schemas/{id}/duplicate — Duplicate a schema.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processDuplicate( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_schemas' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Schema not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		/* translators: %s: original schema title */
		$new_title = sprintf( __( '%s (copy)', 'wp-seopress-pro' ), $post->post_title );

		$new_post_id = wp_insert_post(
			array(
				'post_title'  => $new_title,
				'post_type'   => 'seopress_schemas',
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Copy all meta from original post.
		$all_meta = get_post_meta( $id );
		foreach ( $all_meta as $key => $values ) {
			if ( 0 === strpos( $key, '_seopress_pro_rich_snippets_' ) ) {
				update_post_meta( $new_post_id, $key, maybe_unserialize( $values[0] ) );
			}
		}

		return new \WP_REST_Response(
			array(
				'code' => 'duplicated',
				'data' => $this->build_schema_data( get_post( $new_post_id ) ),
			),
			201
		);
	}

	/**
	 * Update the current user's schemas UI preferences.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processUpdatePreferences( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new \WP_Error(
				'not_logged_in',
				__( 'You must be logged in.', 'wp-seopress-pro' ),
				array( 'status' => 401 )
			);
		}

		if ( null !== $request->get_param( 'showPreview' ) ) {
			$show_preview = filter_var( $request->get_param( 'showPreview' ), FILTER_VALIDATE_BOOLEAN );
			update_user_meta( $user_id, 'seopress_schemas_show_preview', $show_preview ? '1' : '0' );
		}

		return new \WP_REST_Response(
			array(
				'showPreview' => '1' === (string) get_user_meta( $user_id, 'seopress_schemas_show_preview', true ),
			),
			200
		);
	}
}
