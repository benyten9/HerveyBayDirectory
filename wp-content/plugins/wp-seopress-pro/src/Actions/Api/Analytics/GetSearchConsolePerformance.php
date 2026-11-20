<?php

namespace SEOPressPro\Actions\Api\Analytics;

defined( 'ABSPATH' ) || exit;

use SEOPress\Core\Hooks\ExecuteHooks;

class GetSearchConsolePerformance implements ExecuteHooks {
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function can_read() {
		return current_user_can( seopress_capability( 'manage_options', 'dashboard' ) );
	}

	public function register() {
		register_rest_route( 'seopress/v1', '/search-console/performance', array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'can_read' ),
			'callback'            => array( $this, 'get_data' ),
		) );
	}

	public function get_data() {
		if ( ! $this->can_read() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to view these statistics.', 'wp-seopress-pro' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return rest_ensure_response( seopress_pro_get_service( 'SearchConsolePerformance' )->get_data() );
	}
}
