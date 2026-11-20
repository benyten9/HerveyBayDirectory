<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type;
use DirectoristPricingPlan\App\Enums\Plan\Interval;
use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\App\Enums\Plan\TaxType;
use DirectoristPricingPlan\App\Enums\Plan\TrialInterval;
use DirectoristPricingPlan\App\Enums\UserPackage\Status;

/** Closed public contracts. Native identifiers and unlimited flags stay explicit. */
final class Schema {
    public static function object( array $properties, array $required = [] ): array {
        return [ 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ];
    }

    public static function text( int $max = 255 ): array {
        return [ 'type' => 'string', 'maxLength' => $max ];
    }

    public static function integer( int $min = 0 ): array {
        return [ 'type' => 'integer', 'minimum' => $min ];
    }

    public static function choices( array $values ): array {
        return [ 'type' => 'string', 'enum' => $values ];
    }

    public static function list( array $item ): array {
        return [ 'type' => 'array', 'items' => $item, 'maxItems' => 100 ];
    }

    public static function feature(): array {
        return self::object( [
            'key' => self::text( 150 ),
            'is_enabled' => [ 'type' => 'boolean' ],
            'is_show_in_pricing_table' => [ 'type' => 'boolean' ],
            'data' => self::object( [ 'is_unlimited' => [ 'type' => 'boolean' ], 'limit' => self::integer() ] ),
        ], [ 'key', 'is_enabled', 'is_show_in_pricing_table' ] );
    }

    public static function configuration(): array {
        $p = [
            'title' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ],
            'description' => self::text( 1000 ),
            'directory_type_id' => self::integer( 1 ),
            'type' => self::choices( Type::all() ),
            'fee_type' => self::choices( FeeType::all() ),
            'price' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 99999999.99 ],
            'tax_type' => self::choices( TaxType::all() ),
            'tax_rate' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 99999999.99 ],
            'interval_type' => self::choices( Interval::all() ),
            'trial_interval_type' => self::choices( TrialInterval::all() ),
            'features' => self::list( self::feature() ),
        ];
        foreach ( [ 'is_taxable', 'is_subscription_enabled', 'is_trial_enabled', 'is_published', 'is_hidden_from_plans_list', 'is_marked_as_recommended', 'is_fallback', 'is_featured', 'is_allowed_unlimited_listings', 'is_allowed_unlimited_featured_listings' ] as $key ) {
            $p[ $key ] = [ 'type' => 'boolean' ];
        }
        $p['directory_type_id']['description'] = 'Choose the directory when creating a plan. Locked on existing plans, matching the admin editor; an unchanged value may be supplied on update.';
        $p['type']['description'] = 'Choose the plan type when creating a plan. Locked on existing plans, matching the admin editor; an unchanged value may be supplied on update.';
        $p['is_published']['description'] = 'Publish switch in Plan Status: true shows Published; false shows Draft.';
        $p['is_hidden_from_plans_list']['description'] = 'Hide from all plans switch in Plan Status. Hides from public view while retaining existing packages.';
        $p['is_marked_as_recommended']['description'] = 'Mark as Recommended checkbox in Plan Status. Highlights the plan in the frontend.';
        foreach ( [ 'allowed_listings', 'allowed_featured_listings', 'sort_order', 'fallback_plan_id' ] as $key ) {
            $p[ $key ] = self::integer();
        }
        $p['interval_count'] = self::integer();
        $p['trial_interval_count'] = self::integer();
        foreach ( [ 'listing_display_priority' ] as $key ) {
            $p[ $key ] = self::integer( 1 );
        }
        return self::object( $p );
    }

    public static function plan(): array {
        return self::object( [
            'id' => self::integer( 1 ), 'configuration' => self::configuration(),
            'currency' => self::text( 8 ), 'precision' => self::integer(), 'version' => self::text( 64 ),
            'entitlements_use_live_plan' => [ 'type' => 'boolean' ],
            'purchase_eligible' => [ 'type' => 'boolean' ], 'assignment_eligible' => [ 'type' => 'boolean' ],
            'reasons' => self::list( self::text( 500 ) ),
        ], [ 'id', 'configuration', 'currency', 'precision', 'version', 'entitlements_use_live_plan' ] );
    }

    public static function user_counts(): array {
        return self::object( [
            'total' => self::integer() + [ 'description' => 'Distinct existing users with any package history for this plan.' ],
            'active' => self::integer() + [ 'description' => 'Distinct users with at least one active, unexpired package, including cancellation at period end while still valid.' ],
            'inactive' => self::integer() + [ 'description' => 'Users with package history but no active, unexpired package. A user is counted in only one of active or inactive.' ],
        ], [ 'total', 'active', 'inactive' ] ) + [ 'description' => 'Administrator-only user counts across all packages for this plan, independent of pagination. Deleted user accounts are excluded.' ];
    }

    public static function usage(): array {
        return self::object( [ 'allowed' => self::integer( -1 ), 'used' => self::integer(), 'remaining' => self::integer( -1 ) ], [ 'allowed', 'used', 'remaining' ] );
    }

    public static function package(): array {
        return self::object( [
            'id' => self::integer( 1 ), 'user_id' => self::integer( 1 ), 'plan_id' => self::integer( 1 ),
            'directory_type_id' => self::integer( 1 ), 'status' => self::choices( Status::all() ),
            'started_at' => self::text( 30 ), 'current_period_end' => self::text( 30 ), 'cancelled_at' => self::text( 30 ),
            'cancel_at_period_end' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean' ],
            'plan' => self::plan(), 'listings' => self::usage(), 'featured' => self::usage(),
            'order_ids' => self::list( self::integer( 1 ) ), 'reasons' => self::list( self::text( 500 ) ),
            'version' => self::text( 64 ),
        ], [ 'id', 'user_id', 'plan_id', 'status', 'active', 'reasons', 'version' ] );
    }

    public static function entitlement(): array {
        return self::object( [
            'listing_id' => self::integer( 1 ), 'plan_id' => self::integer(), 'package_id' => self::integer(),
            'directory_type_id' => self::integer( 1 ), 'expiration' => self::text( 30 ),
            'compatible_package_ids' => self::list( self::integer( 1 ) ), 'reasons' => self::list( self::text( 500 ) ),
            'renewable' => [ 'type' => 'boolean' ], 'checkout_required' => [ 'type' => 'boolean' ],
        ] );
    }

    public static function output( string $action ): array {
        if ( 'get-schema' === $action ) {
            return self::object( [
                'plan_types' => self::list( self::choices( Type::all() ) ),
                'statuses' => self::list( self::choices( Status::all() ) ),
                'intervals' => self::list( self::choices( Interval::all() ) ),
                'currency' => self::text( 8 ), 'precision' => self::integer(),
                'unlimited_usage_value' => self::integer( -1 ), 'zero_limit_is_empty' => [ 'type' => 'boolean' ],
                'defaults' => self::configuration(), 'features' => self::list( self::feature() ),
                'operations' => self::list( self::object( [ 'action' => self::text( 40 ), 'available' => [ 'type' => 'boolean' ], 'reason' => self::text( 500 ) ] ) ),
                'admin_operations' => [ 'type' => 'boolean' ], 'payment_dispatch' => [ 'type' => 'boolean' ],
                'plan_filters' => self::list( self::object( [ 'field' => self::text( 100 ), 'type' => self::choices( [ 'string', 'integer', 'number', 'boolean', 'array' ] ), 'operators' => self::list( self::text( 20 ) ), 'admin_only' => [ 'type' => 'boolean' ], 'feature_key_required' => [ 'type' => 'boolean' ] ], [ 'field', 'type', 'operators', 'admin_only', 'feature_key_required' ] ) ),
            ] );
        }
        if ( 'list-plans' === $action || 'list-packages' === $action ) {
            $item = 'list-plans' === $action ? self::plan() : self::package();
            if ( 'list-plans' === $action ) { $item['properties']['user_counts'] = self::user_counts(); }
            return self::object( [ 'items' => self::list( $item ), 'total' => self::integer(), 'page' => self::integer( 1 ), 'per_page' => self::integer( 1 ) ] );
        }
        if ( 'get-plan' === $action ) {
            $plan = self::plan();
            $plan['properties']['user_counts'] = self::user_counts();
            return self::object( [ 'plan' => $plan ], [ 'plan' ] );
        }
        if ( 'get-package' === $action ) {
            return self::object( [ 'package' => self::package() ], [ 'package' ] );
        }
        if ( 'get-listing-entitlement' === $action ) {
            return self::object( [ 'entitlement' => self::entitlement() ], [ 'entitlement' ] );
        }
        if ( 'delete-plan' === $action ) {
            return self::object( [ 'plan_id' => self::integer( 1 ), 'deleted' => [ 'type' => 'boolean' ] ], [ 'plan_id', 'deleted' ] );
        }
        if ( in_array( $action, [ 'create-plan', 'update-plan' ], true ) ) {
            return self::object( [ 'plan' => self::plan() ], [ 'plan' ] );
        }
        return self::object( [
            'listing_id' => self::integer( 1 ), 'package_id' => self::integer(), 'order_id' => self::integer(),
            'changed' => [ 'type' => 'boolean' ], 'renewed' => [ 'type' => 'boolean' ],
            'checkout_required' => [ 'type' => 'boolean' ], 'checkout_url' => self::text( 2000 ),
            'expiration' => self::text( 30 ),
        ], [ 'listing_id', 'checkout_required' ] );
    }

    public static function input( string $action ): array {
        $id = self::integer( 1 );
        $pagination = [ 'page' => $id, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ] ];
        $directory = [ 'directory_type_id' => $id ];
        switch ( $action ) {
            case 'get-schema': return self::object( $directory );
            case 'list-plans': return self::object( $directory + $pagination + [ 'scope' => self::choices( [ 'eligible', 'public', 'admin' ] ), 'published' => [ 'type' => 'boolean' ], 'active' => [ 'type' => 'boolean', 'description' => 'Compatibility alias for the Published filter; not user activity.' ], 'filters' => PlanFilters::schema() ] );
            case 'get-plan': return self::object( [ 'plan_id' => $id, 'listing_id' => $id ], [ 'plan_id' ] );
            case 'create-plan':
                $s = self::configuration();
                $s['required'] = [ 'title', 'directory_type_id', 'type' ];
                return $s;
            case 'update-plan': return self::object( [ 'plan_id' => $id, 'changes' => self::configuration() ], [ 'plan_id', 'changes' ] );
            case 'delete-plan': return self::object( [ 'plan_id' => $id ], [ 'plan_id' ] );
            case 'list-packages': return self::object( $directory + $pagination + [ 'scope' => self::choices( [ 'own', 'admin' ] ), 'user_id' => $id, 'status' => self::choices( Status::all() ) ] );
            case 'get-package': return self::object( [ 'package_id' => $id ], [ 'package_id' ] );
            case 'get-listing-entitlement': return self::object( [ 'listing_id' => $id ], [ 'listing_id' ] );
            case 'assign-listing-plan': return self::object( [ 'listing_id' => $id, 'plan_id' => $id, 'package_id' => $id, 'mode' => self::choices( [ 'use_owned_package', 'admin_assignment' ] ) ], [ 'listing_id', 'mode' ] );
            case 'renew-listing': return self::object( [ 'listing_id' => $id, 'package_id' => $id ], [ 'listing_id', 'package_id' ] );
        }
        return self::object( [] );
    }

    public static function preview( string $action ): array {
        if ( 'delete-plan' === $action ) {
            return self::object( [
                'plan_id' => self::integer( 1 ), 'before' => self::configuration(),
                'permanent' => [ 'type' => 'boolean' ], 'deleted_features' => self::integer(),
                'deleted_app_configurations' => self::integer(),
            ], [ 'plan_id', 'before', 'permanent', 'deleted_features', 'deleted_app_configurations' ] );
        }
        if ( in_array( $action, [ 'create-plan', 'update-plan' ], true ) ) {
            return self::object( [ 'before' => self::configuration(), 'after' => self::configuration(), 'affected_packages' => self::integer(), 'entitlements_use_live_plan' => [ 'type' => 'boolean' ] ] );
        }
        return self::object( [
            'listing_id' => self::integer( 1 ), 'beneficiary_user_id' => self::integer( 1 ),
            'plan_id' => self::integer( 1 ), 'package_id' => self::integer(),
            'creates_package' => [ 'type' => 'boolean' ], 'creates_paid_order' => [ 'type' => 'boolean' ],
            'grants_entitlement' => [ 'type' => 'boolean' ], 'checkout_required' => [ 'type' => 'boolean' ],
            'subtotal' => [ 'type' => 'number' ], 'payment_dispatch' => [ 'type' => 'boolean' ],
            'tax_amount' => [ 'type' => 'number' ], 'interval_type' => self::choices( Interval::all() ), 'interval_count' => self::integer(),
            'expiration_source' => self::choices( [ 'package_period_end', 'native_plan_duration' ] ),
            'amount' => [ 'type' => 'number' ], 'currency' => self::text( 8 ), 'precision' => self::integer(),
            'featured' => [ 'type' => 'boolean' ], 'expiration' => self::text( 30 ),
            'resulting_status' => self::text( 30 ),
        ] );
    }
}
