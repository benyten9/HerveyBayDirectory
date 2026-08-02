<?php

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPressPro\Actions\Api\Traits\ViewPreferencesTrait;

class BrokenLinks implements ExecuteHooks {

	use ViewPreferencesTrait;

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register REST routes for broken links.
	 *
	 * @since 9.8.0
	 *
	 * @return void
	 */
	public function register() {
		// List all broken links (paginated).
		register_rest_route(
			'seopress/v1',
			'/broken-links',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetAll' ),
				'args'                => array(
					'per_page' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0 && (int) $param <= 100;
						},
						'sanitize_callback' => 'absint',
						'default'           => 25,
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
							return in_array( $param, array( 'title', 'date', 'status', 'count', 'source' ), true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'date',
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
					'status'   => array(
						'validate_callback' => function ( $param ) {
							if ( is_array( $param ) ) {
								foreach ( $param as $v ) {
									if ( ! is_string( $v ) ) {
										return false;
									}
								}
								return true;
							}
							return is_string( $param );
						},
						'sanitize_callback' => function ( $param ) {
							if ( is_array( $param ) ) {
								return array_map( 'sanitize_text_field', $param );
							}
							return sanitize_text_field( $param );
						},
					),
				),
				'permission_callback' => function () {
					return current_user_can( 'edit_broken_links' );
				},
			)
		);

		// Get single broken link.
		register_rest_route(
			'seopress/v1',
			'/broken-links/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_broken_links' );
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

		// Delete single broken link.
		register_rest_route(
			'seopress/v1',
			'/broken-links/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'processDelete' ),
				'permission_callback' => function () {
					return current_user_can( 'delete_broken_links' );
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

		// Bulk delete.
		register_rest_route(
			'seopress/v1',
			'/broken-links/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processBulkDelete' ),
				'permission_callback' => function () {
					return current_user_can( 'delete_broken_links' );
				},
			)
		);

		// Scan status.
		register_rest_route(
			'seopress/v1',
			'/broken-links/scan-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processScanStatus' ),
				'permission_callback' => function () {
					return current_user_can( seopress_capability( 'manage_options', 'bot' ) );
				},
			)
		);

		// Start / cancel scan.
		register_rest_route(
			'seopress/v1',
			'/broken-links/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processScan' ),
				'permission_callback' => function () {
					return current_user_can( seopress_capability( 'manage_options', 'bot' ) );
				},
			)
		);

		// Export CSV.
		register_rest_route(
			'seopress/v1',
			'/broken-links/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processExport' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_broken_links' );
				},
			)
		);

		// View preferences (persist DataViews state).
		register_rest_route(
			'seopress/v1',
			'/broken-links/view-preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'processGetViewPreferences' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_broken_links' );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'processSaveViewPreferences' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_broken_links' );
					},
				),
			)
		);
	}

	/**
	 * List broken links with pagination, sorting, filtering, and search.
	 *
	 * @since 9.8.0
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processGetAll( \WP_REST_Request $request ) {
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );
		$orderby  = $request->get_param( 'orderby' );
		$order    = strtoupper( $request->get_param( 'order' ) );
		$search   = $request->get_param( 'search' );
		$status   = $request->get_param( 'status' );

		$args = array(
			'post_type'      => 'seopress_bot',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
		);

		// Search by URL (post_title).
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		// Sorting.
		$meta_orderby_map = array(
			'status' => 'seopress_bot_status',
			'count'  => 'seopress_bot_count',
			'source' => 'seopress_bot_source_title',
		);

		if ( isset( $meta_orderby_map[ $orderby ] ) ) {
			$args['meta_key'] = $meta_orderby_map[ $orderby ];
			$args['orderby']  = 'count' === $orderby ? 'meta_value_num' : 'meta_value';
			$args['order']    = $order;
		} else {
			$args['orderby'] = 'title' === $orderby ? 'title' : 'date';
			$args['order']   = $order;
		}

		// Filter by status code.
		if ( ! empty( $status ) ) {
			$status_values = is_array( $status ) ? $status : array( $status );

			$args['meta_query'][] = array(
				'key'     => 'seopress_bot_status',
				'value'   => $status_values,
				'compare' => count( $status_values ) > 1 ? 'IN' : '=',
			);
		}

		$query = new \WP_Query( $args );
		$data  = array();

		foreach ( $query->posts as $post ) {
			$data[] = $this->formatPost( $post );
		}

		return new \WP_REST_Response(
			array(
				'data'       => $data,
				'total'      => (int) $query->found_posts,
				'totalPages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Get a single broken link.
	 *
	 * @since 9.8.0
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processGet( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post || 'seopress_bot' !== $post->post_type ) {
			return new \WP_Error(
				'seopress_broken_link_not_found',
				__( 'Broken link not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( $this->formatPost( $post ) );
	}

	/**
	 * Delete a single broken link.
	 *
	 * @since 9.8.0
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processDelete( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post || 'seopress_bot' !== $post->post_type ) {
			return new \WP_Error(
				'seopress_broken_link_not_found',
				__( 'Broken link not found.', 'wp-seopress-pro' ),
				array( 'status' => 404 )
			);
		}

		wp_delete_post( $id, true );

		return new \WP_REST_Response( array( 'deleted' => $id ) );
	}

	/**
	 * Bulk delete broken links.
	 *
	 * @since 9.8.0
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function processBulkDelete( \WP_REST_Request $request ) {
		$ids = $request->get_param( 'ids' );

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return new \WP_Error(
				'seopress_broken_links_no_items',
				__( 'No items specified.', 'wp-seopress-pro' ),
				array( 'status' => 400 )
			);
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			$id   = absint( $id );
			$post = get_post( $id );
			if ( $post && 'seopress_bot' === $post->post_type ) {
				wp_delete_post( $id, true );
				++$deleted;
			}
		}

		return new \WP_REST_Response( array( 'deleted' => $deleted ) );
	}

	/**
	 * Get scan status.
	 *
	 * @since 9.8.0
	 *
	 * @return \WP_REST_Response
	 */
	public function processScanStatus() {
		return new \WP_REST_Response(
			array(
				'running'   => (int) get_option( 'seopress_pro_broken_links_running', 0 ),
				'offset'    => (int) get_option( 'seopress_pro_broken_links_offset', 0 ),
				'total'     => (int) get_option( 'seopress_pro_broken_links_count_posts', 0 ),
				'processed' => (int) get_option( 'seopress_pro_broken_links_post_count', 0 ),
				'log'       => sanitize_text_field( get_option( 'seopress_pro_broken_links_log', '' ) ),
				'lastScan'  => sanitize_text_field( get_option( 'seopress_bot_log', '' ) ),
				'duration'  => (int) get_option( 'seopress_pro_broken_links_scan_duration', 0 ),
			)
		);
	}

	/**
	 * Start or cancel a scan.
	 *
	 * @since 9.8.0
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function processScan( \WP_REST_Request $request ) {
		$action = $request->get_param( 'action' );

		if ( 'cancel' === $action ) {
			if ( function_exists( 'seopress_broken_links_cancel_task' ) ) {
				seopress_broken_links_cancel_task();
			}
			return new \WP_REST_Response( array( 'status' => 'canceled' ) );
		}

		// Start scan.
		$offset = $request->get_param( 'offset' );
		$offset = is_numeric( $offset ) ? absint( $offset ) : 0;
		$new    = 0 === $offset;

		update_option( 'seopress_pro_broken_links_running', 1, false );

		if ( $new ) {
			delete_option( 'seopress_pro_broken_links_last_error' );
			delete_option( 'seopress_pro_broken_links_heartbeat' );
		}

		// Start the self-heal watchdog before the (synchronous) first batch runs,
		// so the scan can recover even if that very request dies mid-batch.
		if ( function_exists( 'seopress_broken_links_schedule_watchdog' ) ) {
			seopress_broken_links_schedule_watchdog();
		}

		if ( function_exists( 'seopress_broken_links_run_task_fn' ) ) {
			seopress_broken_links_run_task_fn( $offset, $new );
		}

		return new \WP_REST_Response( array( 'status' => 'started' ) );
	}

	/**
	 * Export broken links as CSV.
	 *
	 * @since 9.8.0
	 *
	 * @return \WP_REST_Response
	 */
	public function processExport() {
		$args = array(
			'post_type'      => 'seopress_bot',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query = new \WP_Query( $args );
		$rows  = array();

		$rows[] = array(
			__( 'URL', 'wp-seopress-pro' ),
			__( 'Status', 'wp-seopress-pro' ),
			__( 'Count', 'wp-seopress-pro' ),
			__( 'Content-Type', 'wp-seopress-pro' ),
			__( 'Anchor', 'wp-seopress-pro' ),
			__( 'Source URL', 'wp-seopress-pro' ),
			__( 'Source Title', 'wp-seopress-pro' ),
			__( 'Post Type', 'wp-seopress-pro' ),
			__( 'Date', 'wp-seopress-pro' ),
		);

		foreach ( $query->posts as $post ) {
			$rows[] = array(
				$post->post_title,
				get_post_meta( $post->ID, 'seopress_bot_status', true ),
				get_post_meta( $post->ID, 'seopress_bot_count', true ),
				get_post_meta( $post->ID, 'seopress_bot_type', true ),
				get_post_meta( $post->ID, 'seopress_bot_a_title', true ),
				get_post_meta( $post->ID, 'seopress_bot_source_url', true ),
				get_post_meta( $post->ID, 'seopress_bot_source_title', true ),
				get_post_meta( $post->ID, 'seopress_bot_cpt', true ),
				$post->post_date,
			);
		}

		return new \WP_REST_Response( $rows );
	}

	/**
	 * User-meta key for the persisted DataViews view state.
	 *
	 * @return string
	 */
	protected function getViewPrefsMetaKey() {
		return 'seopress_broken_links_view_prefs';
	}

	/**
	 * Whitelist of view keys persisted for the Broken Links listing.
	 *
	 * @return string[]
	 */
	protected function getViewPrefsAllowedKeys() {
		return array( 'perPage', 'fields', 'filters', 'sort', 'type' );
	}

	/**
	 * Format a seopress_bot post for API response.
	 *
	 * @since 9.8.0
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array
	 */
	private function formatPost( $post ) {
		return array(
			'id'          => $post->ID,
			'url'         => esc_html( $post->post_title ),
			'status'      => sanitize_text_field( get_post_meta( $post->ID, 'seopress_bot_status', true ) ),
			'type'        => sanitize_text_field( get_post_meta( $post->ID, 'seopress_bot_type', true ) ),
			'count'       => absint( get_post_meta( $post->ID, 'seopress_bot_count', true ) ),
			'sourceId'    => absint( get_post_meta( $post->ID, 'seopress_bot_source_id', true ) ),
			'sourceUrl'   => esc_url( get_post_meta( $post->ID, 'seopress_bot_source_url', true ) ),
			'sourceTitle' => sanitize_text_field( get_post_meta( $post->ID, 'seopress_bot_source_title', true ) ),
			'sourceCpt'   => sanitize_text_field( get_post_meta( $post->ID, 'seopress_bot_cpt', true ) ),
			'anchor'      => sanitize_text_field( get_post_meta( $post->ID, 'seopress_bot_a_title', true ) ),
			'dateCreated' => $post->post_date,
		);
	}
}
