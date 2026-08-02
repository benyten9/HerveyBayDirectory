<?php

namespace SEOPressPro\Actions\Api\Metas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class GenerateByAI implements ExecuteHooks {
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}


	public function register() {

		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/generate-metas-by-ai',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processPost' ),
				'args'                => array(
					'id'   => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
					'lang' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_string( $param );
						},
					),
				),
				'permission_callback' => function ( $request ) {
					$post_id = $request['id'];
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					if ( function_exists( 'seopress_ai_generation_check' ) ) {
						return seopress_ai_generation_check( $post_id, 'edit_post', 'metabox' );
					}
					return true;
				},

			)
		);
	}

	public function processPost( \WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		$lang = $request->get_param( 'lang' );

		if ( empty( $lang ) ) {

			// Language
			if ( function_exists( 'seopress_normalized_locale' ) ) {
				$language = seopress_normalized_locale( get_locale() );
			} else {
				$language = get_locale();
			}

			$lang = $language !== null ? $language : 'en_US';
		}

		$data = seopress_pro_get_service( 'Completions' )->generateTitlesDesc( $id, '', $lang );

		return new \WP_REST_Response( $data );
	}
}
