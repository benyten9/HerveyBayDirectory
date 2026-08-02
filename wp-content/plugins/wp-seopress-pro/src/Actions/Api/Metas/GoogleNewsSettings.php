<?php

namespace SEOPressPro\Actions\Api\Metas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class GoogleNewsSettings implements ExecuteHooks {

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function register() {
		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/google-news-settings',
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
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
			)
		);

		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/google-news-settings',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'processPut' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
				),
				'permission_callback' => function ( $request ) {
					if ( function_exists( 'seopress_metabox_role_is_blocked' ) && seopress_metabox_role_is_blocked( 'GLOBAL' ) ) {
						return false;
					}
					$post_id = $request['id'];
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * @since 5.1.0
	 */
	public function processPut( \WP_REST_Request $request ) {
		$id     = $request->get_param( 'id' );
		$params = $request->get_params();

		try {
			if ( isset( $params['_seopress_news_disabled'] ) && $params['_seopress_news_disabled'] === 'yes' ) {
				update_post_meta( $id, '_seopress_news_disabled', 'yes' );
			} else {

				delete_post_meta( $id, '_seopress_news_disabled' );
			}

			return new \WP_REST_Response(
				array(
					'code' => 'success',
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array(
					'code'         => 'error',
					'code_message' => 'execution_failed',
				),
				403
			);
		}
	}

	/**
	 * @since 5.1.0
	 */
	public function processGet( \WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		$data = array(
			array(
				'key'         => '_seopress_news_disabled',
				'type'        => 'checkbox',
				'placeholder' => '',
				'use_default' => '',
				'default'     => '',
				'value'       => ! empty( get_post_meta( $id, '_seopress_news_disabled', true ) ),
				'label'       => __( ' Exclude this post from Google News Sitemap?', 'wp-seopress-pro' ),
				'visible'     => true,
				'description' => '',
			),
		);

		return new \WP_REST_Response( $data );
	}
}
