<?php

namespace DirectoristPricingPlan\App\Providers;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Models\Plan;

class ListingsQueryProvider implements Provider {
    private ?array $listing_display_priorities = null;

    public function boot() {
        if ( ! directorist_is_listing_display_priority_enabled() ) {
            return;
        }

        // Remove the default featured query argument
        add_filter( 'directorist_query_arg_has_featured', [$this, 'handle_query_arg_has_featured'], 10, 2 );
        
        // Sort listings by featured and display priority
        add_filter( 'posts_orderby', [$this, 'add_listing_display_priority_orderby'], 1, 2 );
    }

    public function handle_query_arg_has_featured( bool $has_featured, array $params ) {
        if ( ! directorist_is_listing_display_priority_enabled() || ! $this->is_frontend_request() ) {
            return $has_featured;
        }

        return false;
    }

    public function add_listing_display_priority_orderby( string $orderby, $query ) {
        if ( ! directorist_is_listing_display_priority_enabled() || ! $this->is_core_query( $query ) ) {
            return $orderby;
        }

        $priority_orderby = $this->get_listing_priority_orderby_sql() . ' DESC';
        
        if ( empty( $orderby ) ) {
            $orderby = $priority_orderby;
        } else {
            $orderby = "{$priority_orderby}, {$orderby}";
        }
        
        return $orderby;
    }

    private function is_core_query( $query ): bool {
        if ( ! $this->is_frontend_request() ) {
            return false;
        }

        return $query->get( 'post_type' ) === ATBDP_POST_TYPE;
    }

    private function is_frontend_request(): bool {
        if ( wp_doing_ajax() ) {
            return true;
        }

        return ! is_admin();
    }

    private function get_listing_priority_orderby_sql(): string {
        global $wpdb;

        $priorities    = $this->get_listing_display_priorities();
        $cases         = [];
        $plan_priority = '0';

        foreach ( $priorities as $plan_id => $priority ) {
            $cases[] = sprintf( 'WHEN %d THEN %d', $plan_id, $priority );
        }

        if ( ! empty( $cases ) ) {
            $plan_priority = sprintf( 'CASE CAST( dpp_order_meta.meta_value AS UNSIGNED ) %s ELSE 0 END', implode( ' ', $cases ) );
        }

        $priority_values = array_merge( [0], array_values( $priorities ) );
        $featured_weight = max( $priority_values ) - min( $priority_values ) + 1;
        $featured_key    = '_featured';
        $plan_key        = directorist_plan_key();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The interpolated values are integer-only CASE fragments generated above.
        $orderby = $wpdb->prepare(
            "COALESCE( (
                SELECT
                    COALESCE( MAX( CASE WHEN dpp_order_meta.meta_key = %s THEN CAST( dpp_order_meta.meta_value AS UNSIGNED ) END ), 0 ) * {$featured_weight}
                    + COALESCE( MAX( CASE WHEN dpp_order_meta.meta_key = %s THEN {$plan_priority} END ), 0 )
                FROM {$wpdb->postmeta} AS dpp_order_meta
                WHERE dpp_order_meta.post_id = {$wpdb->posts}.ID
                    AND dpp_order_meta.meta_key IN ( %s, %s )
            ), 0 )",
            $featured_key,
            $plan_key,
            $featured_key,
            $plan_key
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $orderby;
    }

    private function get_listing_display_priorities(): array {
        if ( null !== $this->listing_display_priorities ) {
            return $this->listing_display_priorities;
        }

        global $wpdb;

        $plans_table = $wpdb->prefix . Plan::get_table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from wpdb/model metadata.
        $plans = $wpdb->get_results( "SELECT id, listing_display_priority FROM {$plans_table}" );

        $this->listing_display_priorities = [];

        foreach ( $plans as $plan ) {
            $plan_id = absint( $plan->id );

            if ( ! $plan_id ) {
                continue;
            }

            $this->listing_display_priorities[ $plan_id ] = (int) $plan->listing_display_priority;
        }

        return $this->listing_display_priorities;
    }
}
