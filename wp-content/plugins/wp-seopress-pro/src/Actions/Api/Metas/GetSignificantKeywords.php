<?php

namespace SEOPressPro\Actions\Api\Metas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class GetSignificantKeywords implements ExecuteHooks {

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
			'/posts/(?P<id>\d+)/significant-keywords',
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
	}

	/**
	 * @since 5.1.0
	 */
	public function processGet( \WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_REST_Response( array( 'suggestions' => array() ) );
		}

		$content = seopress_pro_get_service( 'SignificantKeywords' )->getFullContentByPost( $post );

		$keywords = seopress_pro_get_service( 'SignificantKeywords' )->retrieveSignificantKeywords( $content );
		$data     = seopress_pro_get_service( 'SignificantKeywords' )->computeKeywords( $keywords, $content, $id );

		return new \WP_REST_Response( array( 'suggestions' => $data ) );
	}
}
