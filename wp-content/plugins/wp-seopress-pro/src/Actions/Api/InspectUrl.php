<?php

namespace SEOPressPro\Actions\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class InspectUrl implements ExecuteHooks {
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/inspect',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
				),
				'permission_callback' => function ( $request ) {
					$post_id = $request['id'];
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/inspect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processPost' ),
				'permission_callback' => function ( $request ) {
					$post_id = $request['id'];
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	public function processGet( \WP_REST_Request $request ) {
		$postId = $request->get_param( 'id' );

		$data = get_post_meta( $postId, '_seopress_gsc_inspect_url_data', true );

		if ( is_string( $data ) ) {
			$data = json_decode( $data );
		}

		// Get Google API Key
		$options        = get_option( 'seopress_instant_indexing_option_name' );
		$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';

		return new \WP_REST_Response(
			array(
				'google_api_key_is_empty' => empty( $google_api_key ),
				'data'                    => $data,
				'performance'             => $this->get_saved_performance( $postId ),
			)
		);
	}

	/**
	 * Read the last synchronized metrics without making a Search Console call.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Null when no usable metrics have been saved.
	 */
	private function get_saved_performance( $post_id ) {
		$metrics  = array();
		$has_data = false;
		foreach ( array( 'clicks', 'impressions', 'ctr', 'position' ) as $key ) {
			$value = get_post_meta( $post_id, '_seopress_search_console_analysis_' . $key, true );
			$valid = is_numeric( $value ) && is_finite( (float) $value ) && 0 <= (float) $value;
			if ( 'ctr' === $key && $valid && 1 < (float) $value ) {
				$valid = false;
			}
			$metrics[ $key ] = $valid ? (float) $value : null;
			$has_data       = $has_data || $valid;
		}
		return $has_data ? $metrics : null;
	}

	public function processPost( \WP_REST_Request $request ) {
		$postId = $request->get_param( 'id' );

		$data = seopress_pro_get_service( 'InspectUrlGoogle' )->handle( $postId );

		if ( is_string( $data ) ) {
			$data = json_decode( $data );
		}

		return new \WP_REST_Response( array( 'data' => $data ) );
	}
}
