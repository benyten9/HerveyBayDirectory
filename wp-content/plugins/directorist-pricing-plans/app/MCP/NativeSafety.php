<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

/** Cooperating native routes acquire the same lock before reading quota or plan state. */
final class NativeSafety {
    public static function lock(): void {
        if ( class_exists( '\Directorist\MCP\Safety\Write_Lock' ) ) {
            \Directorist\MCP\Safety\Write_Lock::native_lock();
        }
    }

    public static function rest( $result, array $handler, \WP_REST_Request $request ) {
        if ( ! in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true ) && 0 === strpos( $request->get_route(), '/directorist-pricing-plans/' ) ) {
            self::lock();
        }
        return $result;
    }
}
