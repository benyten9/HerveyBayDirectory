<?php
namespace ElementorPro\License\Data\Endpoints;

use ElementorPro\Core\Data\Endpoints\Base;
use ElementorPro\Core\Data\Interfaces\Endpoint;
use ElementorPro\License\Admin;
use ElementorPro\License\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Clear_Options extends Base implements Endpoint {

	const LICENSE_V1_DATA_OPTION_NAME = '_elementor_pro_license_data';

	const LICENSE_V1_DATA_FALLBACK_OPTION_NAME = '_elementor_pro_license_data_fallback';

	const CONNECT_SITE_KEY_OPTION_NAME = 'elementor_connect_site_key';

	const CONNECT_COMMON_DATA_OPTION_NAME = 'elementor_connect_common_data';

	public function get_name(): string {
		return 'clear-options';
	}

	public function get_route(): string {
		return 'clear-options';
	}

	protected function register() {
		register_rest_route(
			$this->controller->get_namespace(),
			'/' . $this->controller->get_name() . '/' . $this->get_route(),
			[
				[
					'methods' => 'POST',
					'callback' => [ $this, 'clear' ],
					'permission_callback' => [ $this, 'permission_callback' ],
					'args' => $this->get_args(),
				],
			]
		);
	}

	public function permission_callback( $request ) {
		return current_user_can( 'manage_options' );
	}

	public function clear( $request ) {
		$cleared = [];

		foreach ( $this->get_clear_map() as $group => $callback ) {
			if ( ! $this->is_requested( $request, $group ) ) {
				continue;
			}

			$callback();
			$cleared[] = $group;
		}

		return new \WP_REST_Response(
			[
				'cleared' => $cleared,
			],
			200
		);
	}

	private function get_args(): array {
		$boolean_arg = [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		];

		$args = [];

		foreach ( array_keys( $this->get_clear_map() ) as $group ) {
			$args[ $group ] = $boolean_arg;
		}

		return $args;
	}

	private function get_clear_map(): array {
		return [
			'license_lock' => function () {
				delete_option( API::REQUEST_LOCK_OPTION_NAME );
			},
			'license_v2_cache' => function () {
				delete_option( Admin::LICENSE_DATA_OPTION_NAME );
				delete_option( Admin::LICENSE_DATA_FALLBACK_OPTION_NAME );
			},
			'license_v1_cache' => function () {
				delete_option( self::LICENSE_V1_DATA_OPTION_NAME );
				delete_option( self::LICENSE_V1_DATA_FALLBACK_OPTION_NAME );
			},
			'license_key' => function () {
				delete_option( Admin::LICENSE_KEY_OPTION_NAME );
			},
			'connect_site_key' => function () {
				delete_option( self::CONNECT_SITE_KEY_OPTION_NAME );
			},
			'connect_common_data' => function () {
				$this->clear_connect_common_data();
			},
		];
	}

	private function is_requested( $request, string $group ): bool {
		return rest_sanitize_boolean( $request->get_param( $group ) );
	}

	private function clear_connect_common_data() {
		$users = get_users( [
			'fields' => 'ID',
			'number' => -1,
		] );

		foreach ( $users as $user_id ) {
			delete_user_option( (int) $user_id, self::CONNECT_COMMON_DATA_OPTION_NAME );
		}
	}
}
