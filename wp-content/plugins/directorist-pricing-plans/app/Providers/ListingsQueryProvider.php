<?php

namespace DirectoristPricingPlan\App\Providers;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Models\UserPackage;

class ListingsQueryProvider implements Provider {
    public function boot() {
        // Remove the default featured query argument
        add_filter( 'directorist_query_arg_has_featured', [$this, 'handle_query_arg_has_featured'], 10, 2 );
        
        // Sort listings by featured and display priority
        add_filter( 'posts_fields', [$this, 'add_listing_display_priority_field'], 10, 2 );
        add_filter( 'posts_join', [$this, 'add_listing_display_priority_joins'], 10, 2 );
        add_filter( 'posts_groupby', [$this, 'add_listing_display_priority_groupby'], 10, 2 );
        add_filter( 'posts_orderby', [$this, 'add_listing_display_priority_orderby'], 1, 2 );
    }

    public function handle_query_arg_has_featured( bool $has_featured, array $params ) {
        if ( ! $this->is_frontend_request() ) {
            return $has_featured;
        }

        return false;
    }

    public function add_listing_display_priority_field( string $fields, $query ) {
        // Only modify directorist listing queries
        if ( ! $this->is_core_query( $query ) ) {
            return $fields;
        }

        $fields .= ', COALESCE( MAX( CAST( dpp_featured_meta.meta_value AS UNSIGNED ) ), 0 ) AS listing_featured';
        $fields .= ", {$this->get_listing_display_priority_field_sql()} AS listing_display_priority";
        
        return $fields;
    }

    public function add_listing_display_priority_joins( string $join, $query ) {
        // Only modify directorist listing queries
        if ( ! $this->is_core_query( $query ) ) {
            return $join;
        }

        global $wpdb;

        $packages_table = $wpdb->prefix . UserPackage::get_table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, Squiz.Strings.ConcatenationSpacing.PaddingFound -- Table names are from $wpdb/model metadata.
        $join .= " LEFT JOIN {$wpdb->postmeta} AS dpp_directory_meta"
            . " ON {$wpdb->posts}.ID = dpp_directory_meta.post_id"
            . " AND dpp_directory_meta.meta_key = '_directory_type'";

        $join .= " LEFT JOIN {$wpdb->postmeta} AS dpp_featured_meta"
            . " ON {$wpdb->posts}.ID = dpp_featured_meta.post_id"
            . " AND dpp_featured_meta.meta_key = '_featured'";

        $join .= " LEFT JOIN {$packages_table} AS dpp_directory_package"
            . " ON dpp_directory_package.user_id = {$wpdb->posts}.post_author"
            . " AND dpp_directory_package.directory_type_id = CAST( dpp_directory_meta.meta_value AS UNSIGNED )";

        if ( $this->has_migrated_legacy_plans() ) {
            $join .= " LEFT JOIN {$wpdb->postmeta} AS dpp_pricing_plan_meta"
                . " ON {$wpdb->posts}.ID = dpp_pricing_plan_meta.post_id"
                . " AND dpp_pricing_plan_meta.meta_key = '_plan_id'";

            $join .= " LEFT JOIN {$packages_table} AS dpp_plan_package"
                . " ON dpp_plan_package.user_id = {$wpdb->posts}.post_author"
                . " AND dpp_plan_package.plan_id = CAST( dpp_pricing_plan_meta.meta_value AS UNSIGNED )";
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, Squiz.Strings.ConcatenationSpacing.PaddingFound

        return $join;
    }

    public function add_listing_display_priority_groupby( string $groupby, $query ) {
        if ( ! $this->is_core_query( $query ) ) {
            return $groupby;
        }

        global $wpdb;

        $posts_id = "{$wpdb->posts}.ID";

        if ( empty( $groupby ) ) {
            return $posts_id;
        }

        if ( false !== strpos( str_replace( '`', '', $groupby ), $posts_id ) ) {
            return $groupby;
        }

        return "{$groupby}, {$posts_id}";
    }

    public function add_listing_display_priority_orderby( string $orderby, $query ) {
        if ( ! $this->is_core_query( $query ) ) {
            return $orderby;
        }

        $priority_orderby = ' listing_featured DESC, listing_display_priority DESC';
        
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

    private function get_listing_display_priority_field_sql(): string {
        if ( ! $this->has_migrated_legacy_plans() ) {
            return 'COALESCE( MAX( dpp_directory_package.listing_display_priority ), 0 )';
        }

        return 'COALESCE(
            MAX( dpp_plan_package.listing_display_priority ),
            MAX( dpp_directory_package.listing_display_priority ),
            0
        )';
    }

    private function has_migrated_legacy_plans(): bool {
        return ! empty( get_option( 'directorist_migration_plan_id_map', [] ) );
    }
}
