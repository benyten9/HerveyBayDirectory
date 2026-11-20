<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type;
use DirectoristPricingPlan\App\Enums\Plan\Interval;
use DirectoristPricingPlan\App\Enums\UserPackage\Status;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Models\UserPackage;
use DirectoristPricingPlan\App\Services\ExpiredListingRenewalService;

final class Reader {
    public static function hash( $value ): string {
        return \Directorist\MCP\Support\Canonical_Data::hash( $value );
    }

    public static function active( \stdClass $package ): bool {
        return directorist_is_package_active( $package ) && ( empty( $package->current_period_end ) || '0000-00-00 00:00:00' === $package->current_period_end || directorist_to_timestamp( $package->current_period_end ) > directorist_now()->getTimestamp() );
    }

    public static function catalog_eligible( \stdClass $plan ): bool {
        if ( ! $plan->is_published || $plan->is_hidden_from_plans_list || ! directorist_plan_has_listing_quota( $plan ) || ! get_term_by( 'id', (int) $plan->directory_type_id, ATBDP_DIRECTORY_TYPE ) ) {
            return false;
        }
        if ( ! directorist_allow_multiple_plans_per_directory_type() ) {
            $packages = directorist_user_package_repository()->get_active_packages_for_directory( get_current_user_id(), (int) $plan->directory_type_id );
            if ( $packages && ! in_array( (int) $plan->id, array_map( 'intval', array_column( $packages, 'plan_id' ) ), true ) ) {
                return false;
            }
        }
        return true;
    }

    public static function features( \stdClass $plan ): array {
        $features = directorist_pricing_plan_repository()->plan_feature_repository->get( $plan );
        $out = [];
        foreach ( $features as $feature ) {
            $data = (array) ( $feature->data ?? [] );
            $item = [ 'key' => (string) $feature->key, 'is_enabled' => (bool) $feature->is_enabled, 'is_show_in_pricing_table' => (bool) $feature->is_show_in_pricing_table ];
            if ( array_key_exists( 'is_unlimited', $data ) ) {
                // Native unlimited feature limits may be stored as an empty string.
                $item['data'] = [ 'is_unlimited' => (bool) $data['is_unlimited'], 'limit' => (int) ( $data['limit'] ?? 0 ) ];
            }
            $out[] = $item;
        }
        Access::check( count( $out ) <= 100, 'This directory exceeds the supported feature count.', 'unsupported_operation' );
        usort( $out, static function ( $a, $b ) { return strcmp( $a['key'], $b['key'] ); } );
        return $out;
    }

    public static function configuration( \stdClass $plan ): array {
        $out = [];
        foreach ( Schema::configuration()['properties'] as $key => $definition ) {
            if ( 'features' === $key ) {
                $out[ $key ] = self::features( $plan );
            } else {
                $value = $plan->$key ?? PlanWriter::defaults()[ $key ];
                switch ( $definition['type'] ) {
                    case 'boolean': $value = (bool) $value; break;
                    case 'integer': $value = (int) $value; break;
                    case 'number': $value = (float) $value; break;
                    case 'string': $value = (string) $value; break;
                }
                $out[ $key ] = $value;
            }
        }
        return $out;
    }

    public static function plan( \stdClass $plan, ?int $listing_id = null ): array {
        $configuration = self::configuration( $plan );
        $eligible = self::catalog_eligible( $plan );
        $assignment = false;
        $reasons = $eligible ? [] : [ 'Plan is not currently available in your eligible catalog.' ];
        if ( $listing_id ) {
            $listing = Access::listing( $listing_id );
            $package = directorist_user_package_repository()->get_package_by_plan( (int) $listing->post_author, (int) $plan->id );
            if ( (int) $plan->directory_type_id !== directorist_get_listings_directory_type( $listing_id ) ) {
                $reasons[] = 'Plan belongs to a different directory.';
            } elseif ( $package ) {
                $detail = self::package( $package );
                $assignment = $eligible && $detail['active'] && 0 !== $detail['listings']['remaining'] && ( ! directorist_is_listing_featured( $listing_id ) || 0 !== $detail['featured']['remaining'] ) && (int) get_post_meta( $listing_id, directorist_plan_key(), true ) !== (int) $plan->id && Type::PACKAGE === $plan->type;
                $native_status = directorist_get_directory_meta( (int) $plan->directory_type_id, 'edit_listing_status' );
                $assignment = $assignment && ( 'publish' === $native_status || ! empty( $plan->is_allowed_unlimited_listings ) );
                if ( ! $assignment ) {
                    $reasons[] = 'No compatible remaining purchased allowance, or plan already assigned.';
                }
            } else {
                $reasons[] = 'Checkout is required to obtain an entitlement.';
            }
        }
        if ( ! Access::admin() ) {
            $configuration = array_diff_key( $configuration, array_flip( [ 'sort_order', 'is_published', 'is_hidden_from_plans_list', 'is_fallback', 'fallback_plan_id', 'listing_display_priority' ] ) );
        }
        return [ 'id' => (int) $plan->id, 'configuration' => $configuration, 'currency' => atbdp_get_payment_currency(), 'precision' => 2, 'version' => self::hash( $configuration ), 'entitlements_use_live_plan' => true, 'purchase_eligible' => $eligible, 'assignment_eligible' => $assignment, 'reasons' => $reasons ];
    }

    public static function package( \stdClass $package ): array {
        $active = self::active( $package );
        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );
        $reasons = $active ? [] : [ 'Package is inactive, cancelled or expired; checkout may be required.' ];
        $out = [
            'id' => (int) $package->id, 'user_id' => (int) $package->user_id, 'plan_id' => (int) $package->plan_id,
            'directory_type_id' => (int) $package->directory_type_id, 'status' => (string) $package->status,
            'started_at' => (string) $package->started_at, 'current_period_end' => (string) $package->current_period_end,
            'cancelled_at' => (string) $package->cancelled_at, 'cancel_at_period_end' => Status::CANCELLED_AT_PERIOD_END === $package->status,
            'active' => $active,
        ];
        if ( $plan ) {
            $usage = directorist_package_usage( ! empty( $package->is_legacy ) );
            $out['plan'] = self::plan( $plan );
            $out['listings'] = array_map( 'intval', $usage->get_total_uses( (int) $package->user_id, $plan ) );
            $out['featured'] = array_map( 'intval', $usage->get_featured_uses( (int) $package->user_id, $plan ) );
            if ( 0 === $out['listings']['remaining'] ) {
                $reasons[] = 'No listing quota remaining.';
            }
        } else {
            $reasons[] = 'Historical plan is unavailable.';
        }
        $out['order_ids'] = [];
        if ( ! empty( $package->last_order_id ) ) {
            $order = directorist_order_repository()->get_by_id( (int) $package->last_order_id );
            if ( $order && ( Access::admin() || (int) $order->user_id === get_current_user_id() ) ) {
                $out['order_ids'][] = (int) $package->last_order_id;
            }
        }
        $out['reasons'] = $reasons;
        $out['version'] = self::hash( [ (array) $package, $out ] );
        return $out;
    }

    /** Aggregate distinct users in bounded batches only when an admin requests these counts. */
    private static function plan_user_counts_for_ids( array $ids ): array {
        global $wpdb;
        Access::check( Access::admin(), 'Administrator access is required.', 'forbidden' );
        $counts = array_fill_keys( $ids, [ 'total' => 0, 'active' => 0, 'inactive' => 0 ] );
        $now = directorist_now()->format( 'Y-m-d H:i:s' );
        foreach ( array_chunk( $ids, 100 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.plan_id, COUNT(DISTINCT u.ID) AS total_users,
                    COUNT(DISTINCT CASE WHEN p.status IN (%s, %s)
                        AND (p.current_period_end IS NULL OR p.current_period_end = %s OR p.current_period_end > %s)
                        THEN u.ID END) AS active_users
                    FROM {$wpdb->prefix}directorist_user_packages p
                    INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
                    WHERE p.plan_id IN ({$placeholders}) GROUP BY p.plan_id",
                array_merge( [ Status::ACTIVE, Status::CANCELLED_AT_PERIOD_END, '0000-00-00 00:00:00', $now ], $chunk )
            ), ARRAY_A );
            Access::check( '' === $wpdb->last_error && is_array( $rows ), 'Plan user counts are unavailable.', 'source_invalid' );
            foreach ( $rows as $row ) {
                Access::check( isset( $row['plan_id'], $row['total_users'], $row['active_users'] ), 'Plan user counts are unavailable.', 'source_invalid' );
                $total = (int) $row['total_users'];
                $active = (int) $row['active_users'];
                $counts[ (int) $row['plan_id'] ] = [ 'total' => $total, 'active' => $active, 'inactive' => $total - $active ];
            }
        }
        return $counts;
    }

    private static function plan_user_counts( int $plan_id ): array {
        return self::plan_user_counts_for_ids( [ $plan_id ] )[ $plan_id ];
    }

    public static function read( string $action, array $args ): array {
        switch ( $action ) {
            case 'get-schema':
                $directory = (int) ( $args['directory_type_id'] ?? 0 );
                if ( $directory ) {
                    Access::check( (bool) get_term_by( 'id', $directory, ATBDP_DIRECTORY_TYPE ), 'Directory is unavailable.', 'not_found' );
                }
                return [ 'plan_types' => Type::all(), 'statuses' => Status::all(), 'intervals' => Interval::all(), 'currency' => atbdp_get_payment_currency(), 'precision' => 2, 'unlimited_usage_value' => -1, 'zero_limit_is_empty' => true, 'defaults' => array_diff_key( PlanWriter::defaults(), array_flip( Access::admin() ? [ 'title' ] : [ 'title', 'sort_order', 'is_published', 'is_hidden_from_plans_list', 'is_fallback', 'fallback_plan_id', 'listing_display_priority' ] ) ), 'features' => $directory ? self::features( (object) [ 'id' => 0, 'directory_type_id' => $directory ] ) : [], 'operations' => Integration::operations(), 'admin_operations' => Access::admin(), 'payment_dispatch' => false, 'plan_filters' => PlanFilters::discovery() ];
            case 'get-plan':
                $plan = self::plan( Access::plan( (int) $args['plan_id'] ), isset( $args['listing_id'] ) ? (int) $args['listing_id'] : null );
                if ( Access::admin() ) {
                    $plan['user_counts'] = self::plan_user_counts( (int) $args['plan_id'] );
                }
                return [ 'plan' => $plan ];
            case 'get-package': return [ 'package' => self::package( Access::package( (int) $args['package_id'] ) ) ];
            case 'get-listing-entitlement': return [ 'entitlement' => Entitlements::describe( (int) $args['listing_id'] ) ];
        }
        $filters = 'list-plans' === $action ? PlanFilters::prepare( $args['filters'] ?? [] ) : [];
        $page = (int) ( $args['page'] ?? 1 );
        $size = (int) ( $args['per_page'] ?? 20 );
        $query = 'list-plans' === $action ? Plan::query() : UserPackage::query();
        if ( isset( $args['directory_type_id'] ) ) {
            $query->where( 'directory_type_id', (int) $args['directory_type_id'] );
        }
        if ( 'list-packages' === $action ) {
            if ( ! Access::admin() || 'admin' !== ( $args['scope'] ?? 'own' ) || isset( $args['user_id'] ) ) {
                $query->where( 'user_id', (int) ( $args['user_id'] ?? get_current_user_id() ) );
            }
            if ( isset( $args['status'] ) ) {
                $query->where( 'status', $args['status'] );
            }
            $count = clone $query;
            $rows = $query->order_by( 'id', 'asc' )->pagination( $page, $size );
            return [ 'items' => array_map( [ self::class, 'package' ], $rows ), 'total' => (int) $count->count(), 'page' => $page, 'per_page' => $size ];
        }
        $admin = Access::admin() && 'admin' === ( $args['scope'] ?? '' );
        if ( ! $admin ) {
            $query->where( 'is_published', 1 )->where( 'is_hidden_from_plans_list', 0 );
        }
        if ( isset( $args['published'] ) ) {
            $query->where( 'is_published', (int) $args['published'] );
        }
        // Filter before slicing so totals and pages describe exactly the permitted scope.
        $rows = $query->order_by( 'id', 'asc' )->get();
        $rows = array_values( array_filter( $rows, static function ( $plan ) use ( $admin, $args ) {
            return ( $admin || self::catalog_eligible( $plan ) ) && ( ! isset( $args['active'] ) || (bool) $args['active'] === (bool) $plan->is_published );
        } ) );
        if ( $filters ) {
            $plans = [];
            $counts_filters = array_values( array_filter( $filters, static function ( $filter ) { return 0 === strpos( $filter['field'], 'user_counts.' ); } ) );
            $other_filters = array_values( array_filter( $filters, static function ( $filter ) { return 0 !== strpos( $filter['field'], 'user_counts.' ); } ) );
            foreach ( $rows as $row ) {
                if ( ! PlanFilters::native_matches( $row, $other_filters ) ) { continue; }
                $plan = self::plan( $row );
                if ( PlanFilters::matches( $plan, $other_filters ) ) { $plans[] = $plan; }
            }
            if ( $counts_filters ) {
                $counts = self::plan_user_counts_for_ids( array_column( $plans, 'id' ) );
                foreach ( $plans as &$plan ) { $plan['user_counts'] = $counts[ $plan['id'] ]; }
                unset( $plan );
                $plans = array_values( array_filter( $plans, static function ( $plan ) use ( $counts_filters ) { return PlanFilters::matches( $plan, $counts_filters ); } ) );
            }
            return [ 'items' => array_slice( $plans, ( $page - 1 ) * $size, $size ), 'total' => count( $plans ), 'page' => $page, 'per_page' => $size ];
        }
        return [ 'items' => array_map( [ self::class, 'plan' ], array_slice( $rows, ( $page - 1 ) * $size, $size ) ), 'total' => count( $rows ), 'page' => $page, 'per_page' => $size ];
    }
}
