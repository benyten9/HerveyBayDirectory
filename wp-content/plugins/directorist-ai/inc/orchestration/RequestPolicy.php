<?php
// phpcs:disable WordPress.Files.FileName -- PSR-4 class follows the existing runtime naming convention.
/** Server-owned task capacities. Unknown model capacity retains the existing defaults. */
namespace DirectoristAIPro\Orchestration;

final class RequestPolicy {
	public static function limits( array $route, string $profile ): array {
		$defaults = array(
			'input'    => 64000,
			'output'   => 'builder' === $profile ? 8192 : 4096,
			'verified' => false,
		);
		if ( 'managed' !== ( $route['source'] ?? null ) || ! class_exists( '\DirectoristAIPro\AI\ManagedService' ) ) {
			return $defaults; }
		$status = \DirectoristAIPro\AI\ManagedService::status();
		$task   = 'builder' === $profile ? 'layout' : ( 'listing' === ( $route['feature'] ?? null ) ? 'listing' : 'chat' );
		$key    = 'directorist_ai_capacity_v2_' . md5( \DirectoristAIPro\AI\ManagedService::fingerprint() );
		$cached = function_exists( 'get_transient' ) ? get_transient( $key . '_grants' ) : false;
		$grant  = ! empty( $status['stale'] ) ? null : ( $status['task_limits'][ $task ] ?? ( is_array( $cached ) ? ( $cached[ $task ] ?? null ) : null ) );
		if ( ! is_array( $grant ) && function_exists( 'get_transient' ) && false === get_transient( $key ) ) {
			// Throttle an unavailable metadata endpoint briefly. A successful
			// report has its own five-minute expiry and must not inherit an
			// earlier failure's retry delay during a continuing generation.
			set_transient( $key, 1, 30 );
			try {
				$status = \DirectoristAIPro\AI\ManagedService::refresh(); } catch ( \Throwable $error ) {
				/* An unavailable report never grants larger capacity. */ }
		}
		if ( empty( $status['stale'] ) && ! empty( $status['task_limits'] ) && function_exists( 'set_transient' ) ) {
			// Balance invalidation must not erase still-current authenticated capacities.
			// Expire against the original report time, never extend by reading it.
			$lifetime = min( 300, max( 1, (int) strtotime( $status['checked_at'] ?? '' ) + 300 - time() ) );
			set_transient( $key . '_grants', $status['task_limits'], $lifetime );
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( $key ); }
			$grant = $status['task_limits'][ $task ] ?? null;
		}
		if ( ! is_array( $grant ) || ! empty( $status['stale'] ) ) {
			return $defaults; }
		return array(
			'input'    => min( 262144, max( 1, $grant['max_input_units'] ) ),
			'output'   => min( 'builder' === $profile ? 16384 : 4096, $grant['max_output_tokens'] ),
			'verified' => true === $grant['context_verified'],
		);
	}
}
