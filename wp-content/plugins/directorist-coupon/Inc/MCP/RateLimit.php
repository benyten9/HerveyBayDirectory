<?php
namespace DirectoristCoupon\MCP;
use Directorist\MCP\Support\Validation;
defined( 'ABSPATH' ) || exit;

/** Private operational accounting only; no coupon codes, quotes, orders or user data. */
final class RateLimit {
    public static function policy(): array {
        $policy = apply_filters( 'directorist_coupon_validation_rate_limit', [ 'requests' => 20, 'window_seconds' => 60 ] );
        return [ 'requests' => max( 1, min( 1000, (int) ( $policy['requests'] ?? 20 ) ) ), 'window_seconds' => max( 1, min( 86400, (int) ( $policy['window_seconds'] ?? 60 ) ) ), 'scope' => 'authenticated user per site; local server' ];
    }
    private static function check( bool $allowed, string $message, string $code ): void {
        if ( $allowed ) { return; }
        if ( class_exists( Validation::class ) ) { Validation::fail( $code, $message, [ 'status' => 'rate_limited' === $code ? 429 : 503 ] ); }
        throw new \RuntimeException( $message, 'rate_limited' === $code ? 429 : 503 );
    }
    public static function consume(): void {
        $policy = self::policy();
        // Flock works without a persistent WP object cache and is atomic across workers.
        // It deliberately uses no SQL writes inside the pure-read product guard.
        $path = get_temp_dir() . 'directorist-coupon-rate-' . hash( 'sha256', ABSPATH . ':' . get_current_blog_id() . ':' . get_current_user_id() ) . '.json';
        $handle = fopen( $path, 'c+' );
        self::check( false !== $handle, 'Validation rate-limit storage is unavailable.', 'dependency_unavailable' );
        try {
            self::check( flock( $handle, LOCK_EX ), 'Validation rate-limit storage is busy.', 'operation_in_progress' );
            $now = time();
            $data = json_decode( stream_get_contents( $handle ), true );
            if ( ! is_array( $data ) || (int) ( $data['reset'] ?? 0 ) <= $now ) {
                $data = [ 'reset' => $now + $policy['window_seconds'], 'count' => 0 ];
            }
            self::check( (int) $data['count'] < $policy['requests'], 'Too many coupon checks. Retry after the validation window.', 'rate_limited' );
            ++$data['count'];
            rewind( $handle );
            self::check( ftruncate( $handle, 0 ) && false !== fwrite( $handle, wp_json_encode( $data ) ) && fflush( $handle ), 'Validation rate-limit storage is unavailable.', 'dependency_unavailable' );
        } finally {
            flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }
}
