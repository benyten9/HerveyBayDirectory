<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ATPP_FILE' ) ) {
    define( 'ATPP_FILE', dirname( __DIR__, 2 ) . '/directorist-pricing-plans.php' );
}

$atpp_legacy_constants = [
    'ATPP_VERSION'                    => '4.0.0-beta10',
    'ATPP_PREFIIX'                    => 'ddpp',
    'ATPP_DIR'                        => plugin_dir_path( ATPP_FILE ),
    'ATPP_URL'                        => plugin_dir_url( ATPP_FILE ),
    'ATPP_BASE'                       => plugin_basename( ATPP_FILE ),
    'ATPP_INC_DIR'                    => plugin_dir_path( ATPP_FILE ) . 'inc/',
    'ATPP_LIB_DIR'                    => plugin_dir_path( ATPP_FILE ) . 'inc/lib/',
    'ATPP_CLASSES_DIR'                => plugin_dir_path( ATPP_FILE ) . 'inc/classes/',
    'ATPP_VIEWS_DIR'                  => plugin_dir_path( ATPP_FILE ) . 'inc/views/',
    'ATPP_ASSETS'                     => plugin_dir_url( ATPP_FILE ) . 'assets/',
    'ATPP_TEMPLATES_DIR'              => plugin_dir_path( ATPP_FILE ) . 'resources/views/templates/',
    'ATPP_LANG_DIR'                   => dirname( plugin_basename( ATPP_FILE ) ) . '/languages',
    'ATPP_NAME'                       => 'Directorist - Pricing Plans',
    'ATBDP_POST_TYPE'                 => 'at_biz_dir',
    'ATBDP_ORDER_POST_TYPE'           => 'atbdp_orders',
    'ATBDP_PRICING_PLANS_POST_TYPE'   => 'atbdp_pricing_plans',
    'ATBDP_AUTHOR_URL'                => 'https://directorist.com',
    'ATBDP_PRICING_PLAN_POST_ID'      => 13776,
    'DPP_META_KEY_PLAN_SORTING_ORDER' => '_dpp_plan_sorting_order',
    'DPP_KEY_BG_LISTING_META_UPDATER' => 'dpp_bg_listing_meta_updater',
];

foreach ( $atpp_legacy_constants as $constant => $value ) {
    if ( ! defined( $constant ) ) {
        define( $constant, $value );
    }
}

unset( $atpp_legacy_constants, $constant, $value );

if ( ! function_exists( 'directorist_pricing_plans_deprecated_function' ) ) {
    function directorist_pricing_plans_deprecated_function( string $function, string $replacement = '' ): void {
        if ( function_exists( '_deprecated_function' ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _deprecated_function() accepts raw function names, not rendered output.
            _deprecated_function( $function, '4.0.0', $replacement );
        }
    }
}

if ( ! function_exists( 'directorist_pricing_plans_legacy_plan_id' ) ) {
    function directorist_pricing_plans_legacy_plan_id( $plan_id ): int {
        $plan_id = absint( $plan_id );

        if ( ! $plan_id ) {
            return 0;
        }

        $plan_id_map = get_option( 'directorist_migration_plan_id_map', [] );

        if ( is_array( $plan_id_map ) && isset( $plan_id_map[ $plan_id ] ) ) {
            return absint( $plan_id_map[ $plan_id ] );
        }

        return $plan_id;
    }
}

if ( ! function_exists( 'directorist_pricing_plans_legacy_plan' ) ) {
    function directorist_pricing_plans_legacy_plan( $plan_id ) {
        $plan_id = directorist_pricing_plans_legacy_plan_id( $plan_id );

        if ( ! $plan_id || ! function_exists( 'directorist_get_pricing_plan_by_id' ) ) {
            return null;
        }

        try {
            return directorist_get_pricing_plan_by_id( $plan_id );
        } catch ( Throwable $e ) {
            return null;
        }
    }
}

if ( ! function_exists( 'directorist_pricing_plans_legacy_feature' ) ) {
    function directorist_pricing_plans_legacy_feature( $plan_id, string $feature_key ) {
        $plan = directorist_pricing_plans_legacy_plan( $plan_id );

        if ( ! $plan ) {
            return null;
        }

        try {
            $features = directorist_pricing_plans_singleton( \DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository::class )->get( $plan );
        } catch ( Throwable $e ) {
            return null;
        }

        foreach ( $features as $feature ) {
            if ( isset( $feature->key ) && $feature->key === $feature_key ) {
                return $feature;
            }
        }

        return null;
    }
}

if ( ! function_exists( 'directorist_pricing_plans_legacy_feature_enabled' ) ) {
    function directorist_pricing_plans_legacy_feature_enabled( $plan_id, string $feature_key ): bool {
        $feature = directorist_pricing_plans_legacy_feature( $plan_id, $feature_key );
        return ! $feature || ! empty( $feature->is_enabled );
    }
}

if ( ! function_exists( 'directorist_pricing_plans_legacy_feature_limit' ) ) {
    function directorist_pricing_plans_legacy_feature_limit( $plan_id, string $feature_key ) {
        $feature = directorist_pricing_plans_legacy_feature( $plan_id, $feature_key );

        if ( ! $feature || empty( $feature->data ) ) {
            return '';
        }

        $data = is_array( $feature->data ) ? $feature->data : (array) $feature->data;
        return $data['limit'] ?? '';
    }
}

if ( ! function_exists( 'directorist_pricing_plans_legacy_feature_unlimited' ) ) {
    function directorist_pricing_plans_legacy_feature_unlimited( $plan_id, string $feature_key ): bool {
        $feature = directorist_pricing_plans_legacy_feature( $plan_id, $feature_key );

        if ( ! $feature || empty( $feature->data ) ) {
            return false;
        }

        $data = is_array( $feature->data ) ? $feature->data : (array) $feature->data;
        return ! empty( $data['is_unlimited'] );
    }
}

if ( ! function_exists( 'directorist_pricing_plans_get_template' ) ) {
    function directorist_pricing_plans_get_template( $template_file, $args = [] ) {
        if ( empty( $template_file ) ) {
            return '';
        }

        if ( is_array( $args ) ) {
            extract( $args, EXTR_SKIP );
        }

        $template_file = sanitize_file_name( $template_file );

        $paths = [
            get_stylesheet_directory() . '/directorist-pricing-plans/' . $template_file . '.php',
            get_template_directory() . '/directorist-pricing-plans/' . $template_file . '.php',
            directorist_pricing_plans_dir( 'resources/views/templates/' ) . $template_file . '.php',
            directorist_pricing_plans_dir( 'resources/views/' ) . $template_file . '.php',
        ];

        foreach ( $paths as $path ) {
            if ( file_exists( $path ) ) {
                include $path;
                return;
            }
        }

        if ( 'fee-plans' === $template_file ) {
            echo wp_kses_post( directorist_render_plans( null, is_array( $args ) ? $args : [] ) );
        }
    }
}

if ( ! function_exists( 'atpp_get_template' ) ) {
    function atpp_get_template( $template_file, $args = [] ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_pricing_plans_get_template' );

        directorist_pricing_plans_get_template( $template_file, $args );
    }
}

if ( ! function_exists( 'directorist_get_directory_submission_fields' ) ) {
    function directorist_get_directory_submission_fields( $plan = null ) {
        $directory_id = ! empty( $plan->directory_type_id ) ? (int) $plan->directory_type_id : ( function_exists( 'default_directory_type' ) ? (int) default_directory_type() : 0 );

        if ( ! $directory_id ) {
            return [];
        }

        return directorist_plan_registered_features( $directory_id );
    }
}

if ( ! function_exists( 'get_category_ids_by_directory_type_id' ) ) {
    function get_category_ids_by_directory_type_id( $directory_type_id ) {
        $directory_type_id = absint( $directory_type_id );

        if ( ! $directory_type_id ) {
            return [];
        }

        $categories = get_terms(
            [
                'taxonomy'   => defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : 'at_biz_dir-category',
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'     => '_directory_type',
                        'value'   => sprintf( 'i:%d;', $directory_type_id ),
                        'compare' => 'LIKE',
                    ],
                ],
                'fields'     => 'ids',
            ]
        );

        return is_wp_error( $categories ) ? [] : array_map( 'intval', (array) $categories );
    }
}

if ( ! function_exists( 'directorist_pricing_plans_get_pages_options' ) ) {
    function directorist_pricing_plans_get_pages_options( array $args = [] ) {
        $pages = get_pages(
            wp_parse_args(
                $args,
                [
                    'sort_order'   => 'ASC',
                    'sort_column'  => 'post_title',
                    'hierarchical' => 1,
                    'post_status'  => 'publish',
                ]
            )
        );

        if ( empty( $pages ) || is_wp_error( $pages ) ) {
            return [];
        }

        return array_map(
            static function( $page ) {
                return [
                    'value' => (int) $page->ID,
                    'label' => $page->post_title ?: sprintf( __( 'Page #%d', 'directorist-pricing-plans' ), $page->ID ),
                ];
            },
            $pages
        );
    }
}

if ( ! function_exists( 'directorist_plan_features' ) ) {
    function directorist_plan_features( $features ) {
        $icon  = $features ? 'fas fa-check' : 'fas fa-times';
        $class = $features ? 'directorist_green' : 'directorist_red';

        return function_exists( 'directorist_icon' ) ? directorist_icon( $icon, true, $class ) : '';
    }
}

if ( ! function_exists( 'directorist_direct_purchase' ) ) {
    function directorist_direct_purchase() {
        $direct_purchase = function_exists( 'get_directorist_option' ) ? get_directorist_option( 'plan_direct_purchase', false ) : false;
        return apply_filters( 'directorist_direct_purchase', $direct_purchase );
    }
}

if ( ! function_exists( 'selected_plan_id' ) ) {
    function selected_plan_id() {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );

        if ( isset( $_POST['plan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return absint( wp_unslash( $_POST['plan'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( isset( $_GET['plan_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return absint( wp_unslash( $_GET['plan_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( isset( $_GET['plan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return absint( wp_unslash( $_GET['plan'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        return false;
    }
}

if ( ! function_exists( 'public_plans' ) ) {
    function public_plans() {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_pricing_plan_repository()->get_by_directory_type()' );

        $directory_id = function_exists( 'default_directory_type' ) ? (int) default_directory_type() : 0;

        if ( ! $directory_id ) {
            return [];
        }

        try {
            return directorist_pricing_plan_repository()->get_by_directory_type( $directory_id );
        } catch ( Throwable $e ) {
            return [];
        }
    }
}

if ( ! function_exists( 'selected_plan_meta' ) ) {
    function selected_plan_meta( $plan_id, $meta_key ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_get_pricing_plan_by_id' );

        $plan = directorist_pricing_plans_legacy_plan( $plan_id );

        if ( ! $plan ) {
            return '';
        }

        $map = [
            'fm_price'                => 'price',
            'fm_description'          => 'description',
            'fm_length'               => 'interval_count',
            '_recurrence_period_term' => 'interval_type',
            'plan_type'               => 'type',
            'free_plan'               => 'fee_type',
            'plan_tax'                => 'is_taxable',
            'plan_tax_type'           => 'tax_type',
            'fm_tax'                  => 'tax_rate',
            'num_regular'             => 'allowed_listings',
            'num_regular_unl'         => 'is_allowed_unlimited_listings',
            'num_featured'            => 'allowed_featured_listings',
            'num_featured_unl'        => 'is_allowed_unlimited_featured_listings',
            '_hide_from_plans'        => 'is_hidden_from_plans_list',
            '_dpp_plan_sorting_order' => 'listing_display_priority',
        ];

        if ( ! isset( $map[ $meta_key ] ) ) {
            return '';
        }

        if ( 'free_plan' === $meta_key ) {
            return ( $plan->fee_type ?? '' ) === 'free' ? '1' : '';
        }

        return $plan->{$map[ $meta_key ]} ?? '';
    }
}

if ( ! function_exists( 'atpp_total_price' ) ) {
    function atpp_total_price( $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        $plan = directorist_pricing_plans_legacy_plan( $plan_id );
        return $plan ? (float) $plan->price : 0;
    }
}

if ( ! function_exists( 'directorist_plan_tax_rate' ) ) {
    function directorist_plan_tax_rate( $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        $plan = directorist_pricing_plans_legacy_plan( $plan_id );
        $tax  = $plan && ! empty( $plan->is_taxable ) ? (float) $plan->tax_rate : 0;
        return apply_filters( 'directorist_plan_tax_rate', $tax, $plan_id );
    }
}

if ( ! function_exists( 'atpp_total_tax' ) ) {
    function atpp_total_tax( $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_get_plan_tax_amount' );
        $plan = directorist_pricing_plans_legacy_plan( $plan_id );
        try {
            $tax = $plan ? directorist_get_plan_tax_amount( $plan, (float) $plan->price ) : 0;
        } catch ( Throwable $e ) {
            $tax = 0;
        }
        return apply_filters( 'directorist_plan_tax', $tax, $plan_id );
    }
}

if ( ! function_exists( 'atpp_total_price_with_tax' ) ) {
    function atpp_total_price_with_tax( $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        return atpp_total_price( $plan_id ) + atpp_total_tax( $plan_id );
    }
}

if ( ! function_exists( 'directorist_plan_lifetime' ) ) {
    function directorist_plan_lifetime( $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_plan_duration_text' );
        $plan = directorist_pricing_plans_legacy_plan( $plan_id );
        return $plan ? directorist_plan_duration_text( $plan ) : '';
    }
}

if ( ! function_exists( 'package_or_PPL' ) ) {
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Legacy public API.
    function package_or_PPL( $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        $plan = directorist_pricing_plans_legacy_plan( $plan_id );
        return $plan ? ( $plan->type ?? 'package' ) : '';
    }
}

if ( ! function_exists( 'PPL_with_featured' ) ) {
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Legacy public API.
    function PPL_with_featured( $plan = null ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );

        if ( is_numeric( $plan ) ) {
            $plan = directorist_pricing_plans_legacy_plan( $plan );
        }

        return $plan && ( $plan->type ?? '' ) === 'pay_per_listing' && ! empty( $plan->is_featured );
    }
}

if ( ! function_exists( 'has_plan' ) ) {
    function has_plan( $listing_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        return (bool) get_post_meta( absint( $listing_id ), directorist_plan_key(), true );
    }
}

if ( ! function_exists( 'plans_remaining' ) ) {
    function plans_remaining( $plan_id = '', $order_id = '' ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );

        $plan    = directorist_pricing_plans_legacy_plan( $plan_id );
        $package = null;

        if ( $plan ) {
            try {
                $package = directorist_user_package_repository()->get_package_by_plan( get_current_user_id(), (int) $plan->id );
            } catch ( Throwable $e ) {
                $package = null;
            }
        }

        $data = [
            'regular'  => $package && isset( $package->uses['listings']['remaining'] ) ? $package->uses['listings']['remaining'] : 0,
            'featured' => $package && isset( $package->uses['featured_listings']['remaining'] ) ? $package->uses['featured_listings']['remaining'] : 0,
        ];

        return apply_filters( 'directorist_plan_remaining', $data );
    }
}

if ( ! function_exists( 'atpp_get_used_free_plan' ) ) {
    function atpp_get_used_free_plan( $plan_id, $user_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        try {
            return directorist_user_package_repository()->has_ever_used_plan( absint( $user_id ), directorist_pricing_plans_legacy_plan_id( $plan_id ) );
        } catch ( Throwable $e ) {
            return false;
        }
    }
}

if ( ! function_exists( 'directorist_recurring_plans' ) ) {
    function directorist_recurring_plans() {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        try {
            return directorist_pricing_plan_repository()->get_query_builder()->where( 'is_subscription_enabled', 1 )->get();
        } catch ( Throwable $e ) {
            return [];
        }
    }
}

if ( ! function_exists( 'directorist_active_orders' ) ) {
    function directorist_active_orders( $plan_id = '', $user_id = '' ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_user_package_repository' );
        $plan_id = directorist_pricing_plans_legacy_plan_id( $plan_id );
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();

        if ( ! $plan_id || ! $user_id ) {
            return [];
        }

        try {
            $package = directorist_user_package_repository()->get_package_by_plan( $user_id, $plan_id );
        } catch ( Throwable $e ) {
            $package = null;
        }
        return $package ? [ $package ] : [];
    }
}

if ( ! function_exists( 'directorist_active_orders_without_listing' ) ) {
    function directorist_active_orders_without_listing( $plan_id = '' ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_get_paid_order_without_listing' );
        try {
            $order = directorist_get_paid_order_without_listing( directorist_pricing_plans_legacy_plan_id( $plan_id ) );
        } catch ( Throwable $e ) {
            $order = null;
        }
        return $order ? [ $order ] : [];
    }
}

if ( ! function_exists( 'directorist_valid_order' ) ) {
    function directorist_valid_order( $order_id, $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );

        if ( ! $order_id || ! $plan_id ) {
            return false;
        }

        if ( ! function_exists( 'directorist_make' ) || ! class_exists( '\Directorist\Repositories\OrderRepository' ) ) {
            return false;
        }

        try {
            $order = directorist_make( \Directorist\Repositories\OrderRepository::class )->get_query_builder()->where( 'id', absint( $order_id ) )->first();
        } catch ( Throwable $e ) {
            return false;
        }

        return $order && (int) $order->ref === directorist_pricing_plans_legacy_plan_id( $plan_id );
    }
}

if ( ! function_exists( 'directorist_validate_date' ) ) {
    function directorist_validate_date( $str ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        return (bool) strtotime( (string) $str );
    }
}

if ( ! function_exists( 'directorist_get_plan_sorting_order' ) ) {
    function directorist_get_plan_sorting_order( $plan_id ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        $plan = directorist_pricing_plans_legacy_plan( $plan_id );
        return $plan ? (int) $plan->listing_display_priority : 0;
    }
}

if ( ! function_exists( 'directorist_pp_is_active_migration' ) ) {
    function directorist_pp_is_active_migration(): bool {
        return (bool) get_option( 'directorist_plans_migration_active', false );
    }
}

if ( ! function_exists( 'directorist_pp_update_migration_active_status' ) ) {
    function directorist_pp_update_migration_active_status( bool $status ): void {
        update_option( 'directorist_plans_migration_active', $status );
    }
}

if ( ! function_exists( 'directorist_pp_is_version_migrated' ) ) {
    function directorist_pp_is_version_migrated( string $version ): bool {
        $versions = get_option( 'directorist_pricing_plans_migrations', [] );
        return is_array( $versions ) && in_array( $version, $versions, true );
    }
}

if ( ! function_exists( 'directorist_pp_update_version_migration_status' ) ) {
    function directorist_pp_update_version_migration_status( string $version, bool $status ): void {
        $versions = get_option( 'directorist_pricing_plans_migrations', [] );
        $versions = is_array( $versions ) ? $versions : [];

        if ( $status && ! in_array( $version, $versions, true ) ) {
            $versions[] = $version;
        }

        if ( ! $status ) {
            $versions = array_values( array_diff( $versions, [ $version ] ) );
        }

        update_option( 'directorist_pricing_plans_migrations', $versions, false );
    }
}

if ( ! function_exists( 'directorist_pp_get_plans_total_listings_count' ) ) {
    function directorist_pp_get_plans_total_listings_count( int $plan_id ): int {
        global $wpdb;

        $plan_id = directorist_pricing_plans_legacy_plan_id( $plan_id );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
                directorist_plan_key(),
                $plan_id
            )
        );
    }
}

if ( ! function_exists( 'directorist_pricing_plan_set_expiry' ) ) {
    function directorist_pricing_plan_set_expiry( int $listing_id, int $plan_id ): void {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );

        $plan = directorist_pricing_plans_legacy_plan( $plan_id );

        if ( ! $plan ) {
            return;
        }

        directorist_pricing_plan_apply_plan_listing_expiration( $listing_id, $plan );
    }
}

if ( ! function_exists( 'atpp_add_listing_page_link_with_plan' ) ) {
    function atpp_add_listing_page_link_with_plan( $plan_id, $is_active = false ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );

        $page_id = function_exists( 'get_directorist_option' ) ? (int) get_directorist_option( 'add_listing_page', 0 ) : 0;
        $link    = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        return apply_filters( 'atbdp_add_listing_page_url', add_query_arg( 'plan', directorist_pricing_plans_legacy_plan_id( $plan_id ), $link ) );
    }
}

if ( ! function_exists( 'directorist_plans_dashboard_data' ) ) {
    function directorist_plans_dashboard_data( $data = 'package' ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__, 'directorist_user_package_repository' );

        $directory_id = function_exists( 'default_directory_type' ) ? (int) default_directory_type() : 0;
        try {
            $package = $directory_id ? directorist_get_current_package( $directory_id ) : null;
        } catch ( Throwable $e ) {
            $package = null;
        }

        if ( 'order' === $data ) {
            return $package && ! empty( $package->last_order_id ) ? [ $package->last_order_id ] : [];
        }

        return $package ? [ $package ] : [];
    }
}

if ( ! function_exists( 'directorist_validate_plan' ) ) {
    function directorist_validate_plan( $plan_id, $post_id, $order_id, $listing_type, $user_id = '', $gift_plan = false ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        update_post_meta( absint( $post_id ), directorist_plan_key(), directorist_pricing_plans_legacy_plan_id( $plan_id ) );
        do_action( 'atbdp_plan_assigned', absint( $post_id ) );
        return true;
    }
}

if ( ! function_exists( 'subscribed_package_or_PPL_plans' ) ) {
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Legacy public API.
    function subscribed_package_or_PPL_plans( $user_id, $order_status, $plan_id, $listing_id = null ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        try {
            return directorist_user_package_repository()->get_package_by_plan( absint( $user_id ), directorist_pricing_plans_legacy_plan_id( $plan_id ) );
        } catch ( Throwable $e ) {
            return null;
        }
    }
}

if ( ! function_exists( 'listings_data_with_plan' ) ) {
    function listings_data_with_plan( $user_id, $featured, $plan_id, $order_id = null ) {
        directorist_pricing_plans_deprecated_function( __FUNCTION__ );
        return [];
    }
}
