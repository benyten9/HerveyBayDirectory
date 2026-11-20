<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

use Directorist\MCP\Support\Validation;

final class Access {
    public static function admin(): bool {
        return current_user_can( 'manage_options' );
    }

    public static function check( bool $condition, string $message, string $code = 'invalid_input' ): void {
        Validation::check( $condition, $message, $code );
    }

    public static function listing( int $id ): \WP_Post {
        $post = get_post( $id );
        self::check( $post && ATBDP_POST_TYPE === $post->post_type && current_user_can( 'edit_post', $id ) && ( self::admin() || (int) $post->post_author === get_current_user_id() ), 'Listing is unavailable.', 'not_found' );
        self::check( ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ), 'This listing cannot use a plan.', 'dependency_blocked' );
        return $post;
    }

    public static function package( int $id ): \stdClass {
        $package = directorist_user_package_repository()->get_by_id( $id );
        self::check( $package && ( self::admin() || (int) $package->user_id === get_current_user_id() ), 'Package is unavailable.', 'not_found' );
        return $package;
    }

    public static function plan( int $id ): \stdClass {
        $plan = directorist_get_pricing_plan_by_id( $id );
        self::check( $plan && ( self::admin() || Reader::catalog_eligible( $plan ) ), 'Plan is unavailable.', 'not_found' );
        return $plan;
    }

    public static function permitted( string $action, array $args, int $user_id ): bool {
        if ( ! $user_id || $user_id !== get_current_user_id() || ! current_user_can( 'read' ) ) {
            return false;
        }
        if ( in_array( $action, [ 'create-plan', 'update-plan', 'delete-plan' ], true ) && ! self::admin() ) {
            return false;
        }
        if ( ( isset( $args['user_id'] ) && (int) $args['user_id'] !== $user_id || 'admin' === ( $args['scope'] ?? '' ) || 'admin_assignment' === ( $args['mode'] ?? '' ) ) && ! self::admin() ) {
            return false;
        }
        if ( 'list-plans' === $action ) {
            PlanFilters::prepare( (array) ( $args['filters'] ?? [] ) );
        }
        if ( isset( $args['listing_id'] ) ) {
            self::listing( (int) $args['listing_id'] );
        }
        if ( isset( $args['package_id'] ) ) {
            self::package( (int) $args['package_id'] );
        }
        // A completed deletion can replay after the plan is gone. Preparation checks existence.
        if ( isset( $args['plan_id'] ) && 'delete-plan' !== $action ) {
            self::plan( (int) $args['plan_id'] );
        }
        return true;
    }
}
