<?php

defined( 'ABSPATH' ) || exit;

use Directorist\Repositories\OrderRepository;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Contracts\PaymentInterface;

use DirectoristPricingPlan\DI\Container;
use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\App\Providers\ShortcodeServiceProvider;
use DirectoristPricingPlan\App\Contracts\PackageUsageInterface;
use DirectoristPricingPlan\App\Repositories\UsesRepository;
use DirectoristPricingPlan\App\Repositories\LegacyUsesRepository;
use DirectoristPricingPlan\App\Enums\Plan\Interval;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\App\Enums\Plan\TaxType as PlanTaxType;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\DTO\UserPackage\Activation as UserPackageActivationDTO;
use DirectoristPricingPlan\App\DTO\UserPackage\DTO as UserPackageDTO;
use DirectoristPricingPlan\App\Repositories\UserPackageRepository;
use DirectoristPricingPlan\App\Repositories\SubscriptionLogRepository;
use DirectoristPricingPlan\App\Models\Plan as PlanModel;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;

function directorist_pricing_plan():App {
    return App::$instance;
}

function directorist_pricing_plans_config( string $config_key ) {
    return directorist_pricing_plan()::$config->get( $config_key );
}

function directorist_pricing_plans_app_config( string $config_key ) {
    return directorist_pricing_plans_config( "app.{$config_key}" );
}

function directorist_pricing_plans_version() {
    return directorist_pricing_plans_app_config( 'version' );
}

function directorist_pricing_plans_container():Container {
    return directorist_pricing_plan()::$container;
}

/**
 * @template T
 * @param class-string<T> $class
 * @return T
 */
function directorist_pricing_plans_singleton( string $class ) {
    return directorist_pricing_plans_container()->get( $class );
}

function directorist_pricing_plans_url( string $url = '' ) {
    return directorist_pricing_plan()->get_url( $url );
}

function directorist_pricing_plans_dir( string $dir = '' ) {
    return directorist_pricing_plan()->get_dir( $dir );
}

function directorist_pricing_plan_repository() {
    return directorist_pricing_plans_singleton( PlanRepository::class );
}

function directorist_user_package_repository(): UserPackageRepository {
    return directorist_pricing_plans_singleton( UserPackageRepository::class );
}

function directorist_subscription_log_repository(): SubscriptionLogRepository {
    return directorist_pricing_plans_singleton( SubscriptionLogRepository::class );
}

function directorist_get_pricing_plan_by_id( int $id ) {
    return directorist_pricing_plan_repository()->get_by_id( $id );
}

function directorist_plan_has_listing_quota( ?stdClass $plan ): bool {
    if ( ! $plan ) {
        return false;
    }

    if ( ( $plan->type ?? \DirectoristPricingPlan\App\Enums\Plan\Type::PACKAGE ) === \DirectoristPricingPlan\App\Enums\Plan\Type::PAY_PER_LISTING ) {
        return true;
    }

    return ! empty( $plan->is_allowed_unlimited_listings ) || (int) $plan->allowed_listings > 0;
}

function directorist_plan_no_listing_quota_message(): string {
    return __( 'This plan has no listing quota and cannot be purchased. Please choose another plan or contact the site administrator.', 'directorist-pricing-plans' );
}

function directorist_is_listing_feature_available( string $feature_key, ?int $listing_id = null, ?int $user_id = null ): bool {
    $listing_id = $listing_id ?? (int) get_the_ID();

    if ( ! $listing_id ) {
        return true;
    }

    /**
     * @var \DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository $plan_feature_repository
     */
    $plan_feature_repository = directorist_pricing_plans_singleton( \DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository::class );

    $package = directorist_get_listing_package( $listing_id );
    $plan    = $package ? directorist_get_pricing_plan_by_id( $package->plan_id ) : null;

    if ( ! $plan ) {
        return true;
    }

    $plan_features     = $plan_feature_repository->get( $plan );
    $plan_feature_keys = array_column( $plan_features, 'key' );
    $feature_index     = array_search( $feature_key, $plan_feature_keys );

    if ( $feature_index === false ) {
        return true;
    }

    return (bool) $plan_features[ $feature_index ]->is_enabled;
}

function directorist_plan_key(): string {
    return '_plan_id';
}

/**
 * Get the active package assigned to a listing.
 *
 * @param int $listing_id Listing post ID.
 * @return stdClass|null
 */
function directorist_get_listing_package( int $listing_id ): ?stdClass {
    return directorist_user_package_repository()->get_listings_package( $listing_id );
}

function directorist_get_current_package( int $directory_type_id, ?int $user_id = null ): ?stdClass {
    return directorist_user_package_repository()->get_current_package( $user_id ?? get_current_user_id(), $directory_type_id );
}

function directorist_is_package_active( stdClass $package ) {
    return in_array( $package->status, [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] );
}

function directorist_get_expiry_date( int $interval_count, string $interval_type ): ?string {
    if ( $interval_count <= 0 ) {
        return null;
    }

    $interval_period_map = [
        Interval::DAY   => 'D',
        Interval::WEEK  => 'W',
        Interval::MONTH => 'M',
        Interval::YEAR  => 'Y',
    ];
    
    if ( ! isset( $interval_period_map[ $interval_type ] ) ) {
        return null;
    }

    $interval_period = $interval_period_map[ $interval_type ];
    $duration        = (int) $interval_count;

    $start_date = current_time( 'mysql' );
    $date       = new DateTime( $start_date );

    $date->add( new DateInterval( "P{$duration}{$interval_period}" ) );

    return $date->format( 'Y-m-d H:i:s' );
}

function directorist_pricing_plan_set_listing_never_expire( int $listing_id ): void {
    update_post_meta( $listing_id, '_never_expire', '1' );
    delete_post_meta( $listing_id, '_expiry_date' );
}

function directorist_pricing_plan_set_listing_expiry_date( int $listing_id, string $expiry_date ): void {
    delete_post_meta( $listing_id, '_never_expire' );
    update_post_meta( $listing_id, '_expiry_date', $expiry_date );
}

function directorist_pricing_plan_is_listing_published( int $listing_id ): bool {
    return 'publish' === get_post_status( $listing_id );
}

function directorist_pricing_plan_apply_package_listing_expiration( int $listing_id, ?string $current_period_end = null ): void {
    if ( ! directorist_pricing_plan_is_listing_published( $listing_id ) ) {
        return;
    }

    if ( empty( $current_period_end ) ) {
        directorist_pricing_plan_set_listing_never_expire( $listing_id );
        return;
    }

    directorist_pricing_plan_set_listing_expiry_date( $listing_id, $current_period_end );
}

function directorist_pricing_plan_apply_plan_listing_expiration( int $listing_id, stdClass $plan ): void {
    if ( ! directorist_pricing_plan_is_listing_published( $listing_id ) ) {
        return;
    }

    if ( Interval::LIFETIME === $plan->interval_type ) {
        directorist_pricing_plan_set_listing_never_expire( $listing_id );
        return;
    }

    $expiry_date = directorist_get_expiry_date( (int) $plan->interval_count, (string) $plan->interval_type );

    if ( $expiry_date ) {
        directorist_pricing_plan_set_listing_expiry_date( $listing_id, $expiry_date );
    }
}

function directorist_to_timestamp( string $date_time ): ?int {
    $timezone = wp_timezone();
    $datetime = date_create_from_format( 'Y-m-d H:i:s', $date_time, $timezone );

    if ( ! $datetime ) {
        return null;
    }

    return $datetime->getTimestamp();
}

function directorist_user_has_pending_order( int $user_id, ?int $directory_type_id = null ): bool {
    /**
     * @var OrderRepository
     */
    $order_repository = directorist_make( OrderRepository::class );
    
    $pending_order_query = $order_repository
        ->get_query_builder()
        ->select( 'd_order.*', 'plan.directory_type_id' )
        ->left_join( PlanModel::get_table_name() . ' as plan', 'd_order.ref', '=', 'plan.id' )
        ->where( 'user_id', $user_id )
        ->where( 'd_order.ref_type', 'pricing_plan' )
        ->where( 'd_order.status', OrderStatus::PENDING );

    if ( $directory_type_id ) {
        $pending_order_query->where( 'plan.directory_type_id', $directory_type_id );
    }

    $pending_order = $pending_order_query->first();

    return $pending_order ? true : false;
}

function directorist_current_user_has_pending_order( ?int $directory_type_id = null ): bool {
    return directorist_user_has_pending_order( get_current_user_id(), $directory_type_id );
}

function directorist_plan_registered_features( int $directory_type_id ): array {
    $fields   = directorist_directory_fields( $directory_type_id );
    $features = [];

    foreach ( $fields as $field ) {
        if ( empty( $field['field_key'] ) || 'listing_type' === $field['field_key'] ) {
            continue;
        }

        $feature_key = $field['field_key'];

        if ( 'privacy_policy' === $feature_key ) {
            $field['label'] = __( 'Privacy Policy', 'directorist-pricing-plans' );
        }

        if ( 'pricing' === $feature_key ) {
            if ( empty( $field['modules'] ) ) {
                continue;
            }

            foreach ( $field['modules'] as $module ) {
                $module_feature_key = ! empty( $module['field_key'] ) ? $module['field_key'] : '';

                if ( ! $module_feature_key ) {
                    continue;
                }
                
                $features[ $module_feature_key ] = [
                    "name"                     => ! empty( $module['label'] ) ? $module['label'] : $module_feature_key,
                    "is_enabled"               => true,
                    "is_show_in_pricing_table" => false,
                ];
            }
            continue;
        }

        $feature = [
            'name'                     => ! empty( $field['label'] ) ? $field['label'] : $feature_key,
            'is_enabled'               => true,
            'is_show_in_pricing_table' => false,
            'data'                     => [],
        ];

        switch ( $feature_key ) {
            case 'listing_img':
                $feature['data'] = [
                    'is_unlimited' => false,
                    'limit'        => 10,
                ];
                break;
        }

        if ( in_array( $feature_key, [ 'tax_input[at_biz_dir-location][]', 'listing_content', 'excerpt', 'fax' ], true ) || strpos( $feature_key, 'custom-textarea' ) === 0 ) {
            $feature['data'] = [
                'is_unlimited' => true,
                'limit'        => '',
            ];
        }

        $features[ $feature_key ] = $feature;
    }
    
    $features['review'] = [
        "name"                     => "Review",
        "is_enabled"               => true,
        "is_show_in_pricing_table" => false,
    ];
    
    $features['contact_listings_owner'] = [
        "name"                     => "Contact Listing Owner",
        "is_enabled"               => true,
        "is_show_in_pricing_table" => false,
    ];

    return apply_filters( 'directorist_pricing_plans_registered_features', $features );
}

function directorist_directory_fields( int $directory_type_id ) {
    $fields = get_term_meta( $directory_type_id, 'submission_form_fields', true );

    if ( empty( $fields ) || ! is_array( $fields ) || ! isset( $fields['fields'] ) || ! is_array( $fields['fields'] ) ) {
        return [];
    }
    
    return $fields['fields'];
}

function directorist_pricing_plan_activate_package( UserPackageActivationDTO $user_package_activation_dto ): UserPackageDTO {
    return directorist_user_package_repository()->activate_package( $user_package_activation_dto );
}

function directorist_user_has_used_trial( int $user_id, int $directory_type_id ): bool {
    return directorist_user_package_repository()
        ->get_query_builder()
        ->where( 'user_id', $user_id )
        ->where( 'directory_type_id', $directory_type_id )
        ->where( 'is_trial', 1 )
        ->first() ? true : false;
}

function directorist_get_subscription_gateways(): array {
    $payment_processors    = directorist_get_payment_processors();
    $subscription_gateways = [];

    if ( empty( $payment_processors ) || ! is_array( $payment_processors ) ) {
        return $subscription_gateways;
    }

    foreach ( $payment_processors as $key => $class ) {
        if ( ! class_exists( $class ) ) {
            continue;
        }

        /**
         * @var PaymentInterface
         */
        $instance = directorist_make( $class );

        if ( $instance->supports_recurring() ) {
            $subscription_gateways[ $key ] = $class;
        }
    }

    return $subscription_gateways;
}

function directorist_package_usage( bool $is_legacy = false ): PackageUsageInterface {
    return directorist_pricing_plans_singleton( $is_legacy ? LegacyUsesRepository::class : UsesRepository::class );
}

function directorist_has_paid_order_without_listing( int $plan_id, ?int $user_id = null ): bool {
    $order = directorist_get_paid_order_without_listing( $plan_id, $user_id );

    return $order ? true : false;
}

function directorist_get_paid_order_without_listing( int $plan_id, ?int $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }

    /** @var OrderRepository $order_repository */
    $order_repository = directorist_make( OrderRepository::class );

    $order = $order_repository
        ->get_query_builder()
        ->where( 'd_order.user_id', $user_id )
        ->where( 'd_order.ref_type', 'pricing_plan' )
        ->where( 'd_order.ref', $plan_id )
        ->where( 'd_order.status', OrderStatus::PAID )
        ->where_null( 'd_order.listing_id' )
        ->first();

    return $order ? $order : null;
}

function directorist_render_plans( ?int $plan_id = null, $atts = [], bool $echo = false ) {
    $atts['id'] = $plan_id;
    return directorist_pricing_plans_singleton( ShortcodeServiceProvider::class )->render( $atts, $echo );
}

function directorist_is_plan_limit_reached( stdClass $active_package ): bool {
    if ( ! $active_package->uses ) {
        return false;
    }

    if ( -1 === $active_package->uses['listings']['remaining'] ) {
        return false;
    }

    return $active_package->uses['listings']['remaining'] < 1;
}

function directorist_plan_duration_text( stdClass $plan ): string {
    switch ( $plan->interval_type ) {
        case 'day':
            return ( $plan->interval_count > 1 ) ? $plan->interval_count . ' ' . __( 'days', 'directorist-pricing-plans' ) : __( 'day', 'directorist-pricing-plans' );
        case 'week':
            return ( $plan->interval_count > 1 ) ? $plan->interval_count . ' ' . __( 'weeks', 'directorist-pricing-plans' ) : __( 'week', 'directorist-pricing-plans' );
        case 'month':
            return ( $plan->interval_count > 1 ) ? $plan->interval_count . ' ' . __( 'months', 'directorist-pricing-plans' ) : __( 'month', 'directorist-pricing-plans' );
        case 'year':
            return ( $plan->interval_count > 1 ) ? $plan->interval_count . ' ' . __( 'years', 'directorist-pricing-plans' ) : __( 'year', 'directorist-pricing-plans' );
        case 'lifetime':
            return __( 'Lifetime', 'directorist-pricing-plans' );
        default:
            return '';
    }
}

function directorist_get_plan_tax_amount( $plan, $subtotal ) {
    if ( floatval( $plan->tax_rate ) < 1 || empty( $plan->tax_type ) ) {
        return 0;
    }

    if ( ! in_array( $plan->tax_type, PlanTaxType::all() ) ) {
        throw new Exception( __( 'Invalid tax type.', 'directorist-pricing-plans' ) );
    }

    return directorist_compute_fixed_or_percent_amount( $plan->tax_type, $plan->tax_rate, $subtotal );
}

function directorist_is_user_trial_eligible( int $directory_type_id, ?int $user_id = null ): bool {
    $user_id = $user_id ? $user_id : get_current_user_id();

    $active_packages_count = directorist_user_package_repository()->count_active_packages_for_directory(
        $user_id,
        $directory_type_id
    );

    if ( $active_packages_count > 0 ) {
        return false;
    }

    return ! directorist_user_has_used_trial( $user_id, $directory_type_id );
}

function directorist_plan_has_subscription( stdClass $plan ) {
    if ( 1 !== (int) $plan->is_subscription_enabled ) {
        return apply_filters( 'directorist_pricing_plan_has_subscription', false, $plan );
    }

    if ( PlanType::PAY_PER_LISTING === $plan->type ) {
        return apply_filters( 'directorist_pricing_plan_has_subscription', false, $plan );
    }

    if ( Interval::LIFETIME === $plan->interval_type ) {
        return apply_filters( 'directorist_pricing_plan_has_subscription', false, $plan );
    }
    
    if ( (int) $plan->interval_count < 1 ) {
        return apply_filters( 'directorist_pricing_plan_has_subscription', false, $plan );
    }

    return apply_filters( 'directorist_pricing_plan_has_subscription', true, $plan );
}

function directorist_is_plan_trial_eligible( stdClass $plan ) {
    return PlanFeeType::PAID === $plan->fee_type && (float) $plan->price > 0 && (int) $plan->is_trial_enabled === 1 && (int) $plan->trial_interval_count > 0;
}

function directorist_is_trial_eligible( stdClass $plan, ?int $user_id = null ): bool {
    if ( ! directorist_is_plan_trial_eligible( $plan ) ) {
        return false;
    }

    return directorist_is_user_trial_eligible( $plan->directory_type_id, $user_id );
}
