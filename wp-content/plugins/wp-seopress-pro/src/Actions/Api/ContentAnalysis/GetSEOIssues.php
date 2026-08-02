<?php

namespace SEOPressPro\Actions\Api\ContentAnalysis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class GetSEOIssues implements ExecuteHooks {

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * @since 8.2.0
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/seo-issues/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetByID' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
				),
				'permission_callback' => function () {
					if ( current_user_can( seopress_capability( 'manage_options', 'titles_metas' ) ) ) {
						return true;
					}
					return false;
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/seo-issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGetAll' ),
				'args'                => array(
					'id'       => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_bool( $param );
						},
					),
					'ignore'   => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
					'type'     => array(
						'validate_callback' => function ( $param, $request, $key ) {
							$types = array(
								'all_canonical',
								'json_schemas',
								'old_post',
								'permalink',
								'headings',
								'title',
								'description',
								'social',
								'robots',
								'img_alt',
								'nofollow_links',
								'outbound_links',
								'internal_links',
								'content_depth',
								'heading_hierarchy',
								'content_media',
								'content_structure',
							);

							if ( in_array( $param, $types ) ) {
								return $param;
							}

							return false;
						},
					),
					'priority' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							$priorities = array(
								'high',
								'medium',
								'low',
								'good',
							);

							if ( in_array( $param, $priorities ) ) {
								return $param;
							}

							return false;
						},
					),
					'name'     => array(
						'validate_callback' => function ( $param, $request, $key ) {
							$names = array(
								'json_schemas_duplicated',
								'json_schemas_not_found',
								'old_post',
								'keywords_permalink',
								'headings_not_found',
								'headings_h1_duplicated',
								'headings_h1_not_found',
								'headings_h1_without_target_kw',
								'headings_h2_without_target_kw',
								'headings_h3_without_target_kw',
								'title_without_target_kw',
								'title_too_long',
								'title_not_custom',
								'description_without_target_kw',
								'description_too_long',
								'description_not_custom',
								'og_title_duplicated',
								'og_title_empty',
								'og_title_missing',
								'og_desc_duplicated',
								'og_desc_empty',
								'og_desc_missing',
								'og_img_empty',
								'og_img_missing',
								'og_url_duplicated',
								'og_url_empty',
								'og_url_missing',
								'og_sitename_duplicated',
								'og_sitename_empty',
								'og_sitename_missing',
								'x_title_duplicated',
								'x_title_empty',
								'x_title_missing',
								'x_desc_duplicated',
								'x_desc_empty',
								'x_desc_missing',
								'x_img_empty',
								'x_img_missing',
								'meta_robots_duplicated',
								'meta_robots_noindex',
								'meta_robots_nofollow',
								'meta_robots_noimageindex',
								'meta_robots_nosnippet',
								'img_alt_missing',
								'img_alt_no_media',
								'nofollow_links_too_many',
								'outbound_links_missing',
								'internal_links_missing',
								'canonical_duplicated',
								'canonical_missing',
								'content_too_thin',
								'heading_hierarchy_skipped',
								'heading_hierarchy_too_few',
								'content_media_missing',
								'content_wall_of_text',
							);

							if ( in_array( $param, $names ) ) {
								return $param;
							}

							return false;
						},
					),
				),
				'permission_callback' => function () {
					if ( current_user_can( seopress_capability( 'manage_options', 'titles_metas' ) ) ) {
						return true;
					}
					return false;
				},
			)
		);
	}

	/**
	 * @since 8.2.0
	 *
	 * @param \WP_REST_Request $request
	 */
	public function processGetByID( \WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		if ( empty( $id ) || ! $id ) {
			return new \WP_Error( 'missing_parameters', 'Need a post ID' );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'seopress_seo_issues';

		$sql = $wpdb->prepare( "SELECT * FROM $table_name WHERE post_id = %d", absint( $id ) );

		$data = $wpdb->get_results( $sql );

		wp_send_json_success( $data );
		return;
	}

	/**
	 * @since 8.2.0
	 *
	 * @param \WP_REST_Request $request
	 */
	public function processGetAll( \WP_REST_Request $request ) {
		$id       = $request->get_param( 'id' );
		$type     = $request->get_param( 'type' );
		$name     = $request->get_param( 'name' );
		$priority = $request->get_param( 'priority' );
		$ignore   = $request->get_param( 'ignore' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'seopress_seo_issues';

		$sql = "SELECT * FROM $table_name";

		$where_clauses = array();

		if ( ! empty( $id ) ) {
			$where_clauses[] = $wpdb->prepare( 'post_id = %d', $id );
		}

		if ( ! empty( $type ) ) {
			$where_clauses[] = $wpdb->prepare( 'issue_type = %s', $type );
		}

		if ( ! empty( $name ) ) {
			$where_clauses[] = $wpdb->prepare( 'issue_name = %s', $name );
		}

		if ( ! empty( $priority ) ) {
			$where_clauses[] = $wpdb->prepare( 'issue_priority = %s', $priority );
		}

		if ( ! empty( $ignore ) ) {
			$where_clauses[] = $wpdb->prepare( 'issue_ignore = %d', $ignore );
		}

		if ( ! empty( $where_clauses ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_clauses );
		}

		$data = $wpdb->get_results( $sql );

		wp_send_json_success( $data );
		return;
	}
}
