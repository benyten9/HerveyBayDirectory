<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

/** Register before core seals its inventory, independently of the connected user. */
final class Integration {
    private const OWNER = 'pricing-plans';
    private const CONTRACT = '1.0.0';

    public static function boot(): void {
        add_action( 'directorist_mcp_register', [ self::class, 'register' ] );
        add_filter( 'directorist_mcp_listing_policy', [ Entitlements::class, 'listing_policy' ], PHP_INT_MIN, 5 );
        add_filter( 'rest_request_before_callbacks', [ NativeSafety::class, 'rest' ], 0, 3 );
        add_action( 'directorist_pp_mcp_deliver_event', [ Outbox::class, 'deliver_event' ] );
        add_action( 'directorist_pp_mcp_deliver_mail', [ Outbox::class, 'deliver' ] );
    }

    public static function available(): bool {
        return did_action( 'after_load_directorist_pricing_plans' ) > 0 && function_exists( 'directorist_user_package_repository' ) && defined( 'ATBDP_VERSION' ) && version_compare( ATBDP_VERSION, '8.9.4', '>=' );
    }

    public static function admission( int $user_id ): bool {
        return $user_id > 0 && $user_id === get_current_user_id() && current_user_can( 'read' );
    }

    public static function tables(): array {
        global $wpdb;
        $tables = [ $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships ];
        foreach ( [ 'plans', 'plan_features', 'plan_app_configurations', 'user_packages', 'package_orders', 'plan_order_meta', 'subscription_logs', 'plans_queues', 'orders', 'payments', 'refunds' ] as $table ) {
            $tables[] = $wpdb->prefix . 'directorist_' . $table;
        }
        return $tables;
    }

    public static function invalidate(): void {
        wp_cache_delete( 'atbdp_option', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) {
            wp_cache_set_posts_last_changed();
        }
        if ( function_exists( 'directorist_pricing_plans_singleton' ) ) {
            directorist_pricing_plans_singleton( \DirectoristPricingPlan\App\Providers\FormFields::class )->clear_plan_feature_cache();
        }
    }

    public static function register( $registry ): void {
        $ok = $registry->register_extension( [
            'owner' => self::OWNER, 'plugin' => plugin_basename( DIRECTORIST_PRICING_PLANS_FILE ),
            'label' => 'Directorist Pricing Plans', 'version' => '4.3.0', 'contract_version' => self::CONTRACT,
            'api_version' => 1, 'capability' => 'read', 'requires_core' => '8.9.4',
            'requires' => [ 'directorist/directorist-base.php' ],
            'availability' => [ self::class, 'available' ], 'admission' => [ self::class, 'admission' ],
        ] );
        if ( is_wp_error( $ok ) ) {
            return;
        }
        $actions = self::actions();
        foreach ( $actions as $action ) {
            $write = in_array( $action, [ 'create-plan', 'update-plan', 'delete-plan', 'assign-listing-plan', 'renew-listing' ], true );
            $definition = [
                'owner' => self::OWNER, 'action' => $action, 'domain' => 'extension', 'group' => 'Pricing Plans',
                'description' => self::description( $action ), 'contract_version' => self::CONTRACT,
                'write' => $write, 'schema' => Schema::input( $action ), 'output_schema' => Schema::output( $action ),
                'discover' => static function ( array $args, int $user_id ) use ( $action ): bool {
                    return self::admission( $user_id ) && ( ! in_array( $action, [ 'create-plan', 'update-plan', 'delete-plan' ], true ) || Access::admin() );
                },
                'permission' => static function ( array $args, int $user_id ) use ( $action ): bool { return Access::permitted( $action, $args, $user_id ); },
                'project' => static function ( array $result, array $args, int $user_id ) use ( $action ): array {
                    Access::check( Access::permitted( $action, $args, $user_id ), 'Access changed.', 'forbidden' );
                    if ( isset( $result['plan'] ) ) {
                        Access::plan( (int) $result['plan']['id'] );
                        if ( ! Access::admin() ) {
                            unset( $result['plan']['user_counts'] );
                        }
                    }
                    if ( isset( $result['package_id'] ) && $result['package_id'] ) {
                        Access::package( (int) $result['package_id'] );
                    }
                    // Keep only the declared public contract, including on idempotent replay.
                    return array_intersect_key( $result, Schema::output( $action )['properties'] + [ 'success' => true, 'committed' => true, 'idempotent_replay' => true ] );
                },
            ];
            if ( $write ) {
                $definition += [
                    'preview_schema' => Schema::preview( $action ), 'tables' => self::tables(),
                    'invalidate' => [ self::class, 'invalidate' ],
                    'prepare' => static function ( array $args ) use ( $action ): array {
                        return in_array( $action, [ 'create-plan', 'update-plan', 'delete-plan' ], true ) ? PlanWriter::prepare( $action, $args ) : Entitlements::prepare( $action, $args );
                    },
                ];
            } else {
                $definition['read'] = static function ( array $args ) use ( $action ): array { return Reader::read( $action, $args ); };
            }
            $registry->register_tool( $definition );
        }
        self::fields( $registry );
    }

    public static function actions(): array {
        return [ 'get-schema', 'list-plans', 'get-plan', 'create-plan', 'update-plan', 'delete-plan', 'list-packages', 'get-package', 'get-listing-entitlement', 'assign-listing-plan', 'renew-listing' ];
    }

    public static function operations(): array {
        global $wpdb;
        $storage = true;
        foreach ( self::tables() as $table ) {
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
            $storage = $storage && 'INNODB' === strtoupper( (string) $engine );
        }
        $native_entitlement_adapter = true;
        try { Entitlements::payment_adapter(); } catch ( \Throwable $error ) { $native_entitlement_adapter = false; }
        $out = [];
        foreach ( self::actions() as $action ) {
            $management = in_array( $action, [ 'create-plan', 'update-plan', 'delete-plan' ], true );
            if ( $management && ! Access::admin() ) { continue; }
            $entitlement = in_array( $action, [ 'assign-listing-plan', 'renew-listing' ], true );
            $available = ( ! $management && ! $entitlement ) || ( $storage && ( ! $entitlement || $native_entitlement_adapter ) );
            $out[] = [ 'action' => $action, 'available' => $available, 'reason' => $available ? '' : ( $storage ? 'Active payment adapter is not certified for this flow.' : 'A required transactional store is unavailable.' ) ];
        }
        return $out;
    }

    private static function description( string $action ): string {
        $descriptions = [
            'get-schema' => 'Discover native plan types, features, defaults, currency precision and quota semantics.',
            'list-plans' => 'List permitted plans with typed filters on every configuration field, effective feature setting and returned metadata. Admins can also filter user counts. All filters combine before pagination and totals; get-schema lists supported fields and operators.',
            'get-plan' => 'Inspect plan details and optional own-listing eligibility. Administrators also see total, active and inactive user counts.',
            'create-plan' => 'Preview and confirm a native plan with all effective defaults.',
            'update-plan' => 'Edit the settings editable in the native admin UI, including pricing, taxes, duration, subscriptions, trials, features, allowances, fallback, priority and Publish/visibility controls. Existing or historical packages do not block these administrator edits. Plan type and directory are locked on existing plans, matching the admin UI even when the REST API is more permissive. Existing packages use live plan settings; preview discloses affected packages and native effects. Preserve omitted settings. Website permissions and configuration validation still apply.',
            'delete-plan' => 'Permanently delete an unreferenced plan and its feature/application configuration rows. Administrator only. First call with dry_run:true, show the user the plan name and ID, settings and counts of configuration rows to be removed, and explain that deletion is permanent. Then stop and wait for explicit confirmation in a later user message before executing that exact preview. The initial deletion request and the returned confirm_token are not approval of the preview. If the preview expires or the state changes, show a fresh preview and wait for confirmation again. Confirmation never overrides native deletion restrictions.',
            'list-packages' => 'List own packages, or an authorized administrative scope, with native usage.',
            'get-package' => 'Inspect an authorized package, lifecycle, allowance and safe order references.',
            'get-listing-entitlement' => 'Explain an authorized listing entitlement and compatible purchased allowances.',
            'assign-listing-plan' => 'Confirm use of an owned package or an explicit administrative grant; preview discloses package and paid-order effects.',
            'renew-listing' => 'Renew an expired listing under an existing allowance or return native checkout required. Never dispatch payment.',
        ];
        return $descriptions[ $action ];
    }

    private static function fields( $registry ): void {
        global $wpdb;
        foreach ( [ 'plan_direct_purchase', 'allow_multiple_plans_per_directory_type', 'enable_listing_display_priority', 'pricing_plans' ] as $key ) {
            $page = 'pricing_plans' === $key;
            $default = $page ? '' : false;
            $registry->register_field_adapter( [
                'owner' => self::OWNER, 'domain' => 'settings', 'key' => $key,
                'contract_version' => self::CONTRACT, 'schema' => $page ? Schema::text( 20 ) : [ 'type' => 'boolean' ],
                'default' => $default, 'sensitive' => false, 'capability' => 'manage_options',
                'writable' => true, 'resettable' => true, 'storage_adapter' => 'directorist',
                'tables' => [ $wpdb->options ], 'invalidate' => [ self::class, 'invalidate' ],
                'permission' => static function (): bool { return Access::admin(); },
                'read' => static function () use ( $key, $default ) { $saved = (array) get_option( 'atbdp_option', [] ); return $saved[ $key ] ?? $default; },
                'validate' => static function ( $value ) use ( $page ) {
                    Access::check( Access::admin(), 'Administrator access is required.', 'forbidden' );
                    if ( $page && '' !== $value ) {
                        Access::check( is_string( $value ) && ctype_digit( $value ) && 'page' === get_post_type( (int) $value ) && current_user_can( 'read_post', (int) $value ), 'Select an accessible native page.' );
                    } else {
                        Access::check( $page || is_bool( $value ), 'Use a native boolean switch.' );
                    }
                    return $value;
                },
                // The shared Directorist adapter persists one complete selected change set through the native panel.
                'write' => static function () { return new \WP_Error( 'native_settings_required', 'Use the registered core settings writer.' ); },
            ] );
        }
        $registry->register_field_adapter( [
            'owner' => self::OWNER, 'domain' => 'listings', 'key' => 'pricing_entitlement',
            'contract_version' => self::CONTRACT, 'schema' => Schema::entitlement(), 'default' => (object) [],
            'sensitive' => false, 'writable' => false,
            'permission' => static function ( $context ): bool { return $context instanceof \WP_Post && (bool) Access::listing( (int) $context->ID ); },
            'read' => static function ( $context ): array { return Entitlements::describe( (int) $context->ID ); },
            'validate' => static function () { return new \WP_Error( 'read_only', 'Use the confirmed entitlement tools.' ); },
        ] );
        $registry->register_field_adapter( [
            'owner' => self::OWNER, 'domain' => 'orders', 'key' => 'pricing_plan_reference',
            'contract_version' => self::CONTRACT, 'schema' => Schema::object( [ 'plan_id' => Schema::integer( 1 ) ] ),
            'default' => (object) [], 'sensitive' => false, 'writable' => false,
            'permission' => static function ( $context ): bool {
                return $context instanceof \stdClass && 'pricing_plan' === ( $context->ref_type ?? '' ) && ( Access::admin() || (int) $context->user_id === get_current_user_id() );
            },
            'read' => static function ( $context ): array { return [ 'plan_id' => (int) $context->ref ]; },
            'validate' => static function () { return new \WP_Error( 'read_only', 'Use native checkout for plan-backed purchases.' ); },
        ] );
        $registry->register_field_adapter( [
            'owner' => self::OWNER, 'domain' => 'builder', 'key' => 'listing-type',
            'contract_version' => self::CONTRACT, 'schema' => Schema::object( [ 'label' => Schema::text(), 'featured_label' => Schema::text() ] ),
            'default' => (object) [], 'sensitive' => false, 'writable' => false,
            'permission' => static function (): bool { return Access::admin(); },
            'read' => static function () { return (object) []; },
            'validate' => static function () { return new \WP_Error( 'adapter_unavailable', 'Edit this native widget in the directory builder.' ); },
        ] );
        $registry->register_field_adapter( [
            'owner' => self::OWNER, 'domain' => 'order_items', 'key' => 'pricing_plan',
            'contract_version' => self::CONTRACT, 'schema' => Schema::object( [ 'plan_id' => Schema::integer( 1 ), 'listing_id' => Schema::integer( 1 ) ], [ 'plan_id' ] ),
            'default' => (object) [], 'sensitive' => false, 'writable' => false,
            'permission' => static function (): bool { return self::admission( get_current_user_id() ); },
            'read' => static function () { return (object) []; },
            'validate' => static function ( $item ) {
                Access::plan( (int) $item['plan_id'] );
                if ( isset( $item['listing_id'] ) ) { Access::listing( (int) $item['listing_id'] ); }
                return new \WP_Error( 'checkout_required', 'Pricing-plan purchases require native checkout. No order or entitlement was created.' );
            },
        ] );
    }
}
