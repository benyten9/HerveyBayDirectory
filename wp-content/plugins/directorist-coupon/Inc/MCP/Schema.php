<?php
namespace DirectoristCoupon\MCP;
defined( 'ABSPATH' ) || exit;

final class Schema {
    public static function text( int $max = 256 ): array { return [ 'type' => 'string', 'maxLength' => $max ]; }
    public static function integer( int $min = 0, int $max = 2147483647 ): array { return [ 'type' => 'integer', 'minimum' => $min, 'maximum' => $max ]; }
    public static function object( array $properties, ?array $required = null ): array {
        return [ 'type' => 'object', 'properties' => $properties, 'required' => $required ?? array_keys( $properties ), 'additionalProperties' => false ];
    }
    public static function items( array $item, int $max = 100 ): array { return [ 'type' => 'array', 'items' => $item, 'maxItems' => $max ]; }
    public static function config(): array {
        return [
            'code' => self::text( 128 ) + [ 'minLength' => 1 ], 'description' => self::text( 4000 ),
            'type' => [ 'type' => 'string', 'enum' => \SWBDPCCouponService::TYPES ],
            'amount' => self::integer( 1, 99999999 ), 'eligible_plan_ids' => self::items( self::integer( 1 ) ),
            'expiry' => self::text( 10 ), 'per_user_limit' => self::integer( 0, 1000000 ),
        ];
    }
    public static function coupon(): array {
        $fields = self::config();
        // Read legacy values honestly, including an unsupported fractional stored amount.
        $fields['amount'] = self::text( 64 );
        $fields['type'] = self::text( 80 );
        return self::object( [ 'id' => self::integer(), 'status' => self::text( 20 ), 'version' => self::text( 64 ), 'available' => [ 'type' => 'boolean' ], 'amount_unit' => self::text( 20 ), 'last_valid_day' => self::text( 10 ) ] + $fields );
    }
    public static function context(): array {
        return self::object( [ 'plan_id' => self::integer( 1 ), 'listing_id' => self::integer( 1 ), 'customer_id' => self::integer( 1 ) ], [ 'plan_id' ] );
    }
    public static function pagination(): array { return [ 'page' => self::integer( 1, 100000 ), 'per_page' => self::integer( 1, 100 ) ]; }
    public static function input( string $action ): array {
        $id = [ 'coupon_id' => self::integer( 1 ) ];
        switch ( $action ) {
            case 'get-schema': return self::object( [ 'context' => self::context() ], [] );
            case 'validate-coupon': return self::object( [ 'code' => self::text( 128 ) + [ 'minLength' => 1 ], 'context' => self::context() ] );
            case 'list-coupons': return self::object( self::pagination() + [ 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash' ] ], 'type' => self::config()['type'], 'search' => self::text( 128 ), 'expiry' => [ 'type' => 'string', 'enum' => [ 'expired', 'valid', 'none' ] ] ], [] );
            case 'get-usage': return self::object( $id + self::pagination() + [ 'customer_id' => self::integer( 1 ) ], [ 'coupon_id' ] );
            case 'create-coupon': return self::object( self::config() );
            case 'update-coupon': return self::object( $id + [ 'changes' => self::object( self::config(), [] ) + [ 'minProperties' => 1 ] ] );
            case 'change-status': return self::object( $id + [ 'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private' ] ] ] );
            default: return self::object( $id );
        }
    }
    public static function rate(): array { return self::object( [ 'requests' => self::integer( 1 ), 'window_seconds' => self::integer( 1 ), 'scope' => self::text() ] ); }
    public static function output( string $action ): array {
        if ( 'get-schema' === $action ) {
            return self::object( [
                'admin' => [ 'type' => 'boolean' ], 'types' => self::items( self::object( [ 'value' => self::text(), 'semantics' => self::text( 1000 ) ] ), 3 ),
                'amount_precision' => self::integer(), 'currency' => self::text( 4 ), 'quote_rounding' => self::text( 1000 ), 'expiry_semantics' => self::text( 1000 ), 'usage_semantics' => self::text( 1000 ),
                'code_policy' => self::text( 1000 ), 'lifecycle' => self::text( 1000 ), 'configuration_fields' => self::items( self::text(), 7 ),
                'checkout_adapter' => self::text( 1000 ), 'core_order_coupon_adapter' => [ 'type' => 'boolean' ], 'rate_limit' => self::rate(),
            ] );
        }
        if ( 'validate-coupon' === $action ) {
            return self::object( [ 'eligible' => [ 'type' => 'boolean' ], 'reason' => self::text(), 'subtotal' => self::text( 64 ), 'discount' => self::text( 64 ), 'tax' => self::text( 64 ), 'payable' => self::text( 64 ), 'currency' => self::text( 4 ), 'valid_until' => self::text( 40 ), 'requires_revalidation' => [ 'type' => 'boolean' ], 'reserves_usage' => [ 'type' => 'boolean' ], 'rate_limit' => self::rate() ] );
        }
        if ( 'get-usage' === $action ) {
            return self::object( [ 'coupon_id' => self::integer( 1 ), 'count' => self::integer(), 'semantics' => self::text( 1000 ), 'orders' => self::items( self::object( [ 'order_id' => self::integer( 1 ) ] ) ), 'page' => self::integer( 1 ), 'per_page' => self::integer( 1 ), 'total_pages' => self::integer() ] );
        }
        if ( 'list-coupons' === $action ) {
            return self::object( [ 'coupons' => self::items( self::coupon() ), 'total' => self::integer(), 'page' => self::integer( 1 ), 'per_page' => self::integer( 1 ), 'total_pages' => self::integer() ] );
        }
        return self::object( [ 'coupon' => self::coupon() ] );
    }
    public static function preview(): array {
        return self::object( [ 'before' => self::items( self::coupon(), 1 ), 'after' => self::coupon(), 'changed_fields' => self::items( self::text(), 20 ), 'effects' => self::text( 1000 ) ] );
    }
}
