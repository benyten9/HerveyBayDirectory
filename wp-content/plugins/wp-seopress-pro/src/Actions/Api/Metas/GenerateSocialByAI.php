<?php

namespace SEOPressPro\Actions\Api\Metas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;
use SEOPress\Helpers\VersionCompatibility;

/**
 * This class is used to generate social meta tags by AI.
 *
 * @since 9.4.0
 *
 * @package SEOPressPro\Actions\Api\Metas
 * @subpackage GenerateSocialByAI
 */
class GenerateSocialByAI implements ExecuteHooks {
	/**
	 * Hooks the register method.
	 *
	 * @since 9.4.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}


	/**
	 * Register the REST route for generating social meta tags by AI.
	 *
	 * @since 9.4.0
	 *
	 * @return void
	 */
	public function register() {

		register_rest_route(
			'seopress/v1',
			'/posts/(?P<id>\d+)/generate-social-metas-by-ai',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'processPost' ),
				'args'                => array(
					'id'        => array(
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
							return is_numeric( $param );
						},
					),
					'lang'      => array(
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
							return is_string( $param );
						},
					),
					'platform'  => array(
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
							return in_array( $param, array( 'facebook', 'twitter' ), true );
						},
					),
					'meta_type' => array(
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore
							return in_array( $param, array( 'title', 'desc' ), true );
						},
					),
				),
				'permission_callback' => function ( $request ) {
					$post_id = $request['id'];
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					if ( function_exists( 'seopress_ai_generation_check' ) ) {
						return seopress_ai_generation_check( $post_id, 'edit_post', 'social' );
					}
					return true;
				},

			)
		);
	}

	/**
	 * Process the POST request for generating social meta tags by AI.
	 *
	 * @since 9.4.0
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function processPost( \WP_REST_Request $request ) {
		// Version compatibility check - both FREE and PRO must be 9.4+.
		$version_error = VersionCompatibility::getRestErrorResponse( '9.4', '9.4' );
		if ( $version_error ) {
			return $version_error;
		}

		$id        = $request->get_param( 'id' );
		$lang      = $request->get_param( 'lang' );
		$platform  = $request->get_param( 'platform' );
		$meta_type = $request->get_param( 'meta_type' );

		if ( empty( $lang ) ) {

			// Get the language.
			if ( function_exists( 'seopress_normalized_locale' ) ) {
				$language = seopress_normalized_locale( get_locale() );
			} else {
				$language = get_locale();
			}

			$lang = null !== $language ? $language : 'en_US';
		}

		$data = seopress_pro_get_service( 'Completions' )->generateSocialMetas( $id, $meta_type, $platform, $lang );

		return new \WP_REST_Response( $data );
	}
}
