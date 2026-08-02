<?php

namespace SEOPressPro\Actions\Api\PageSpeed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class GetPageSpeedReport implements ExecuteHooks {
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		register_rest_route(
			'seopress/v1',
			'/page-speed',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'args'                => array(
					'device' => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return in_array( $param, array( 'desktop', 'mobile' ) );
						},
					),
				),
				'permission_callback' => function ( $request ) {
					if ( ! current_user_can( seopress_capability( 'manage_options', 'bot' ) ) ) {
						return false;
					}

					return true;
				},
			)
		);
	}

	public function processGet( \WP_REST_Request $request ) {
		$device = $request->get_param( 'device' ) ?? 'mobile';

		if ( ! in_array( $device, array( 'desktop', 'mobile' ) ) ) {
			return new \WP_REST_Response( 'Invalid device' );
		}

		if ( $device === 'desktop' ) {
			$data = get_transient( 'seopress_results_page_speed_desktop' );
		} else {
			$data = get_transient( 'seopress_results_page_speed' );
		}

		if ( is_string( $data ) ) {
			$data = json_decode( $data );
		}

		if ( empty( $data ) ) {
			return new \WP_REST_Response( 'No data found' );
		}

		return new \WP_REST_Response( $data );
	}
}
