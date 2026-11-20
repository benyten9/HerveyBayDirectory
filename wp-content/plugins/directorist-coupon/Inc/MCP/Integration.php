<?php
namespace DirectoristCoupon\MCP;
use Directorist\MCP\Support\Validation;
use Directorist\MCP\Support\Canonical_Data;
defined( 'ABSPATH' ) || exit;

final class Integration {
    private static $touched = [];
    const ACTIONS = [ 'get-schema', 'list-coupons', 'get-coupon', 'create-coupon', 'update-coupon', 'change-status', 'trash-coupon', 'restore-coupon', 'validate-coupon', 'get-usage' ];
    const WRITES = [ 'create-coupon', 'update-coupon', 'change-status', 'trash-coupon', 'restore-coupon' ];

    public static function boot(): void {
        add_action( 'directorist_mcp_register', [ self::class, 'register' ] );
        add_filter( 'wp_insert_post_data', [ '\SWBDPCCouponService', 'native_post' ], 5, 2 );
        foreach ( [ 'pre_trash_post', 'pre_untrash_post' ] as $hook ) {
            add_filter( $hook, static function ( $result, $post ) {
                if ( $post && SWBDPC_POST_TYPE === $post->post_type ) { \SWBDPCCouponService::lock(); }
                return $result;
            }, 0, 2 );
        }
    }
    public static function available(): bool {
        return class_exists( '\SWBDPCHelperFunctions' ) && defined( 'ATBDP_VERSION' ) && 1 === (int) get_directorist_option( 'swbdp_coupon_is_enable', 1 );
    }
    public static function admission( int $id ): bool { return $id > 0 && $id === get_current_user_id() && current_user_can( 'read' ); }
    public static function object_permitted( int $id, string $action ): bool {
        $post = get_post( $id );
        return current_user_can( 'manage_options' ) && $post && SWBDPC_POST_TYPE === $post->post_type && current_user_can( $action . '_post', $id );
    }
    public static function permitted( string $action, array $args, int $id ): bool {
        if ( ! self::admission( $id ) || ! self::available() ) { return false; }
        if ( in_array( $action, [ 'get-schema', 'validate-coupon' ], true ) ) { return true; }
        if ( ! current_user_can( 'manage_options' ) ) { return false; }
        $type = get_post_type_object( SWBDPC_POST_TYPE );
        if ( ! $type ) { return false; }
        if ( 'create-coupon' === $action ) { return current_user_can( $type->cap->create_posts ); }
        if ( isset( $args['coupon_id'] ) ) {
            $cap = in_array( $action, [ 'trash-coupon', 'restore-coupon' ], true ) ? 'delete' : ( in_array( $action, self::WRITES, true ) ? 'edit' : 'read' );
            if ( ! self::object_permitted( $args['coupon_id'], $cap ) ) { return false; }
            if ( 'restore-coupon' === $action && ! self::object_permitted( $args['coupon_id'], 'edit' ) ) { return false; }
        }
        return current_user_can( $type->cap->edit_posts );
    }
    public static function register( $registry ): void {
        $ok = $registry->register_extension( [
            'owner' => 'coupon', 'plugin' => plugin_basename( SWBDPC_BASE_FILE ), 'label' => 'Directorist Coupon',
            'version' => SWBDPC_PLUGIN_VERSION, 'contract_version' => '1.0.0', 'api_version' => 1,
            'capability' => 'read', 'requires' => [ 'directorist/directorist-base.php' ],
            'availability' => [ self::class, 'available' ], 'admission' => [ self::class, 'admission' ],
        ] );
        if ( is_wp_error( $ok ) ) { return; }
        foreach ( self::ACTIONS as $action ) {
            $write = in_array( $action, self::WRITES, true );
            $definition = [
                'owner' => 'coupon', 'action' => $action, 'domain' => 'extension', 'group' => 'Coupon', 'contract_version' => '1.0.0',
                'description' => self::description( $action ), 'write' => $write, 'schema' => Schema::input( $action ), 'output_schema' => Schema::output( $action ),
                'discover' => static function ( array $args, int $id ) use ( $action ): bool { return self::permitted( $action, [], $id ); },
                'permission' => static function ( array $args, int $id ) use ( $action ): bool { return self::permitted( $action, $args, $id ); },
                'project' => static function ( array $value, array $args, int $id ) use ( $action ): array {
                    Validation::check( self::permitted( $action, $args, $id ), 'Coupon access changed.', 'forbidden' );
                    foreach ( isset( $value['coupons'] ) ? $value['coupons'] : ( isset( $value['coupon'] ) ? [ $value['coupon'] ] : [] ) as $coupon ) {
                        Validation::check( self::object_permitted( $coupon['id'], 'read' ), 'Coupon access changed.', 'forbidden' );
                    }
                    return array_intersect_key( $value, Schema::output( $action )['properties'] + [ 'success' => true, 'committed' => true, 'idempotent_replay' => true ] );
                },
            ];
            if ( $write ) {
                $definition += [ 'preview_schema' => Schema::preview(), 'tables' => self::tables(), 'invalidate' => [ self::class, 'invalidate' ], 'prepare' => static function ( array $args ) use ( $action ): array { return self::prepare( $action, $args ); } ];
            } else {
                $definition['read'] = static function ( array $args ) use ( $action ): array { return Reader::read( $action, $args ); };
            }
            $registry->register_tool( $definition );
        }
    }
    private static function description( string $action ): string {
        $descriptions = [
            'get-schema' => 'Read native coupon precision, applicability, expiry, per-user usage, lifecycle and effective validation rate-limit rules.',
            'list-coupons' => 'List authorized private promotion configuration with stable filters and pagination. Administrators only.',
            'get-coupon' => 'Read an authorized native coupon and its current version. Administrators only.',
            'create-coupon' => 'Preview and create a unique native coupon as a draft, with whole-number discount configuration.',
            'update-coupon' => 'Preview selected coupon changes after validating the complete effective record; retain history and unrelated fields.',
            'change-status' => 'Preview native publication state and effective coupon availability without changing historical orders.',
            'trash-coupon' => 'Preview moving a coupon to recoverable trash. Retain its configuration and order history.',
            'restore-coupon' => 'Preview the native restored publication state, retaining expiry and checking code collisions.',
            'validate-coupon' => 'Rate-limited code check against the authenticated buyer\'s server-calculated native one-time plan checkout. No order or usage reservation.',
            'get-usage' => 'Read native all-status user/code order-row counts and safe order IDs. Administrators only.',
        ];
        return $descriptions[$action];
    }
    public static function tables(): array {
        global $wpdb;
        return [ $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->comments, $wpdb->commentmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships ];
    }
    public static function invalidate(): void {
        foreach ( array_keys( self::$touched ) as $id ) { clean_post_cache( $id ); }
        if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) { wp_cache_set_posts_last_changed(); }
    }
    public static function prepare( string $action, array $args ): array {
        Validation::check( self::permitted( $action, $args, get_current_user_id() ), 'Coupon access is unavailable.', 'forbidden' );
        $id = $args['coupon_id'] ?? 0;
        if ( $id ) { self::$touched[$id] = true; }
        $before = $id ? Reader::coupon( $id ) : null;
        $config = $id ? \SWBDPCCouponService::configuration( $id ) : array_intersect_key( $args, \SWBDPCCouponService::META );
        $status = $before ? $before['status'] : 'draft';
        if ( 'update-coupon' === $action ) {
            Validation::check( ! empty( $args['changes'] ), 'Select at least one configuration field.' );
            $config = array_replace( $config, $args['changes'] );
        }
        Validation::check( ! $id || ( 'restore-coupon' === $action ? 'trash' === $status : 'trash' !== $status ), 'Restore a trashed coupon before editing it.' );
        if ( 'change-status' === $action ) { $status = $args['status']; }
        if ( 'trash-coupon' === $action ) {
            Validation::check( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS > 0, 'Recoverable trash is disabled on this site.', 'unsupported_operation' );
            $status = 'trash';
        }
        if ( 'restore-coupon' === $action ) {
            $previous = (string) get_post_meta( $id, '_wp_trash_meta_status', true );
            $status = apply_filters( 'wp_untrash_post_status', 'draft', $id, $previous );
            Validation::check( in_array( $status, [ 'publish', 'draft', 'pending', 'private' ], true ), 'The native restoration state needs a supported adapter.', 'unsupported_operation' );
        }
        // Stopping an invalid old promotion must remain possible.
        if ( in_array( $action, [ 'create-coupon', 'update-coupon', 'restore-coupon' ], true ) || 'publish' === $status ) {
            $config = Validation::unwrap( \SWBDPCCouponService::validate( $config, $id ) );
        }
        if ( in_array( $status, [ 'publish', 'private' ], true ) ) {
            $type = get_post_type_object( SWBDPC_POST_TYPE );
            Validation::check( $type && current_user_can( $type->cap->publish_posts ), 'Native publication capability is required.', 'forbidden' );
        }
        $plans = [];
        foreach ( $config['eligible_plan_ids'] as $plan_id ) {
            $plan = \SWBDPCCouponService::plan( $plan_id );
            $plans[] = is_wp_error( $plan ) ? 'unavailable' : (array) $plan;
        }
        $state = [ 'record' => $id ? Reader::state( $id ) : [], 'usage' => $before ? \SWBDPCCouponService::usage( $before['code'] ) : 0, 'plans' => $plans, 'site_day' => current_time( 'Y-m-d' ), 'code_collision' => \SWBDPCCouponService::collision( $config['code'], $id ) ];
        $after = Reader::record( $config, $id, $status );
        $changed = [];
        foreach ( $after as $key => $value ) { if ( 'version' !== $key && ( ! $before || $before[$key] !== $value ) ) { $changed[] = $key; } }
        return [
            'state' => $state,
            'preview' => [ 'before' => $before ? [ $before ] : [], 'after' => $after, 'changed_fields' => $changed, 'effects' => 'Native coupon configuration/publication only. Expiry is inclusive in site time. No historical order, payment or usage row changes.' ],
            'execute' => static function () use ( $action, $id, $config, $status ): array {
                if ( ! $id ) {
                    $id = Validation::unwrap( wp_insert_post( [ 'post_type' => SWBDPC_POST_TYPE, 'post_title' => $config['code'], 'post_status' => 'draft', 'post_author' => get_current_user_id() ], true ) );
                    Validation::check( $id > 0, 'Coupon creation failed.' );
                    self::$touched[$id] = true;
                }
                if ( in_array( $action, [ 'create-coupon', 'update-coupon' ], true ) ) {
                    Validation::unwrap( \SWBDPCCouponService::save( $id, $config ) );
                } elseif ( 'trash-coupon' === $action ) {
                    Validation::check( (bool) wp_trash_post( $id ), 'Coupon could not be moved to trash.' );
                } elseif ( 'restore-coupon' === $action ) {
                    Validation::check( (bool) wp_untrash_post( $id ), 'Coupon could not be restored.' );
                } else {
                    Validation::unwrap( wp_update_post( [ 'ID' => $id, 'post_status' => $status ], true ) );
                }
                clean_post_cache( $id );
                Validation::check( get_post_status( $id ) === $status, 'Native publication state differed from preview.' );
                return [ 'coupon' => Reader::coupon( $id ) ];
            },
        ];
    }
}
