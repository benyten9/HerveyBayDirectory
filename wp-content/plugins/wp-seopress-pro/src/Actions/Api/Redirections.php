<?php

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Actions\Api\Traits\ViewPreferencesTrait;

class Redirections implements ExecuteHooks {

	use ViewPreferencesTrait;

	/**
	 * @var int|null
	 */
	protected $current_user = null;

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * @since 8.8.0
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/redirections',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetAll' ),
				'args'                => array(
					'id'       => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'enabled'  => array(
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'yes', 'no' ), true );
						},
					),
					'type'     => array(
						'validate_callback' => function ( $param ) {
							$types = array( '301', '302', '307', '404', '410', '451' );
							if ( is_array( $param ) ) {
								return array_intersect( $param, $types ) === $param;
							}
							return in_array( $param, $types, true );
						},
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
							return in_array( $param, array( 'title', 'date', 'count', 'type', 'last_hit', 'enabled', 'regex' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'date',
					),
					'view'     => array(
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'redirects', '404', 'all' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'redirects',
					),
					'order'    => array(
						'validate_callback' => function ( $param ) {
							return in_array( strtoupper( $param ), array( 'ASC', 'DESC' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'DESC',
					),
					'search'   => array(
						'validate_callback' => function ( $param ) {
							return is_string( $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
				),
				'permission_callback' => function () {
					$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
					return user_can( $current_user, 'read_redirection' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'permission_callback' => function () {
					// Use the primitive capability (no object id) so the meta-cap mapping does not
					// collapse to the bare "read" capability for published redirections, which every
					// subscriber holds. Mirrors the collection route above.
					$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
					return user_can( $current_user, 'read_redirection' );
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
			'/redirections',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processCreate' ),
				'permission_callback' => function () {
					return current_user_can( 'publish_redirections' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'processUpdate' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_redirection', (int) $request['id'] );
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
			'/redirections/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'processDelete' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'delete_redirection', (int) $request['id'] );
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
			'/redirections/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processValidate' ),
				'permission_callback' => function () {
					$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
					return user_can( $current_user, 'edit_redirections' );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processTest' ),
				'permission_callback' => function () {
					$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
					return user_can( $current_user, 'edit_redirections' );
				},
				'args'                => array(
					'url' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && '' !== trim( $param );
						},
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/categories',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGetCategories' ),
					'permission_callback' => function () {
						$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
						return user_can( $current_user, 'edit_redirections' );
					},
					// Optional filter params. When provided, the returned `count` for
					// each category is scoped to posts matching the same filters as
					// the listing endpoint, so the sidebar numbers stay in sync with
					// what the user will see after clicking a category.
					'args'                => array(
						'view'    => array(
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'redirects', '404', 'all' ), true );
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
						'enabled' => array(
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'yes', 'no' ), true );
							},
						),
						'type'    => array(
							'validate_callback' => function ( $param ) {
								$types = array( '301', '302', '307', '404', '410', '451' );
								if ( is_array( $param ) ) {
									return array_intersect( $param, $types ) === $param;
								}
								return in_array( $param, $types, true );
							},
						),
						'search'  => array(
							'validate_callback' => function ( $param ) {
								return is_string( $param );
							},
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processCreateCategory' ),
					'permission_callback' => array( $this, 'permissionManageCategories' ),
					'args'                => array(
						'name'   => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $param ) {
								return is_string( $param ) && '' !== trim( $param );
							},
						),
						'parent' => array(
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'processUpdateCategory' ),
					'permission_callback' => array( $this, 'permissionManageCategories' ),
					'args'                => array(
						'id'     => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
						'name'   => array(
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $param ) {
								return is_string( $param ) && '' !== trim( $param );
							},
						),
						'parent' => array(
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'processDeleteCategory' ),
					'permission_callback' => array( $this, 'permissionManageCategories' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/view-preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGetViewPreferences' ),
					'permission_callback' => function () {
						$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
						return user_can( $current_user, 'edit_redirections' );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processSaveViewPreferences' ),
					'permission_callback' => function () {
						$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
						return user_can( $current_user, 'edit_redirections' );
					},
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/bulk',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'processBulkDelete' ),
				'permission_callback' => function () {
					return current_user_can( 'delete_others_redirections' );
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
			'/redirections/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processBulkUpdate' ),
				'permission_callback' => function () {
					$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
					return user_can( $current_user, 'edit_redirections' );
				},
				'args'                => array(
					'ids'         => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_array( $param ) && ! empty( $param );
						},
						'sanitize_callback' => function ( $param ) {
							return array_map( 'absint', $param );
						},
					),
					'action'      => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'enable', 'disable', 'assign_category', 'set_type' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category_id' => array(
						'sanitize_callback' => 'absint',
					),
					'type'        => array(
						'validate_callback' => function ( $param ) {
							return in_array( (string) $param, array( '301', '302', '307', '410', '451' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/cleanup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processCleanup' ),
				'permission_callback' => function () {
					return current_user_can( 'delete_others_redirections' );
				},
				'args'                => array(
					'scope' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'older_than', 'zero_hits' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'days'  => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'seopress/v1',
			'/redirections/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processDuplicate' ),
				'permission_callback' => function () {
					$current_user = $this->current_user ? $this->current_user : wp_get_current_user()->ID;
					return user_can( $current_user, 'publish_redirections' );
				},
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Valid HTTP status codes for redirections.
	 *
	 * @return array
	 */
	private function get_valid_types() {
		return array( '301', '302', '307', '410', '451' );
	}

	/**
	 * Build a redirection data object from a post.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array
	 */
	private function build_redirection_data( $post ) {
		$id = $post->ID;

		// Categories (taxonomy terms).
		$categories   = array();
		$category_ids = array();
		$terms        = wp_get_post_terms( $id, 'seopress_404_cat' );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[]   = $term->name;
				$category_ids[] = (int) $term->term_id;
			}
		}

		$stored_type   = get_post_meta( $id, '_seopress_redirections_type', true );
		$stored_param  = get_post_meta( $id, '_seopress_redirections_param', true );
		$stored_logged = get_post_meta( $id, '_seopress_redirections_logged_status', true );

		return array(
			'id'           => $id,
			'origin'       => $post->post_title,
			'destination'  => get_post_meta( $id, '_seopress_redirections_value', true ),
			'enabled'      => 'yes' === get_post_meta( $id, '_seopress_redirections_enabled', true ),
			// Empty type means a logged 404 error not yet converted to a redirection.
			'type'         => ! empty( $stored_type ) ? $stored_type : '',
			'param'        => ! empty( $stored_param ) ? $stored_param : 'exact_match',
			'enabledRegex' => 'yes' === get_post_meta( $id, '_seopress_redirections_enabled_regex', true ),
			'loggedStatus' => ! empty( $stored_logged ) ? $stored_logged : 'both',
			'count'        => (int) get_post_meta( $id, 'seopress_404_count', true ),
			'lastHit'      => get_post_meta( $id, '_seopress_404_redirect_date_request', true ),
			'dateCreated'  => $post->post_date,
			'userAgent'    => get_post_meta( $id, 'seopress_redirections_ua', true ),
			'fullOrigin'   => get_post_meta( $id, 'seopress_redirections_referer', true ),
			'categories'   => $categories,
			'categoryIds'  => $category_ids,
		);
	}

	/**
	 * GET /seopress/v1/redirections — List redirections.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetAll( \WP_REST_Request $request ) {
		$id            = $request->get_param( 'id' );
		$enabled       = $request->get_param( 'enabled' );
		$type          = $request->get_param( 'type' );
		$per_page      = $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 20;
		$page          = $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1;
		$orderby_param = $request->get_param( 'orderby' ) ? $request->get_param( 'orderby' ) : 'date';
		$order_param   = $request->get_param( 'order' ) ? $request->get_param( 'order' ) : 'DESC';
		$order         = strtoupper( $order_param );
		$search        = $request->get_param( 'search' );
		$category      = $request->get_param( 'category' );
		$view          = $request->get_param( 'view' ) ? $request->get_param( 'view' ) : 'redirects';

		$args = array(
			'post_type'      => 'seopress_404',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
			'order'          => $order,
		);

		// Meta-based orderby mapping. Using a named meta_query clause (see below)
		// forces a LEFT JOIN so posts without the meta are still returned.
		$sort_meta_map = array(
			'count'    => array(
				'key'  => 'seopress_404_count',
				'type' => 'NUMERIC',
			),
			'type'     => array(
				'key'  => '_seopress_redirections_type',
				'type' => 'CHAR',
			),
			'last_hit' => array(
				'key'  => '_seopress_404_redirect_date_request',
				'type' => 'NUMERIC',
			),
			'enabled'  => array(
				'key'  => '_seopress_redirections_enabled',
				'type' => 'CHAR',
			),
			'regex'    => array(
				'key'  => '_seopress_redirections_enabled_regex',
				'type' => 'CHAR',
			),
		);

		$sort_clause_config = null;
		switch ( $orderby_param ) {
			case 'title':
				$args['orderby'] = 'title';
				break;
			case 'date':
				$args['orderby'] = 'date';
				break;
			default:
				if ( isset( $sort_meta_map[ $orderby_param ] ) ) {
					$sort_clause_config = $sort_meta_map[ $orderby_param ];
				} else {
					$args['orderby'] = 'date';
				}
				break;
		}

		// Search. WP_Query's `s` only matches post_title (the origin URL)
		// for this CPT. We widen the search clause so the same term also
		// matches the destination URL, stored in the
		// `_seopress_redirections_value` post meta.
		$search_filter = null;
		if ( ! empty( $search ) ) {
			$args['s']                           = $search;
			$args['seopress_search_destination'] = true;

			global $wpdb;
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';

			$search_filter = function ( $search_sql, $wp_query ) use ( $wpdb, $search_like ) {
				if ( true !== $wp_query->get( 'seopress_search_destination' ) ) {
					return $search_sql;
				}

				// Only WP-managed table names ({$wpdb->posts}, {$wpdb->postmeta})
				// are interpolated; every user value goes through %s placeholders.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->prepare(
					" AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN (
						SELECT post_id FROM {$wpdb->postmeta}
						WHERE meta_key = '_seopress_redirections_value' AND meta_value LIKE %s
					) ) ",
					$search_like,
					$search_like
				);
			};

			add_filter( 'posts_search', $search_filter, 10, 2 );
		}

		// Single ID.
		if ( ! empty( $id ) ) {
			$args['p'] = (int) $id;
		}

		// Meta query — shared with processGetCategories so the per-category
		// counts use exactly the same predicates as the visible list.
		$meta_query = $this->build_filters_meta_query( $view, $enabled, $type );

		// Sort clause with LEFT JOIN (EXISTS OR NOT EXISTS) so posts missing the
		// sorted meta are still returned. The OR + NOT EXISTS forces WP to
		// convert the EXISTS branch's INNER JOIN to LEFT JOIN. The EXISTS leaf
		// must itself be named so WP_Query::parse_orderby() can resolve the
		// alias — only leaf clauses (not relation groups) end up in
		// WP_Meta_Query::get_clauses().
		if ( null !== $sort_clause_config ) {
			$meta_query[] = array(
				'relation'    => 'OR',
				'sort_clause' => array(
					'key'     => $sort_clause_config['key'],
					'compare' => 'EXISTS',
					'type'    => $sort_clause_config['type'],
				),
				array(
					'key'     => $sort_clause_config['key'],
					'compare' => 'NOT EXISTS',
				),
			);

			$args['orderby'] = array(
				'sort_clause' => $order,
				'date'        => $order,
			);
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Category (taxonomy) filter.
		if ( ! empty( $category ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'seopress_404_cat',
					'field'    => 'term_id',
					'terms'    => (int) $category,
				),
			);
		}

		$query = new \WP_Query( $args );

		if ( null !== $search_filter ) {
			remove_filter( 'posts_search', $search_filter, 10 );
		}

		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->build_redirection_data( $post );
		}

		return new \WP_REST_Response(
			array(
				'data'       => $items,
				'total'      => $query->found_posts,
				'totalPages' => $query->max_num_pages,
			)
		);
	}

	/**
	 * GET /seopress/v1/redirections/{id} — Get one.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processGet( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_404' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Redirection not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( $this->build_redirection_data( $post ) );
	}

	/**
	 * Sanitize and apply redirection fields to a post.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $params  The request params.
	 *
	 * @return void
	 */
	private function save_redirection_fields( $post_id, $params ) {
		if ( isset( $params['destination'] ) ) {
			update_post_meta( $post_id, '_seopress_redirections_value', esc_url_raw( $params['destination'] ) );
		}
		if ( isset( $params['type'] ) ) {
			$type = (string) $params['type'];
			if ( in_array( $type, $this->get_valid_types(), true ) ) {
				update_post_meta( $post_id, '_seopress_redirections_type', $type );
			}
		}
		if ( isset( $params['enabled'] ) ) {
			if ( $params['enabled'] ) {
				update_post_meta( $post_id, '_seopress_redirections_enabled', 'yes' );
			} else {
				delete_post_meta( $post_id, '_seopress_redirections_enabled' );
			}
		}
		if ( isset( $params['enabledRegex'] ) ) {
			if ( $params['enabledRegex'] ) {
				update_post_meta( $post_id, '_seopress_redirections_enabled_regex', 'yes' );
			} else {
				delete_post_meta( $post_id, '_seopress_redirections_enabled_regex' );
			}
		}
		if ( isset( $params['param'] ) ) {
			$param = sanitize_text_field( $params['param'] );
			if ( in_array( $param, array( 'exact_match', 'without_param', 'with_ignored_param' ), true ) ) {
				update_post_meta( $post_id, '_seopress_redirections_param', $param );
			}
		}
		if ( isset( $params['loggedStatus'] ) ) {
			$status = sanitize_text_field( $params['loggedStatus'] );
			if ( in_array( $status, array( 'both', 'only_logged_in', 'only_not_logged_in' ), true ) ) {
				update_post_meta( $post_id, '_seopress_redirections_logged_status', $status );
			}
		}
		if ( isset( $params['categoryIds'] ) && is_array( $params['categoryIds'] ) ) {
			$ids = array_map( 'absint', $params['categoryIds'] );
			$ids = array_filter( $ids );
			wp_set_post_terms( $post_id, $ids, 'seopress_404_cat' );
		}
	}

	/**
	 * Normalize an origin URL path (strip domain, leading slash, anchors).
	 *
	 * @param string $origin Raw origin input.
	 *
	 * @return string
	 */
	private function normalize_origin( $origin ) {
		$origin = trim( (string) $origin );
		// Strip anchor.
		$pos = strpos( $origin, '#' );
		if ( false !== $pos ) {
			$origin = substr( $origin, 0, $pos );
		}
		// Strip domain if full URL.
		$parts = wp_parse_url( $origin );
		if ( ! empty( $parts['host'] ) ) {
			$origin = isset( $parts['path'] ) ? $parts['path'] : '/';
			if ( ! empty( $parts['query'] ) ) {
				$origin .= '?' . $parts['query'];
			}
		}
		// Trim leading slash for consistency with legacy storage.
		$origin = ltrim( $origin, '/' );
		// Do NOT use sanitize_text_field() here: it runs _sanitize_text_fields(),
		// which strips every %XX sequence and destroys percent-encoded UTF-8
		// paths (e.g. /%C3%BCber-uns → ber-uns). esc_url_raw() is also unsuitable
		// because it percent-encodes / strips regex metacharacters ([]{}\^),
		// which would silently break regex-mode redirections that also pass
		// through this method. Instead, apply the same hardening as
		// sanitize_text_field() *except* the %XX-stripping loop: drop invalid
		// UTF-8, strip HTML tags, and collapse whitespace/control characters.
		// This preserves %XX, raw UTF-8 and regex patterns alike. post_title is
		// additionally KSES-filtered by core on save, and every read path
		// escapes at output, so removing the %XX loop does not widen exposure.
		$origin = wp_check_invalid_utf8( $origin );
		$origin = wp_strip_all_tags( $origin );
		$origin = preg_replace( '/[\r\n\t ]+/', ' ', $origin );
		return trim( $origin );
	}

	/**
	 * POST /seopress/v1/redirections — Create a new redirection.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processCreate( \WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['origin'] ) ) {
			return new \WP_Error(
				'missing_parameters',
				__( 'Origin URL is required.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$origin = $this->normalize_origin( $params['origin'] );

		$post_id = wp_insert_post(
			array(
				'post_title'  => $origin,
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Default enabled = true on create unless explicitly set.
		if ( ! isset( $params['enabled'] ) ) {
			$params['enabled'] = true;
		}
		// Default type = 301 on create.
		if ( ! isset( $params['type'] ) ) {
			$params['type'] = '301';
		}
		// Default Query Parameters mode for new redirects: respect the global
		// option (set in SEO PRO > 404 / Redirections), falling back to
		// 'exact_match' for backward compatibility. Only applied when the
		// client didn't send an explicit value, so the React editor's choice
		// always wins.
		if ( ! isset( $params['param'] ) && function_exists( 'seopress_pro_get_service' ) ) {
			$params['param'] = seopress_pro_get_service( 'OptionPro' )->get404DefaultParam();
		}

		$this->save_redirection_fields( $post_id, $params );

		$this->dismiss_matching_slug_change_notices( $origin );

		return new \WP_REST_Response(
			array(
				'code' => 'created',
				'id'   => $post_id,
				'data' => $this->build_redirection_data( get_post( $post_id ) ),
			),
			201
		);
	}

	/**
	 * Remove any pending "we detected a slug change / deletion" admin notice
	 * whose before_url matches the origin of a redirection that was just
	 * created. The user has now done what the notice was suggesting.
	 *
	 * Match is tolerant: leading/trailing slashes are stripped and the
	 * comparison is case-insensitive.
	 *
	 * @param string $origin Normalized origin of the new redirection.
	 * @return void
	 */
	private function dismiss_matching_slug_change_notices( $origin ) {
		if ( ! function_exists( 'seopress_get_option_post_need_redirects' ) || ! function_exists( 'seopress_remove_notification_for_redirect' ) ) {
			return;
		}

		$notices = seopress_get_option_post_need_redirects();
		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return;
		}

		$needle = strtolower( trim( (string) $origin, '/' ) );
		if ( '' === $needle ) {
			return;
		}

		foreach ( $notices as $notice ) {
			if ( ! isset( $notice['before_url'], $notice['id'] ) ) {
				continue;
			}
			if ( strtolower( trim( (string) $notice['before_url'], '/' ) ) === $needle ) {
				seopress_remove_notification_for_redirect( $notice['id'] );
			}
		}
	}

	/**
	 * PUT /seopress/v1/redirections/{id} — Update a redirection.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processUpdate( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_404' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Redirection not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		$params = $request->get_json_params();

		if ( isset( $params['origin'] ) ) {
			$origin = $this->normalize_origin( $params['origin'] );
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $origin,
				)
			);
		}

		$this->save_redirection_fields( $id, $params );

		return new \WP_REST_Response(
			array(
				'code' => 'updated',
				'data' => $this->build_redirection_data( get_post( $id ) ),
			)
		);
	}

	/**
	 * DELETE /seopress/v1/redirections/{id} — Delete a redirection.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processDelete( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_404' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Redirection not found.', 'wp-seopress-pro' ),
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
	 * POST /seopress/v1/redirections/validate — Validate a redirection draft.
	 *
	 * Performs server-side checks:
	 * - Duplicate origin: another redirection already uses this origin
	 * - Redirect chain: destination matches the origin of another redirection
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processValidate( \WP_REST_Request $request ) {
		$params      = $request->get_json_params();
		$origin      = isset( $params['origin'] ) ? $this->normalize_origin( $params['origin'] ) : '';
		$destination = isset( $params['destination'] ) ? (string) $params['destination'] : '';
		$exclude_id  = isset( $params['id'] ) ? absint( $params['id'] ) : 0;

		$result = array(
			'duplicate' => null,
			'chain'     => null,
		);

		// Duplicate origin check.
		if ( ! empty( $origin ) ) {
			$existing = get_posts(
				array(
					'post_type'      => 'seopress_404',
					'post_status'    => 'any',
					'title'          => $origin,
					'posts_per_page' => 1,
					'post__not_in'   => $exclude_id ? array( $exclude_id ) : array(),
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $existing ) ) {
				$dup_id              = $existing[0];
				$result['duplicate'] = array(
					'id'    => $dup_id,
					'title' => get_the_title( $dup_id ),
				);
			}
		}

		// Redirect chain check: destination is the origin of another redirection.
		if ( ! empty( $destination ) ) {
			$dest_path = wp_parse_url( $destination, PHP_URL_PATH );
			if ( $dest_path ) {
				$dest_path = ltrim( $dest_path, '/' );

				$chain_post = get_posts(
					array(
						'post_type'      => 'seopress_404',
						'post_status'    => 'any',
						'title'          => $dest_path,
						'posts_per_page' => 1,
						'post__not_in'   => $exclude_id ? array( $exclude_id ) : array(),
						'fields'         => 'ids',
					)
				);

				if ( ! empty( $chain_post ) ) {
					$chain_id          = $chain_post[0];
					$chain_destination = get_post_meta( $chain_id, '_seopress_redirections_value', true );

					if ( ! empty( $chain_destination ) ) {
						$result['chain'] = array(
							'id'          => $chain_id,
							'origin'      => $dest_path,
							'destination' => $chain_destination,
						);
					}
				}
			}
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * POST /seopress/v1/redirections/test — Test a URL against existing redirections.
	 *
	 * Finds the first matching redirection (exact or regex) and follows the chain
	 * up to 5 hops to surface infinite loops.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processTest( \WP_REST_Request $request ) {
		$input_url = (string) $request->get_param( 'url' );
		$path      = $this->normalize_origin( $input_url );

		$result = $this->resolve_redirection_chain( $path, array() );

		return new \WP_REST_Response( $result );
	}

	/**
	 * Find a matching redirection for a given path, following redirect chains.
	 *
	 * @param string $path      Normalized path (no leading slash, no anchor).
	 * @param array  $visited   Already-visited redirection IDs (loop protection).
	 *
	 * @return array { matched: bool, hops: array, loop: bool }
	 */
	private function resolve_redirection_chain( $path, $visited = array() ) {
		$hops    = array();
		$loop    = false;
		$hop     = 0;
		$max     = 5;
		$current = $path;

		while ( $hop < $max ) {
			$match = $this->find_matching_redirection( $current );
			if ( ! $match ) {
				break;
			}
			if ( in_array( (int) $match['id'], $visited, true ) ) {
				$loop   = true;
				$hops[] = $match;
				break;
			}
			$visited[] = (int) $match['id'];
			$hops[]    = $match;

			if ( in_array( $match['type'], array( '410', '451' ), true ) ) {
				break;
			}

			// Continue the chain with the destination.
			$destination = $match['destination'];
			if ( empty( $destination ) ) {
				break;
			}
			$current = $this->normalize_origin( $destination );
			++$hop;
		}

		return array(
			'matched' => ! empty( $hops ),
			'hops'    => $hops,
			'loop'    => $loop,
			'input'   => $path,
		);
	}

	/**
	 * Find the first redirection that matches a given path.
	 *
	 * Tries exact match first, then falls back to regex patterns.
	 *
	 * @param string $path Normalized path.
	 *
	 * @return array|null { id, origin, destination, type, regex, matched_by, replaced }
	 */
	private function find_matching_redirection( $path ) {
		// Exact match first.
		$exact = get_posts(
			array(
				'post_type'      => 'seopress_404',
				'post_status'    => 'publish',
				'title'          => $path,
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => '_seopress_redirections_enabled',
						'value'   => 'yes',
						'compare' => '=',
					),
				),
			)
		);
		if ( ! empty( $exact ) ) {
			$p    = $exact[0];
			$type = get_post_meta( $p->ID, '_seopress_redirections_type', true );
			return array(
				'id'          => (int) $p->ID,
				'origin'      => $p->post_title,
				'destination' => get_post_meta( $p->ID, '_seopress_redirections_value', true ),
				'type'        => ! empty( $type ) ? $type : '301',
				'regex'       => false,
				'matched_by'  => 'exact',
				'replaced'    => get_post_meta( $p->ID, '_seopress_redirections_value', true ),
			);
		}

		// Regex match.
		$regex_posts = get_posts(
			array(
				'post_type'      => 'seopress_404',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_seopress_redirections_enabled',
						'value'   => 'yes',
						'compare' => '=',
					),
					array(
						'key'     => '_seopress_redirections_enabled_regex',
						'value'   => 'yes',
						'compare' => '=',
					),
				),
			)
		);
		foreach ( $regex_posts as $p ) {
			$pattern = $p->post_title;
			if ( '' === $pattern ) {
				continue;
			}
			$delimited = '#' . str_replace( '#', '\\#', $pattern ) . '#';
			// Silence warnings on invalid user patterns — we just want to know if it matches.
			$matched = @preg_match( $delimited, $path, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( 1 === $matched ) {
				$dest     = get_post_meta( $p->ID, '_seopress_redirections_value', true );
				$replaced = $dest;
				if ( ! empty( $matches ) && is_string( $dest ) ) {
					foreach ( $matches as $i => $capture ) {
						$replaced = str_replace( '$' . $i, $capture, $replaced );
					}
				}
				$type = get_post_meta( $p->ID, '_seopress_redirections_type', true );
				return array(
					'id'          => (int) $p->ID,
					'origin'      => $pattern,
					'destination' => $dest,
					'type'        => ! empty( $type ) ? $type : '301',
					'regex'       => true,
					'matched_by'  => 'regex',
					'replaced'    => $replaced,
				);
			}
		}

		return null;
	}

	/**
	 * User-meta key for the persisted DataViews view state.
	 *
	 * @return string
	 */
	protected function getViewPrefsMetaKey() {
		return 'seopress_redirections_view';
	}

	/**
	 * Cover every persistent DataViews view field (density, layout, titleField,
	 * mediaField, descriptionField, showMedia…) so that all display preferences
	 * are actually restored on next load.
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
			'viewMode',
			'showCategoriesSidebar',
		);
	}

	/**
	 * Capability check for category write operations.
	 *
	 * Mirrors the access required by the legacy edit-tags.php screen for
	 * the seopress_404_cat taxonomy: `manage_categories`. Keeping the same
	 * capability ensures any user who could already manage these terms via
	 * the legacy WP screen can do it from the new UI, and no one else can.
	 *
	 * @return bool
	 */
	public function permissionManageCategories() {
		return current_user_can( 'manage_categories' );
	}

	/**
	 * Build the public-facing array shape of a redirection category term.
	 *
	 * @param \WP_Term $term  The term object.
	 * @param int|null $count Pre-computed count, or null to compute fresh.
	 *
	 * @return array
	 */
	private function build_category_data( $term, $count = null ) {
		// Never trust $term->count: the cached value in wp_term_taxonomy can
		// drift if redirections are removed through a code path that bypasses
		// WP's term-count callback (raw SQL deletes, imports). Compute fresh
		// unless the caller provides an already-computed value.
		if ( null === $count ) {
			$count = $this->count_publish_redirections_in_term( (int) $term->term_id );
		}

		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
			'count'  => (int) $count,
		);
	}

	/**
	 * Build the meta_query fragment shared by the listing and category-count
	 * endpoints so the sidebar numbers always match what the user will see in
	 * the list under the same filters.
	 *
	 * @param string|null $view    'redirects' | '404' | 'all' | null.
	 * @param string|null $enabled 'yes' | 'no' | null.
	 * @param mixed       $type    A single redirection type, array of types, or empty.
	 *
	 * @return array
	 */
	private function build_filters_meta_query( $view, $enabled, $type ) {
		$meta_query = array();

		// View: redirects (has type) | 404 (no type) | all (no filter).
		if ( 'redirects' === $view ) {
			$meta_query[] = array(
				'key'     => '_seopress_redirections_type',
				'compare' => 'EXISTS',
			);
		} elseif ( '404' === $view ) {
			$meta_query[] = array(
				'key'     => '_seopress_redirections_type',
				'compare' => 'NOT EXISTS',
			);
		}

		if ( 'yes' === $enabled ) {
			$meta_query[] = array(
				'key'     => '_seopress_redirections_enabled',
				'value'   => 'yes',
				'compare' => '=',
			);
		} elseif ( 'no' === $enabled ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_seopress_redirections_enabled',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_seopress_redirections_enabled',
					'value'   => 'yes',
					'compare' => '!=',
				),
			);
		}

		if ( ! empty( $type ) ) {
			$meta_query[] = array(
				'key'     => '_seopress_redirections_type',
				'value'   => $type,
				'compare' => is_array( $type ) ? 'IN' : '=',
			);
		}

		return $meta_query;
	}

	/**
	 * Count publish seopress_404 posts attached to a term, optionally scoped
	 * by the listing filters (view/enabled/type/search).
	 *
	 * @param int    $term_id    Term id.
	 * @param array  $meta_query Filter meta_query (see build_filters_meta_query).
	 * @param string $search     Optional search string.
	 *
	 * @return int
	 */
	private function count_publish_redirections_in_term( $term_id, $meta_query = array(), $search = '' ) {
		$args = array(
			'post_type'      => 'seopress_404',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'seopress_404_cat',
					'field'    => 'term_id',
					'terms'    => (int) $term_id,
				),
			),
		);

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Count publish seopress_404 posts matching the listing filters, regardless
	 * of category. This is the authoritative "All redirections" total: it
	 * matches the DataViews `found_posts` (same predicates, no tax filter) and,
	 * unlike summing the per-term counts, it includes uncategorized
	 * redirections and never double-counts posts in several categories.
	 *
	 * @param array  $meta_query Filter meta_query (see build_filters_meta_query).
	 * @param string $search     Optional search string.
	 *
	 * @return int
	 */
	private function count_all_publish_redirections( $meta_query = array(), $search = '' ) {
		$args = array(
			'post_type'      => 'seopress_404',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * GET /seopress/v1/redirections/categories — List redirection categories.
	 *
	 * Counts are computed on the fly (the cached wp_term_taxonomy.count value
	 * can drift) and, when filter params are provided, scoped to those same
	 * filters so the sidebar numbers reflect what clicking a category will
	 * actually show.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetCategories( \WP_REST_Request $request ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'seopress_404_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return new \WP_REST_Response( array() );
		}

		$view    = $request->get_param( 'view' );
		$enabled = $request->get_param( 'enabled' );
		$type    = $request->get_param( 'type' );
		$search  = $request->get_param( 'search' );

		$meta_query = $this->build_filters_meta_query( $view, $enabled, $type );
		$search     = is_string( $search ) ? $search : '';

		$categories = array();
		foreach ( $terms as $term ) {
			$count        = $this->count_publish_redirections_in_term( (int) $term->term_id, $meta_query, $search );
			$categories[] = $this->build_category_data( $term, $count );
		}

		// `total` is the authoritative "All redirections" count: every matching
		// redirection regardless of category. Summing the per-term counts on the
		// client drops uncategorized redirections and double-counts ones in
		// several categories, so the sidebar reads this value directly.
		return new \WP_REST_Response(
			array(
				'total'      => $this->count_all_publish_redirections( $meta_query, $search ),
				'categories' => $categories,
			)
		);
	}

	/**
	 * POST /seopress/v1/redirections/categories — Create a redirection category.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processCreateCategory( \WP_REST_Request $request ) {
		$name   = trim( (string) $request->get_param( 'name' ) );
		$parent = (int) $request->get_param( 'parent' );

		if ( '' === $name ) {
			return new \WP_Error( 'seopress_category_name_empty', __( 'Category name is required.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
		}

		$args = array();
		if ( $parent > 0 ) {
			$parent_term = get_term( $parent, 'seopress_404_cat' );
			if ( ! $parent_term || is_wp_error( $parent_term ) ) {
				return new \WP_Error( 'seopress_category_parent_invalid', __( 'Parent category does not exist.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
			}
			$args['parent'] = $parent;
		}

		$result = wp_insert_term( $name, 'seopress_404_cat', $args );

		if ( is_wp_error( $result ) ) {
			$status = 'term_exists' === $result->get_error_code() ? 409 : 400;
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		$term = get_term( (int) $result['term_id'], 'seopress_404_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'seopress_category_fetch_failed', __( 'Category created but could not be retrieved.', 'wp-seopress-pro' ), array( 'status' => 500 ) );
		}

		// New term: no posts can be attached yet, skip the count query.
		return rest_ensure_response( $this->build_category_data( $term, 0 ) );
	}

	/**
	 * PATCH /seopress/v1/redirections/categories/{id} — Rename / reparent a category.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processUpdateCategory( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		$term = get_term( $id, 'seopress_404_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'seopress_category_not_found', __( 'Category not found.', 'wp-seopress-pro' ), array( 'status' => 404 ) );
		}

		$args = array();

		$name_param = $request->get_param( 'name' );
		if ( null !== $name_param ) {
			$name = trim( (string) $name_param );
			if ( '' === $name ) {
				return new \WP_Error( 'seopress_category_name_empty', __( 'Category name is required.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
			}
			$args['name'] = $name;
		}

		$parent_param = $request->get_param( 'parent' );
		if ( null !== $parent_param ) {
			$parent = (int) $parent_param;

			if ( $parent === $id ) {
				return new \WP_Error( 'seopress_category_parent_self', __( 'A category cannot be its own parent.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
			}

			if ( $parent > 0 ) {
				$parent_term = get_term( $parent, 'seopress_404_cat' );
				if ( ! $parent_term || is_wp_error( $parent_term ) ) {
					return new \WP_Error( 'seopress_category_parent_invalid', __( 'Parent category does not exist.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
				}

				// Anti-cycle: walk the proposed parent's ancestors and refuse if we
				// hit the term being edited.
				$ancestors = get_ancestors( $parent, 'seopress_404_cat', 'taxonomy' );
				if ( in_array( $id, array_map( 'intval', $ancestors ), true ) ) {
					return new \WP_Error( 'seopress_category_parent_cycle', __( 'A category cannot be moved under one of its descendants.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
				}
			}

			$args['parent'] = $parent;
		}

		if ( empty( $args ) ) {
			return rest_ensure_response( $this->build_category_data( $term ) );
		}

		$result = wp_update_term( $id, 'seopress_404_cat', $args );

		if ( is_wp_error( $result ) ) {
			$status = 'term_exists' === $result->get_error_code() ? 409 : 400;
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		$updated = get_term( (int) $result['term_id'], 'seopress_404_cat' );
		if ( ! $updated || is_wp_error( $updated ) ) {
			return new \WP_Error( 'seopress_category_fetch_failed', __( 'Category updated but could not be retrieved.', 'wp-seopress-pro' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->build_category_data( $updated ) );
	}

	/**
	 * DELETE /seopress/v1/redirections/categories/{id} — Delete a category.
	 *
	 * Detach-only: redirections previously rattached to this term simply lose
	 * the category, they are never deleted. Mirrors the WP core default for
	 * `wp_delete_term` so behaviour matches the legacy edit-tags.php screen.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processDeleteCategory( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		$term = get_term( $id, 'seopress_404_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'seopress_category_not_found', __( 'Category not found.', 'wp-seopress-pro' ), array( 'status' => 404 ) );
		}

		$result = wp_delete_term( $id, 'seopress_404_cat' );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		if ( false === $result || 0 === $result ) {
			return new \WP_Error( 'seopress_category_delete_failed', __( 'Failed to delete category.', 'wp-seopress-pro' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'id'      => $id,
				'deleted' => true,
			)
		);
	}

	/**
	 * DELETE /seopress/v1/redirections/bulk — Delete multiple redirections.
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
			if ( $post && 'seopress_404' === $post->post_type && current_user_can( 'delete_redirection', $id ) ) {
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
	 * POST /seopress/v1/redirections/cleanup — Cleanup 404 errors (not redirects).
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processCleanup( \WP_REST_Request $request ) {
		$scope = (string) $request->get_param( 'scope' );
		$days  = (int) $request->get_param( 'days' );

		$args = array(
			'post_type'      => 'seopress_404',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// Only touch entries that have NOT been converted to redirects.
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_seopress_redirections_type',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		if ( 'older_than' === $scope && $days > 0 ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_date_gmt',
					'before' => gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ),
				),
			);
		} elseif ( 'zero_hits' === $scope ) {
			$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => 'seopress_404_count',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'seopress_404_count',
					'value'   => 0,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			);
		} else {
			return new \WP_Error(
				'invalid_scope',
				__( 'Invalid cleanup scope.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$query   = new \WP_Query( $args );
		$deleted = 0;
		foreach ( $query->posts as $post_id ) {
			if ( current_user_can( 'delete_redirection', $post_id ) ) {
				wp_delete_post( $post_id, true );
				++$deleted;
			}
		}

		return new \WP_REST_Response(
			array(
				'code'    => 'cleaned',
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * POST /seopress/v1/redirections/bulk — Bulk enable or disable redirections.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processBulkUpdate( \WP_REST_Request $request ) {
		$ids         = $request->get_param( 'ids' );
		$action      = $request->get_param( 'action' );
		$category_id = (int) $request->get_param( 'category_id' );
		$type        = (string) $request->get_param( 'type' );
		$updated     = array();

		if ( 'assign_category' === $action && $category_id > 0 ) {
			$term = get_term( $category_id, 'seopress_404_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error(
					'invalid_category',
					__( 'Category not found.', 'wp-seopress-pro' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( 'set_type' === $action && ! in_array( $type, $this->get_valid_types(), true ) ) {
			return new \WP_Error(
				'invalid_type',
				__( 'Invalid redirection type.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'seopress_404' !== $post->post_type ) {
				continue;
			}
			if ( ! current_user_can( 'edit_redirection', $id ) ) {
				continue;
			}
			if ( 'enable' === $action ) {
				update_post_meta( $id, '_seopress_redirections_enabled', 'yes' );
			} elseif ( 'disable' === $action ) {
				delete_post_meta( $id, '_seopress_redirections_enabled' );
			} elseif ( 'assign_category' === $action && $category_id > 0 ) {
				wp_set_post_terms( $id, array( $category_id ), 'seopress_404_cat', true );
			} elseif ( 'set_type' === $action ) {
				// If the entry had no type yet (i.e. it is a 404 error being
				// converted into a real redirect), enable it by default.
				// Otherwise keep the existing enabled/disabled state so the
				// bulk action never silently re-enables a redirect the user
				// has explicitly disabled.
				$previous_type = get_post_meta( $id, '_seopress_redirections_type', true );
				update_post_meta( $id, '_seopress_redirections_type', $type );
				if ( empty( $previous_type ) ) {
					update_post_meta( $id, '_seopress_redirections_enabled', 'yes' );
				}
			}
			$updated[] = $id;
		}

		return new \WP_REST_Response(
			array(
				'code'    => 'updated',
				'updated' => $updated,
			)
		);
	}

	/**
	 * POST /seopress/v1/redirections/{id}/duplicate — Duplicate a redirection.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processDuplicate( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'seopress_404' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				__( 'Redirection not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		$new_id = wp_insert_post(
			array(
				/* translators: %s: redirection origin */
				'post_title'  => sprintf( __( '%s (copy)', 'wp-seopress-pro' ), $post->post_title ),
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy all meta fields.
		$meta_keys = array(
			'_seopress_redirections_value',
			'_seopress_redirections_type',
			'_seopress_redirections_enabled_regex',
			'_seopress_redirections_param',
			'_seopress_redirections_logged_status',
		);
		foreach ( $meta_keys as $meta_key ) {
			$value = get_post_meta( $id, $meta_key, true );
			if ( '' !== $value ) {
				update_post_meta( $new_id, $meta_key, $value );
			}
		}

		// Duplicate starts disabled to avoid accidental collisions.
		delete_post_meta( $new_id, '_seopress_redirections_enabled' );

		// Copy categories.
		$terms = wp_get_post_terms( $id, 'seopress_404_cat', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			wp_set_post_terms( $new_id, $terms, 'seopress_404_cat' );
		}

		return new \WP_REST_Response(
			array(
				'code' => 'duplicated',
				'id'   => $new_id,
				'data' => $this->build_redirection_data( get_post( $new_id ) ),
			),
			201
		);
	}
}
