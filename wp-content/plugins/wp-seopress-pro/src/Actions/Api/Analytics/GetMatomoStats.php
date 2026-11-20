<?php

namespace SEOPressPro\Actions\Api\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooks;

class GetMatomoStats implements ExecuteHooks {
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		register_rest_route(
			'seopress/v1',
			'/matomo',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'processGet' ),
				'permission_callback' => function ( $request ) {
					if ( true === seopress_advanced_security_matomo_widget_check() ) {
						return true;
					}

					return false;
				},
			)
		);
	}

	public function processGet( \WP_REST_Request $request ) {
		$data = get_transient( 'seopress_results_matomo' );

		// A failed sync caches a marker for a few minutes, not the message: the
		// message is translated here, for the person asking.
		$failed = function_exists( 'seopress_matomo_cache_is_failure' ) && seopress_matomo_cache_is_failure( $data );

		if ( empty( $data ) || $failed ) {
			// An empty answer used to be the only thing this route could say, so a
			// refused token and a site with no visitors were indistinguishable.
			$error = function_exists( 'seopress_matomo_get_sync_error' ) ? seopress_matomo_get_sync_error() : null;

			if ( null !== $error ) {
				return new \WP_REST_Response( array( 'error' => $error ) );
			}

			return new \WP_REST_Response( 'No data found' );
		}

		return new \WP_REST_Response( $data );
	}
}
