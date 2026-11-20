<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type;
use DirectoristPricingPlan\App\Services\ListingPlanAssignmentService;
use DirectoristPricingPlan\App\Services\ExpiredListingRenewalService;
use Directorist\MCP\Tool_Providers\Listings\Listing_Policy;

/** Admit only a checked native allowance path; never turn the fee-manager gate off. */
final class Entitlements {
    private static $admitted = null;

    public static function service(): ListingPlanAssignmentService {
        return directorist_pricing_plans_singleton( ListingPlanAssignmentService::class );
    }

    public static function renewal(): ExpiredListingRenewalService {
        return directorist_pricing_plans_singleton( ExpiredListingRenewalService::class );
    }

    public static function payment_adapter(): void {
        // These engines have separate purchase/order contracts, which V1 does not certify.
        $plugins = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        foreach ( $plugins as $plugin ) {
            Access::check( ! preg_match( '#(?:woocommerce|directorist-coupon|directorist-woocommerce|directorist-stripe|directorist-paypal)#i', $plugin ), 'The active payment integration is not certified for MCP entitlement writes. Use native checkout.', 'adapter_unavailable' );
        }
    }

    public static function check_package( \stdClass $package, \stdClass $plan, \WP_Post $listing ): void {
        Access::check( (int) $package->user_id === (int) $listing->post_author && (int) $package->plan_id === (int) $plan->id && (int) $package->directory_type_id === directorist_get_listings_directory_type( $listing->ID ), 'Package is incompatible with this listing.', 'entitlement_blocked' );
        Access::check( Reader::active( $package ), 'Package is inactive or expired.', 'entitlement_blocked' );
        Access::check( empty( $package->is_legacy ), 'Legacy entitlements require the native workflow.', 'adapter_unavailable' );
        $order = directorist_order_repository()->get_by_id( (int) $package->last_order_id );
        Access::check( $order && 'paid' === $order->status && (int) $order->user_id === (int) $listing->post_author && (int) $order->ref === (int) $plan->id && 'pricing_plan' === $order->ref_type, 'Package has no matching paid entitlement.', 'entitlement_blocked' );
        $selected = directorist_user_package_repository()->get_package_by_plan( (int) $listing->post_author, (int) $plan->id );
        Access::check( $selected && (int) $selected->id === (int) $package->id, 'Native package selection changed. Select the current package.', 'entitlement_blocked' );
    }

    public static function describe( int $listing_id ): array {
        $listing = Access::listing( $listing_id );
        $package = directorist_get_listing_package( $listing_id );
        $options = self::service()->get_compatible_plans( $listing_id, (int) $listing->post_author );
        $ids = [];
        foreach ( $options as $option ) {
            $candidate = directorist_user_package_repository()->get_package_by_plan( (int) $listing->post_author, (int) $option['id'] );
            $candidate_plan = $candidate ? directorist_get_pricing_plan_by_id( (int) $candidate->plan_id ) : null;
            $status = directorist_get_directory_meta( directorist_get_listings_directory_type( $listing_id ), 'edit_listing_status' );
            if ( $candidate && $candidate_plan && Reader::active( $candidate ) && ( 'publish' === $status || ! empty( $candidate_plan->is_allowed_unlimited_listings ) ) ) {
                $ids[] = (int) $candidate->id;
            }
        }
        $plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );
        $plan = $plan_id ? directorist_get_pricing_plan_by_id( $plan_id ) : null;
        $checkout = $plan && Type::PAY_PER_LISTING === $plan->type;
        $summary = self::renewal()->get_listing_summary( $listing_id, (int) $listing->post_author );
        return [
            'listing_id' => $listing_id, 'plan_id' => $plan_id, 'package_id' => $package ? (int) $package->id : 0,
            'directory_type_id' => directorist_get_listings_directory_type( $listing_id ),
            'expiration' => (string) get_post_meta( $listing_id, '_expiry_date', true ),
            'compatible_package_ids' => array_slice( array_values( array_unique( $ids ) ), 0, 100 ),
            'renewable' => (bool) $summary && ! $checkout, 'checkout_required' => (bool) $checkout,
            'reasons' => $summary && ! $checkout ? [] : [ $checkout ? 'Renewal requires native checkout.' : 'No currently renewable entitlement; inspect package status, listing status and quota.' ],
        ];
    }

    public static function checkout( int $listing_id, int $plan_id ): array {
        return [ 'listing_id' => $listing_id, 'changed' => false, 'renewed' => false, 'checkout_required' => true, 'committed' => false, 'checkout_url' => directorist_get_checkout_page_url( 'plan', [ 'plan_id' => $plan_id, 'listing_id' => $listing_id ] ) ];
    }

    public static function prepare( string $action, array $args ): array {
        self::payment_adapter();
        $listing = Access::listing( (int) $args['listing_id'] );
        $listing_id = (int) $listing->ID;
        $admin_mode = 'admin_assignment' === ( $args['mode'] ?? '' );
        Access::check( ! $admin_mode || Access::admin(), 'Administrative assignment requires an administrator.', 'forbidden' );
        $package = isset( $args['package_id'] ) ? Access::package( (int) $args['package_id'] ) : null;
        Access::check( $package || $admin_mode || ( 'assign-listing-plan' === $action && isset( $args['plan_id'] ) ), 'Select an owned package or an eligible plan.' );
        $plan_id = (int) ( $args['plan_id'] ?? ( $package->plan_id ?? 0 ) );
        $plan = 'renew-listing' === $action ? directorist_get_pricing_plan_by_id( $plan_id ) : Access::plan( $plan_id );
        Access::check( (bool) $plan, 'Assigned plan is unavailable.', 'not_found' );
        Access::check( in_array( $plan->type, Type::all(), true ), 'Unsupported native plan type.', 'adapter_unavailable' );
        Access::check( (int) $plan->directory_type_id === directorist_get_listings_directory_type( $listing_id ), 'Plan belongs to another directory.', 'entitlement_blocked' );
        if ( ! $package ) {
            $package = directorist_user_package_repository()->get_package_by_plan( (int) $listing->post_author, $plan_id );
        }
        if ( $package ) {
            self::check_package( $package, $plan, $listing );
        }
        $renew = 'renew-listing' === $action;
        $checkout = ! $package && ! $admin_mode;
        $summary = null;
        if ( $renew ) {
            Access::check( (int) get_post_meta( $listing_id, directorist_plan_key(), true ) === $plan_id && 'expired' === $listing->post_status, 'Only an expired listing under its assigned plan can be renewed.', 'entitlement_blocked' );
            $assigned = directorist_get_listing_package( $listing_id );
            Access::check( $assigned && (int) $assigned->id === (int) $package->id, 'Select the assigned package.', 'entitlement_blocked' );
            $summary = self::renewal()->get_listing_summary( $listing_id, (int) $listing->post_author );
            $checkout = Type::PAY_PER_LISTING === $plan->type;
            Access::check( $checkout || (bool) $summary, 'Native renewal is blocked by lifecycle or quota.', 'entitlement_blocked' );
        } else {
            Access::check( (bool) $plan->is_published && directorist_plan_has_listing_quota( $plan ), 'Plan is not Published or has no listing allowance.', 'entitlement_blocked' );
            Access::check( $plan_id !== (int) get_post_meta( $listing_id, directorist_plan_key(), true ), 'Plan is already assigned.', 'entitlement_blocked' );
            if ( ! $admin_mode ) {
                $options = self::service()->get_compatible_plans( $listing_id, (int) $listing->post_author );
                Access::check( $checkout || Type::PAY_PER_LISTING === $plan->type || in_array( $plan_id, array_map( 'intval', array_column( $options, 'id' ) ), true ), 'No eligible purchased allowance remains.', 'entitlement_blocked' );
                $checkout = $checkout || Type::PAY_PER_LISTING === $plan->type;
            } else {
                Access::check( ! $plan->is_subscription_enabled && ! $plan->is_trial_enabled, 'Administrative subscription and trial grants require the native workflow.', 'adapter_unavailable' );
                if ( ! $package ) {
                    Access::check( ! \DirectoristPricingPlan\App\Models\UserPackage::query()->where( 'user_id', (int) $listing->post_author )->where( 'plan_id', $plan_id )->count(), 'Native activation would reactivate a historical package. Use the native workflow.', 'unsupported_operation' );
                    Access::check( directorist_allow_multiple_plans_per_directory_type() || ! directorist_user_package_repository()->get_active_packages_for_directory( (int) $listing->post_author, (int) $plan->directory_type_id ), 'An active package already exists in this directory.', 'entitlement_blocked' );
                    // Native activation can rewrite orphaned listings. Do not hide that larger operation.
                    $other = get_posts( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => array_keys( get_post_stati() ), 'author' => (int) $listing->post_author, 'post__not_in' => [ $listing_id ], 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => directorist_plan_key(), 'meta_value' => $plan_id ] );
                    Access::check( ! $other, 'Native activation would affect other listings. Use the native administrative workflow.', 'unsupported_operation' );
                }
            }
            if ( Type::PACKAGE === $plan->type ) {
                $usage = directorist_package_usage();
                $total = $usage->get_total_uses( (int) $listing->post_author, $plan );
                $featured = $usage->get_featured_uses( (int) $listing->post_author, $plan );
                Access::check( 0 !== (int) $total['remaining'] && ( ! directorist_is_listing_featured( $listing_id ) || 0 !== (int) $featured['remaining'] ), 'No remaining listing or featured allowance.', 'entitlement_blocked' );
            }
        }
        $state = [
            'listing' => (array) $listing, 'meta' => get_post_meta( $listing_id ),
            'plan' => (array) $plan, 'features' => Reader::features( $plan ),
            'package' => $package ? Reader::package( $package ) : [],
            'packages' => directorist_user_package_repository()->get_active_packages_for_directory( (int) $listing->post_author, (int) $plan->directory_type_id ),
            'summary' => $summary, 'settings' => get_option( 'atbdp_option', [] ),
            'directory' => get_term_meta( (int) $plan->directory_type_id ), 'currency' => atbdp_get_payment_currency(),
        ];
        $status = $renew || $admin_mode ? 'publish' : directorist_get_directory_meta( (int) $plan->directory_type_id, 'edit_listing_status' );
        $status = in_array( $status, [ 'publish', 'pending' ], true ) ? $status : 'pending';
        Access::check( $checkout || $renew || $admin_mode || 'publish' === $status || ! empty( $plan->is_allowed_unlimited_listings ), 'Native pending assignments do not reserve limited quota. Use the native review workflow for this directory.', 'adapter_unavailable' );
        $creates_order = ! $checkout && $admin_mode && ( ! $package || Type::PAY_PER_LISTING === $plan->type );
        $tax_amount = $plan->is_taxable && (float) $plan->price > 0 ? (float) directorist_compute_fixed_or_percent_amount( $plan->tax_type, (float) $plan->tax_rate, (float) $plan->price ) : 0.0;
        $preview = [
            'listing_id' => $listing_id, 'beneficiary_user_id' => (int) $listing->post_author,
            'plan_id' => $plan_id, 'package_id' => $package ? (int) $package->id : 0,
            'creates_package' => ! $checkout && ! $package, 'creates_paid_order' => $creates_order,
            'grants_entitlement' => ! $checkout && $admin_mode, 'checkout_required' => $checkout,
            'amount' => $creates_order || $checkout ? (float) $plan->price + $tax_amount : 0.0,
            'subtotal' => $creates_order || $checkout ? (float) $plan->price : 0.0,
            'tax_amount' => $creates_order || $checkout ? $tax_amount : 0.0, 'payment_dispatch' => false,
            'interval_type' => (string) $plan->interval_type, 'interval_count' => (int) $plan->interval_count,
            'expiration_source' => $package && Type::PACKAGE === $plan->type ? 'package_period_end' : 'native_plan_duration', 'currency' => atbdp_get_payment_currency(), 'precision' => 2,
            'featured' => $summary ? (bool) $summary['will_be_featured'] : ( Type::PAY_PER_LISTING === $plan->type ? (bool) $plan->is_featured : directorist_is_listing_featured( $listing_id ) ),
            'expiration' => (string) ( $package->current_period_end ?? '' ), 'resulting_status' => $status,
        ];
        return [ 'state' => $state, 'preview' => $preview, 'execute' => static function () use ( $action, $args, $listing, $plan, $package, $renew, $admin_mode, $checkout, $preview ): array {
            if ( $checkout ) {
                return self::checkout( (int) $listing->ID, (int) $plan->id );
            }
            // This capability is private to this invocation and checked again by the core gate.
            self::$admitted = [ 'listing_id' => (int) $listing->ID, 'directory' => (int) $plan->directory_type_id, 'operation' => $renew ? 'renew' : 'assign', 'package' => $package, 'plan' => $plan, 'admin_mode' => $admin_mode ];
            try {
                Listing_Policy::policy( $listing, (int) $plan->directory_type_id, self::$admitted['operation'] );
                $result = Outbox::capture( static function () use ( $listing, $plan, $renew, $admin_mode ) {
                    if ( $renew ) {
                        return self::renewal()->renew_listing( (int) $listing->ID, (int) $listing->post_author );
                    }
                    if ( $admin_mode ) {
                        return get_post_meta( $listing->ID, directorist_plan_key(), true )
                            ? self::service()->assign_admin_listing_plan( (int) $listing->ID, (int) $plan->id )
                            : self::service()->assign_unassigned_listing( (int) $listing->ID, (int) $plan->id );
                    }
                    return self::service()->assign( (int) $listing->ID, (int) $plan->id, (int) $listing->post_author, false );
                } );
                Access::check( empty( $result['requires_payment'] ), 'Native flow requires checkout.', 'entitlement_blocked' );
                Access::check( get_post_status( $listing->ID ) === $preview['resulting_status'] && (int) get_post_meta( $listing->ID, directorist_plan_key(), true ) === (int) $plan->id, 'Native entitlement save did not match preview.', 'operation_failed' );
                $assigned = directorist_get_listing_package( (int) $listing->ID );
                Access::check( (bool) $assigned, 'Native assignment did not retain an active package.', 'operation_failed' );
                self::check_package( $assigned, $plan, get_post( $listing->ID ) );
                $output = [ 'listing_id' => (int) $listing->ID, 'package_id' => $assigned ? (int) $assigned->id : 0, 'order_id' => (int) ( $result['order_id'] ?? 0 ), 'changed' => true, 'renewed' => $renew, 'checkout_required' => false, 'expiration' => (string) get_post_meta( $listing->ID, '_expiry_date', true ) ];
                if ( ! $output['order_id'] ) { unset( $output['order_id'] ); }
                return $output;
            } finally {
                self::$admitted = null;
            }
        } ];
    }

    public static function listing_policy( $allowed, $post, $directory, $operation, $data ) {
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        if ( 'publish' === $operation && $post ) {
            self::publication_policy( $post );
        }
        $context = self::$admitted;
        if ( ! $context || ! $post || $context['listing_id'] !== (int) $post->ID || $context['directory'] !== (int) $directory || $context['operation'] !== $operation || ! \Directorist\MCP\Safety\Write_Guard::is_mutating() ) {
            return $allowed;
        }
        Access::listing( (int) $post->ID );
        self::payment_adapter();
        if ( $context['package'] ) {
            self::check_package( Access::package( (int) $context['package']->id ), directorist_get_pricing_plan_by_id( (int) $context['plan']->id ), $post );
        } else {
            Access::check( $context['admin_mode'] && Access::admin() && (bool) $context['plan']->is_published, 'No administrative entitlement grant is authorized.', 'entitlement_blocked' );
        }
        return true;
    }

    /** Check native publication requirements without running approval side effects. */
    private static function publication_policy( \WP_Post $post ): void {
        $package = directorist_get_listing_package( (int) $post->ID );
        if ( ! $package ) {
            Access::check( current_user_can( 'edit_others_at_biz_dirs' ), 'No active plan found. Assign a package before publishing.', 'entitlement_blocked' );
            return;
        }
        Access::check( Reader::active( $package ), 'Package is inactive or expired.', 'entitlement_blocked' );
        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );
        Access::check( (bool) $plan, 'The plan associated with this package no longer exists.', 'entitlement_blocked' );
        if ( Type::PAY_PER_LISTING === ( $plan->type ?? Type::PACKAGE ) ) {
            $order = directorist_order_repository()->get_query_builder()
                ->where( 'listing_id', (int) $post->ID )
                ->where( 'plan_id', (int) $plan->id )
                ->where( 'status', 'paid' )
                ->first();
            Access::check( (bool) $order, 'Payment is required for this listing.', 'entitlement_blocked' );
            return;
        }
        $provider = directorist_pricing_plans_singleton( \DirectoristPricingPlan\App\Providers\PlanServiceProvider::class );
        Access::check( $provider->has_plan_remaining_quota( true, $plan, false ), 'You have reached the maximum number of allowed listings.', 'entitlement_blocked' );
    }
}
