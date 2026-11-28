<?php
/**
 * Administrator-only source selection and safe plugin-scoped usage reporting.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

use DirectoristAIPro\Storage\Database;

/** Public chat never receives account reporting or administrative policy writes. */
final class Api {

	/**
	 * Register the closed source/reporting endpoints.
	 *
	 * @param Database $db Runtime database.
	 */
	public static function register( Database $db ): void {
		register_rest_route(
			'directorist-ai/v1',
			'/ai/settings',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static fn(): array => Settings::status(),
				),
				array(
					'methods'             => 'PUT',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static function ( \WP_REST_Request $request ) {
						$body = json_decode( $request->get_body() );
						if ( ! $body instanceof \stdClass ) {
							return new \WP_Error( 'invalid_input', 'AI settings must be an object.', array( 'status' => 400 ) );
						}
						$result = Settings::save( json_decode( $request->get_body(), true ) );
						return is_wp_error( $result ) ? $result : Settings::status();
					},
				),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/ai/usage',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'callback'            => static function ( \WP_REST_Request $request ) use ( $db ) {
					if ( in_array( $request->get_param( 'summary' ), array( '1', 1 ), true ) ) {
						try {
							return array( 'summary' => UsageSummary::read( $db, $request->get_query_params() ) );
						} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
							return new \WP_Error( 'invalid_input', $error->getMessage(), array( 'status' => 400 ) );
						} catch ( \Throwable $error ) {
							return new \WP_Error( 'storage_unavailable', 'Usage reporting is temporarily unavailable.', array( 'status' => 503 ) );
						}
					}
					$days = $request->get_param( 'days' ) ?? '7';
					$source = $request->get_param( 'source' ) ?? 'all';
					$feature = $request->get_param( 'feature' ) ?? 'all';
					if ( ( ! is_int( $days ) && ( ! is_string( $days ) || ! preg_match( '/^[0-9]{1,2}$/D', $days ) ) ) || (int) $days < 1 || (int) $days > 90 || ! in_array( $source, array( 'all', 'wordpress', 'managed' ), true ) || ! in_array( $feature, array( 'all', 'listing', 'builder', 'visitor', 'admin' ), true ) ) {
						return new \WP_Error( 'invalid_input', 'Choose a valid usage window, source and feature.', array( 'status' => 400 ) );
					}
					try {
						return UsageReport::read( $db, (int) $days, $source, $feature );
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'storage_unavailable', 'Usage reporting is temporarily unavailable.', array( 'status' => 503 ) );
					}
				},
			)
		);
		foreach ( array(
			'refresh'       => 'POST',
			'usage'         => 'GET',
			'usage-summary' => 'GET',
			'license'       => 'POST',
		) as $action => $method ) {
			register_rest_route(
				'directorist-ai/v1',
				'/ai/managed/' . $action,
				array(
					'methods'             => $method,
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static function ( \WP_REST_Request $request ) use ( $action ) {
						try {
							$body = $request->get_json_params() ?? array();
							if ( ! is_array( $body ) ) {
								return new \WP_Error( 'invalid_input', 'Request must be an object.', array( 'status' => 400 ) ); }
							if ( 'license' === $action ) {
								if ( array_diff( array_keys( $body ), array( 'action', 'settings' ) ) || ! is_string( $body['action'] ?? null ) || ( isset( $body['settings'] ) && ! is_array( $body['settings'] ) ) ) {
									return new \WP_Error( 'invalid_input', 'Choose a license action and object settings.', array( 'status' => 400 ) ); }
								return LicenseConnection::action( $body['action'] ?? '', $body['settings'] ?? array() ); }
							if ( in_array( $action, array( 'usage', 'usage-summary' ), true ) ) {
								$filters = $request->get_query_params();
								unset( $filters['rest_route'], $filters['_locale'] );
								return 'usage' === $action ? ManagedService::usage( $filters ) : ManagedService::usage_summary( $filters );
							}
							return ManagedService::$action();
						} catch ( \DirectoristAIPro\Orchestration\ModelException $error ) {
							return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 'invalid_input' === $error->error_code() ? 400 : 503 ) ); }
					},
				)
			);
		}
	}
}
