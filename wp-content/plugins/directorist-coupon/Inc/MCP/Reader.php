<?php
namespace DirectoristCoupon\MCP;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Validation;
defined( 'ABSPATH' ) || exit;

final class Reader {
    const USAGE = 'Matching user/code rows in directorist_orders, including every order status. Positive limits are per user; zero means unlimited. A quote reserves nothing.';

    public static function coupon( int $id ): array {
        $post = get_post( $id );
        Validation::check( $post && SWBDPC_POST_TYPE === $post->post_type, 'Coupon is unavailable.', 'not_found' );
        return self::record( \SWBDPCCouponService::configuration( $id ), $id, $post->post_status, self::version( $id ) );
    }
    public static function record( array $data, int $id, string $status, string $version = '' ): array {
        $data['amount'] = (string) $data['amount'];
        $data['per_user_limit'] = (int) $data['per_user_limit'];
        return [
            'id' => $id, 'status' => $status, 'version' => $version,
            'available' => 'publish' === $status && ( '' === $data['expiry'] || $data['expiry'] >= current_time( 'Y-m-d' ) ),
            'amount_unit' => 'percentage_dis' === $data['type'] ? 'percent' : 'currency', 'last_valid_day' => $data['expiry'],
        ] + $data;
    }
    public static function version( int $id ): string { return Canonical_Data::hash( self::state( $id ) ); }
    public static function state( int $id ): array {
        $post = get_post( $id );
        return [ 'post' => $post ? $post->to_array() : [], 'meta' => $id ? get_post_meta( $id ) : [] ];
    }
    public static function read( string $action, array $args ): array {
        if ( 'get-schema' === $action ) {
            if ( isset( $args['context'] ) ) { Checkout::quote( $args['context'] ); }
            return [
                'admin' => current_user_can( 'manage_options' ),
                'types' => [
                    [ 'value' => 'percentage_dis', 'semantics' => 'Whole-number percentage >0 and <=100 on any eligible native checkout; no plan restriction.' ],
                    [ 'value' => 'fixed_cart_dis', 'semantics' => 'Whole-number currency discount on the cart; capped at subtotal; no plan restriction.' ],
                    [ 'value' => 'fixed_product_dis', 'semantics' => 'Whole-number currency discount for explicit published native plan IDs; capped at subtotal.' ],
                ],
                'amount_precision' => 0, 'currency' => atbdp_get_payment_currency(), 'quote_rounding' => 'Native discount/tax arithmetic rounds to two decimals. Quotes use native currency formatting and reject currencies whose precision exceeds the native ledger.', 'expiry_semantics' => 'Site-local YYYY-MM-DD; the expiry date is the last valid day, inclusive. Empty means no expiry.',
                'usage_semantics' => self::USAGE,
                'code_policy' => 'Editor normalization replaces spaces with underscores and removes characters outside ASCII letters, digits, underscore and dot. Native database collation defines equality. All records, including trash, reserve codes. Used codes cannot be renamed.',
                'lifecycle' => 'Create as draft. Publish/draft/pending/private, trash and native restore require confirmed preview. Restore retains expiry and previews the native restoration status. Permanent deletion is excluded.',
                'configuration_fields' => current_user_can( 'manage_options' ) ? array_keys( \SWBDPCCouponService::META ) : [],
                'checkout_adapter' => Checkout::ready() ? 'Native one-time plan checkout; own authenticated buyer, or admin with edit-user capability for support; recurring, trial, existing-order and alternate pricing adapters excluded. Native checkout revalidates under the shared write lock.' : 'Unavailable: active native Pricing Plans quote service required.',
                'core_order_coupon_adapter' => false, 'rate_limit' => RateLimit::policy(),
            ];
        }
        if ( 'validate-coupon' === $action ) { return Checkout::validate( $args['code'], $args['context'] ); }
        if ( 'list-coupons' === $action ) { return self::listing( $args ); }
        if ( 'get-usage' === $action ) { return self::usage( $args ); }
        return [ 'coupon' => self::coupon( $args['coupon_id'] ) ];
    }
    private static function listing( array $args ): array {
        $query = [ 'post_type' => SWBDPC_POST_TYPE, 'post_status' => isset( $args['status'] ) ? [ $args['status'] ] : [ 'publish', 'draft', 'pending', 'private', 'trash' ], 'fields' => 'ids', 'posts_per_page' => 200, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ];
        $meta = [];
        if ( isset( $args['type'] ) ) { $meta[] = [ 'key' => 'swbdpc_coupon_type', 'value' => $args['type'] ]; }
        if ( ! empty( $args['search'] ) ) {
            $meta[] = [ 'relation' => 'OR', [ 'key' => 'swbdpc_coupon_code', 'value' => $args['search'], 'compare' => 'LIKE' ], [ 'key' => 'swbdpc_coupon_descrip', 'value' => $args['search'], 'compare' => 'LIKE' ] ];
        }
        if ( isset( $args['expiry'] ) ) {
            $date = current_time( 'Y-m-d' );
            if ( 'expired' === $args['expiry'] ) {
                $meta[] = [ 'relation' => 'AND', [ 'key' => 'swbdpc_coupon_expiry', 'value' => '', 'compare' => '!=' ], [ 'key' => 'swbdpc_coupon_expiry', 'value' => $date, 'compare' => '<' ] ];
            } else {
                $part = [ 'relation' => 'OR', [ 'key' => 'swbdpc_coupon_expiry', 'value' => '' ], [ 'key' => 'swbdpc_coupon_expiry', 'compare' => 'NOT EXISTS' ] ];
                if ( 'valid' === $args['expiry'] ) { $part[] = [ 'key' => 'swbdpc_coupon_expiry', 'value' => $date, 'compare' => '>=' ]; }
                $meta[] = $part;
            }
        }
        if ( $meta ) { $query['meta_query'] = $meta; }
        $page = $args['page'] ?? 1; $per_page = $args['per_page'] ?? 20;
        $start = ( $page - 1 ) * $per_page; $total = 0; $rows = [];
        // Count and slice exactly the same object-authorized, stably ordered set.
        for ( $batch = 1; ; ++$batch ) {
            $query['paged'] = $batch;
            $ids = ( new \WP_Query( $query ) )->posts;
            foreach ( $ids as $id ) {
                if ( ! Integration::object_permitted( (int) $id, 'read' ) ) { continue; }
                if ( $total >= $start && count( $rows ) < $per_page ) { $rows[] = self::coupon( (int) $id ); }
                ++$total;
            }
            if ( count( $ids ) < 200 ) { break; }
        }
        return [ 'coupons' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'total_pages' => (int) ceil( $total / $per_page ) ];
    }
    private static function usage( array $args ): array {
        global $wpdb;
        $coupon = self::coupon( $args['coupon_id'] );
        $where = $wpdb->prepare( 'coupon_code=%s', $coupon['code'] );
        if ( isset( $args['customer_id'] ) ) {
            Validation::check( (bool) get_userdata( $args['customer_id'] ), 'Customer is unavailable.', 'not_found' );
            $where .= $wpdb->prepare( ' AND user_id=%d', $args['customer_id'] );
        }
        $count = \SWBDPCCouponService::usage( $coupon['code'], $args['customer_id'] ?? 0 );
        $page = $args['page'] ?? 1; $per_page = $args['per_page'] ?? 20;
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}directorist_orders WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ) );
        return [ 'coupon_id' => $args['coupon_id'], 'count' => $count, 'semantics' => self::USAGE, 'orders' => array_map( static function ( $id ) { return [ 'order_id' => (int) $id ]; }, $rows ), 'page' => $page, 'per_page' => $per_page, 'total_pages' => (int) ceil( $count / $per_page ) ];
    }
}
