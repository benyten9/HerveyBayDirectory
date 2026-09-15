<?php

namespace DirectoristPricingPlan\App\Services;

defined( 'ABSPATH' ) || exit;

use stdClass;
use WP_Post;
use Directorist\Enums\Order\Status as OrderStatus;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Jobs\UnassignedPlanOrderQueue;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

class ListingPlanAssignmentService {
    /**
     * Return published plans grouped by whether the listing owner has an active package.
     *
     * @return array{active: array<int, array{id: int, title: string, type: string}>, inactive: array<int, array{id: int, title: string, type: string}>}
     */
    public function get_admin_plan_groups( int $listing_id ): array {
        $listing           = $this->get_listing( $listing_id );
        $directory_type_id = directorist_get_listings_directory_type( $listing_id );
        $current_plan_id   = (int) get_post_meta( $listing_id, directorist_plan_key(), true );
        $groups            = [
            'active'   => [],
            'inactive' => [],
        ];

        if ( ! $directory_type_id ) {
            return $groups;
        }

        $package_repository = directorist_user_package_repository();
        $active_packages    = $package_repository->get_active_packages_for_directory(
            (int) $listing->post_author,
            $directory_type_id
        );
        $active_plan_ids    = array_fill_keys( array_map( 'intval', array_column( $active_packages, 'plan_id' ) ), true );
        $current_is_active  = isset( $active_plan_ids[ $current_plan_id ] );
        $can_create_package = directorist_allow_multiple_plans_per_directory_type() || empty( $active_packages );

        $plans = Plan::query()
            ->where( 'directory_type_id', $directory_type_id )
            ->where( 'is_published', 1 )
            ->order_by( 'title', 'asc' )
            ->get();

        foreach ( $plans as $plan ) {
            if ( (int) $plan->id === $current_plan_id && $current_is_active ) {
                continue;
            }

            $item = [
                'id'    => (int) $plan->id,
                'title' => (string) $plan->title,
                'type'  => (string) ( $plan->type ?? PlanType::PACKAGE ),
            ];

            if ( isset( $active_plan_ids[ (int) $plan->id ] ) ) {
                $groups['active'][] = $item;
                continue;
            }

            if ( $can_create_package ) {
                $groups['inactive'][] = $item;
            }
        }

        return $groups;
    }

    /**
     * Assign an unassigned listing to an active package, or purchase and activate a new package.
     *
     * @return array{changed: bool, order_id?: int, package_id: int}
     */
    public function assign_unassigned_listing( int $listing_id, int $plan_id ): array {
        $listing         = $this->get_listing( $listing_id );
        $current_plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );
        $current_package = directorist_get_listing_package( $listing_id );

        if ( $current_plan_id && $current_package && (int) $current_package->plan_id === $current_plan_id ) {
            throw new Exception( esc_html__( 'This listing already has a plan assigned.', 'directorist-pricing-plans' ), 409 );
        }

        return $this->assign_admin_plan( $listing, $plan_id );
    }

    /**
     * Change an assigned listing to an active package or a newly activated package.
     *
     * @return array{changed: bool, order_id?: int, package_id: int}
     */
    public function assign_admin_listing_plan( int $listing_id, int $plan_id ): array {
        $listing         = $this->get_listing( $listing_id );
        $current_plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );

        if ( ! $current_plan_id ) {
            throw new Exception( esc_html__( 'This listing does not have a plan assigned.', 'directorist-pricing-plans' ), 409 );
        }

        if ( $current_plan_id === $plan_id ) {
            throw new Exception( esc_html__( 'The selected plan is already assigned to this listing.', 'directorist-pricing-plans' ), 409 );
        }

        return $this->assign_admin_plan( $listing, $plan_id );
    }

    public function remove_package_plan( int $listing_id, ?int $requesting_user_id = null ): void {
        $this->get_listing( $listing_id, $requesting_user_id );

        $plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );

        if ( ! $plan_id ) {
            throw new Exception( esc_html__( 'This listing does not have a plan assigned.', 'directorist-pricing-plans' ), 409 );
        }

        $plan = directorist_get_pricing_plan_by_id( $plan_id );

        if ( ! $plan ) {
            throw new Exception( esc_html__( 'The assigned plan is not available anymore.', 'directorist-pricing-plans' ), 404 );
        }

        if ( PlanType::PACKAGE !== ( $plan->type ?? PlanType::PACKAGE ) ) {
            throw new Exception( esc_html__( 'Only package plan assignments can be removed.', 'directorist-pricing-plans' ), 400 );
        }

        if ( ! delete_post_meta( $listing_id, directorist_plan_key() ) ) {
            throw new Exception( esc_html__( 'The listing could not be removed from its plan.', 'directorist-pricing-plans' ), 400 );
        }

        if ( ! directorist_set_listing_status( $listing_id, 'expired' ) ) {
            update_post_meta( $listing_id, directorist_plan_key(), $plan_id );
            throw new Exception( esc_html__( 'The listing could not be expired after removing its plan.', 'directorist-pricing-plans' ), 400 );
        }

        directorist_pricing_plan_set_listing_expiry_date( $listing_id, current_time( 'mysql' ) );
        update_post_meta( $listing_id, '_listing_status', 'post_status' );

        do_action( 'directorist_listing_plan_removed', $listing_id, $plan_id );
    }

    /**
     * @return array{changed: bool, order_id?: int, package_id: int}
     */
    private function assign_admin_plan( WP_Post $listing, int $plan_id ): array {
        $listing_id = (int) $listing->ID;
        $plan       = directorist_get_pricing_plan_by_id( $plan_id );

        if ( ! $plan || 1 !== (int) $plan->is_published ) {
            throw new Exception( esc_html__( 'The selected plan is not available anymore.', 'directorist-pricing-plans' ), 400 );
        }

        $directory_type_id = directorist_get_listings_directory_type( $listing_id );

        if ( ! $directory_type_id || (int) $plan->directory_type_id !== $directory_type_id ) {
            throw new Exception( esc_html__( 'The selected plan does not belong to this listing directory.', 'directorist-pricing-plans' ), 400 );
        }

        $package_repository = directorist_user_package_repository();
        $package            = $package_repository->get_package_by_plan( (int) $listing->post_author, $plan_id );

        $this->validate_plan_quota( $listing, $plan );

        if ( $package ) {
            $result = [
                'changed'    => true,
                'package_id' => (int) $package->id,
            ];

            if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ) {
                $result['order_id'] = directorist_pricing_plans_singleton( ListingOrderService::class )
                    ->create_assignment_order( $listing_id, $package, $plan );
            }

            $this->apply_assignment( $listing_id, $package, $plan, true );

            return $result;
        }

        if ( ! directorist_allow_multiple_plans_per_directory_type() ) {
            $active_packages = $package_repository->get_active_packages_for_directory(
                (int) $listing->post_author,
                $directory_type_id
            );

            if ( ! empty( $active_packages ) ) {
                throw new Exception( esc_html__( 'An active package already exists for this directory type.', 'directorist-pricing-plans' ), 400 );
            }
        }

        /** @var UnassignedPlanOrderQueue $assignment */
        $assignment = directorist_pricing_plans_singleton( UnassignedPlanOrderQueue::class );
        $order_id   = (int) $assignment->create_order(
            (int) $listing->post_author,
            $plan,
            OrderStatus::PAID,
            atbdp_get_payment_currency(),
            false,
            $listing_id
        );

        if ( ! $order_id ) {
            throw new Exception( esc_html__( 'Failed to create a paid order for the selected plan.', 'directorist-pricing-plans' ), 400 );
        }

        $assignment->activate_package( (int) $listing->post_author, $plan, (string) $order_id );
        $package = $package_repository->get_package_by_plan( (int) $listing->post_author, $plan_id );

        if ( ! $package ) {
            throw new Exception( esc_html__( 'The new package could not be activated.', 'directorist-pricing-plans' ), 400 );
        }

        $this->apply_assignment( $listing_id, $package, $plan, true );

        return [
            'changed'    => true,
            'order_id'   => $order_id,
            'package_id' => (int) $package->id,
        ];
    }

    /**
     * Return dashboard plans grouped by owned active packages and plans available to purchase.
     *
     * @return array{has_assigned_plan: bool, my_plans: array<int, array<string, mixed>>, available_plans: array<int, array<string, mixed>>, show_my_plans: bool, show_available_plans: bool}
     */
    public function get_dashboard_plan_options( int $listing_id, ?int $requesting_user_id = null ): array {
        $listing           = $this->get_listing( $listing_id, $requesting_user_id );
        $directory_type_id = directorist_get_listings_directory_type( $listing_id );
        $current_plan_id   = (int) get_post_meta( $listing_id, directorist_plan_key(), true );
        $has_assigned_plan = $current_plan_id && 'expired' !== $listing->post_status;
        $is_featured       = '1' === (string) get_post_meta( $listing_id, '_featured', true );
        $my_plans          = [];
        $available_plans   = [];

        if ( ! $directory_type_id ) {
            return [
                'has_assigned_plan'    => (bool) $has_assigned_plan,
                'my_plans'             => $my_plans,
                'available_plans'      => $available_plans,
                'show_my_plans'        => false,
                'show_available_plans' => false,
            ];
        }

        $packages             = directorist_user_package_repository()->get_active_packages_for_directory(
            (int) $listing->post_author,
            $directory_type_id
        );
        $allow_multiple       = directorist_allow_multiple_plans_per_directory_type();
        $is_expired           = 'expired' === $listing->post_status;
        $packages_by_plan     = [];
        $show_available_plans = $allow_multiple || ( $is_expired && empty( $packages ) );
        $show_my_plans        = $allow_multiple || ! $show_available_plans;

        foreach ( $packages as $package ) {
            $packages_by_plan[ (int) $package->plan_id ] = $package;
        }

        $plans = Plan::query()
            ->where( 'directory_type_id', $directory_type_id )
            ->where( 'is_published', 1 )
            ->where( 'is_hidden_from_plans_list', 0 )
            ->order_by( 'sort_order', 'asc' )
            ->get();

        foreach ( $plans as $plan ) {
            if ( ! directorist_plan_has_listing_quota( $plan ) ) {
                continue;
            }

            $plan_id        = (int) $plan->id;
            $plan_type      = (string) ( $plan->type ?? PlanType::PACKAGE );
            $active_package = $packages_by_plan[ $plan_id ] ?? null;

            if ( PlanType::PACKAGE === $plan_type && $active_package ) {
                $my_plans[] = $this->build_owned_plan_option(
                    $listing,
                    $plan,
                    $current_plan_id,
                    $is_featured
                );
                continue;
            }

            if ( ! $show_available_plans ) {
                continue;
            }

            $is_current        = $plan_id === $current_plan_id;
            $is_active_current = $is_current && $active_package;

            $available_plans[] = array_merge(
                [
                    'id'                 => $plan_id,
                    'title'              => (string) $plan->title,
                    'type'               => $plan_type,
                    'remaining'          => -1,
                    'featured_remaining' => -1,
                    'is_current'         => $is_current,
                    'is_assignable'      => ! $is_active_current,
                    'status_message'     => $is_active_current
                        ? esc_html__( 'Currently assigned', 'directorist-pricing-plans' )
                        : '',
                    'checkout_url'       => directorist_get_checkout_page_url(
                        'plan',
                        [
                            'plan_id'           => $plan_id,
                            'listing_id'        => $listing_id,
                            'plan_reassignment' => '1',
                            'is_featured'       => ! empty( $plan->is_featured ) ? '1' : '0',
                        ]
                    ),
                ],
                $this->build_plan_display_data( $plan )
            );
        }

        return [
            'has_assigned_plan'    => (bool) $has_assigned_plan,
            'my_plans'             => $my_plans,
            'available_plans'      => $available_plans,
            'show_my_plans'        => $show_my_plans,
            'show_available_plans' => $show_available_plans,
        ];
    }

    /**
     * Return active package plans that can immediately receive the listing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_compatible_plans( int $listing_id, ?int $requesting_user_id = null ): array {
        $options = $this->get_dashboard_plan_options( $listing_id, $requesting_user_id );

        return array_values(
            array_filter(
                $options['my_plans'],
                static fn( array $plan ): bool => ! empty( $plan['is_assignable'] )
            )
        );
    }

    /**
     * @return array{changed: bool, requires_payment: bool, redirect_url?: string, order_id?: int}
     */
    public function assign( int $listing_id, int $plan_id, ?int $requesting_user_id = null, bool $is_admin = false ): array {
        $listing    = $this->get_listing( $listing_id, $requesting_user_id );
        $compatible = $this->get_compatible_plans( $listing_id, $requesting_user_id );
        $target     = null;

        foreach ( $compatible as $candidate ) {
            if ( (int) $candidate['id'] === $plan_id ) {
                $target = $candidate;
                break;
            }
        }

        if ( ! $target ) {
            throw new Exception( esc_html__( 'The selected plan is not compatible with this listing or has no remaining quota.', 'directorist-pricing-plans' ), 400 );
        }

        $plan    = directorist_get_pricing_plan_by_id( $plan_id );
        $package = directorist_user_package_repository()->get_package_by_plan( (int) $listing->post_author, $plan_id );

        if ( ! $plan || ! $package ) {
            throw new Exception( esc_html__( 'No active package was found for the selected plan.', 'directorist-pricing-plans' ), 404 );
        }

        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ) {
            if ( $is_admin ) {
                $order_id = directorist_pricing_plans_singleton( ListingOrderService::class )->create_assignment_order( $listing_id, $package, $plan );
                $this->apply_assignment( $listing_id, $package, $plan, true );

                return [ 'changed' => true, 'requires_payment' => false, 'order_id' => $order_id ];
            }

            $paid_order = directorist_get_paid_order_without_listing( $plan_id, (int) $listing->post_author );

            if ( ! $paid_order ) {
                return [
                    'changed'          => false,
                    'requires_payment' => true,
                    'redirect_url'     => directorist_get_checkout_page_url(
                        'plan',
                        [
                            'plan_id'           => $plan_id,
                            'listing_id'        => $listing_id,
                            'plan_reassignment' => '1',
                            'is_featured'       => ! empty( $plan->is_featured ) ? '1' : '0',
                        ]
                    ),
                ];
            }

            $updated = directorist_order_repository()->get_query_builder()
                ->where( 'id', (int) $paid_order->id )
                ->where( 'status', OrderStatus::PAID )
                ->where_null( 'listing_id' )
                ->update(
                    [
                        'listing_id'          => $listing_id,
                        'is_featured_listing' => ! empty( $plan->is_featured ),
                    ]
                );

            if ( ! $updated ) {
                throw new Exception( esc_html__( 'The prepaid order could not be assigned to this listing.', 'directorist-pricing-plans' ), 409 );
            }
            directorist_user_package_repository()->link_package_order( (int) $package->id, (int) $paid_order->id );
        }

        $this->apply_assignment( $listing_id, $package, $plan, $is_admin );

        return [ 'changed' => true, 'requires_payment' => false ];
    }

    public function validate_checkout_reassignment( int $listing_id, int $plan_id, int $user_id ): void {
        $options = $this->get_dashboard_plan_options( $listing_id, $user_id );

        foreach ( $options['available_plans'] as $candidate ) {
            if ( (int) $candidate['id'] === $plan_id && ! empty( $candidate['is_assignable'] ) ) {
                return;
            }
        }

        throw new Exception( esc_html__( 'This listing cannot be reassigned to the selected plan.', 'directorist-pricing-plans' ), 400 );
    }

    public function apply_assignment( int $listing_id, stdClass $package, stdClass $plan, bool $is_admin = false ): void {
        update_post_meta( $listing_id, directorist_plan_key(), (int) $plan->id );

        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ) {
            directorist_set_listing_featured( $listing_id, ! empty( $plan->is_featured ) );
            directorist_pricing_plan_apply_plan_listing_expiration( $listing_id, $plan );
        } else {
            directorist_pricing_plan_apply_package_listing_expiration( $listing_id, $package->current_period_end ?? null );
        }

        directorist_set_listing_status( $listing_id, $is_admin ? 'publish' : $this->get_user_assignment_status( $listing_id ) );
        update_post_meta( $listing_id, '_listing_status', 'post_status' );

        do_action( 'directorist_listing_plan_reassigned', $listing_id, (int) $plan->id, (int) $package->id );
    }

    private function build_owned_plan_option( WP_Post $listing, stdClass $plan, int $current_plan_id, bool $is_featured ): array {
        $usage              = directorist_package_usage();
        $total              = $usage->get_total_uses( (int) $listing->post_author, $plan );
        $featured           = $usage->get_featured_uses( (int) $listing->post_author, $plan );
        $remaining          = (int) $total['remaining'];
        $featured_remaining = (int) $featured['remaining'];
        $is_current         = (int) $plan->id === $current_plan_id;
        $is_assignable      = ! $is_current
            && 0 !== $remaining
            && ( ! $is_featured || 0 !== $featured_remaining );
        $status_message     = '';

        if ( $is_current ) {
            $status_message = esc_html__( 'Currently assigned', 'directorist-pricing-plans' );
        } elseif ( 0 === $remaining ) {
            $status_message = esc_html__( 'No listing quota remaining', 'directorist-pricing-plans' );
        } elseif ( $is_featured && 0 === $featured_remaining ) {
            $status_message = esc_html__( 'No featured listing quota remaining', 'directorist-pricing-plans' );
        }

        return array_merge(
            [
                'id'                 => (int) $plan->id,
                'title'              => (string) $plan->title,
                'type'               => (string) ( $plan->type ?? PlanType::PACKAGE ),
                'remaining'          => $remaining,
                'featured_remaining' => $featured_remaining,
                'is_current'         => $is_current,
                'is_assignable'      => $is_assignable,
                'status_message'     => $status_message,
                'checkout_url'       => '',
            ],
            $this->build_plan_display_data( $plan )
        );
    }

    /**
     * Return fields shared by plan option cards in dashboard modals.
     *
     * @return array<string, mixed>
     */
    private function build_plan_display_data( stdClass $plan ): array {
        return [
            'price'                                  => PlanFeeType::FREE === ( $plan->fee_type ?? '' ) ? 0.0 : (float) $plan->price,
            'currency'                               => atbdp_get_payment_currency(),
            'interval_type'                          => (string) ( $plan->interval_type ?? 'lifetime' ),
            'interval_count'                         => (int) ( $plan->interval_count ?? 0 ),
            'regular_listing'                        => (int) ( $plan->allowed_listings ?? 0 ),
            'feature_listing'                        => (int) ( $plan->allowed_featured_listings ?? 0 ),
            'is_featured'                            => ! empty( $plan->is_featured ),
            'is_allowed_unlimited_listings'          => ! empty( $plan->is_allowed_unlimited_listings ),
            'is_allowed_unlimited_featured_listings' => ! empty( $plan->is_allowed_unlimited_featured_listings ),
        ];
    }

    private function get_user_assignment_status( int $listing_id ): string {
        $directory_type_id = directorist_get_listings_directory_type( $listing_id );
        $status            = $directory_type_id
            ? directorist_get_directory_meta( $directory_type_id, 'edit_listing_status' )
            : '';

        return in_array( $status, [ 'publish', 'pending' ], true ) ? $status : 'pending';
    }

    private function validate_plan_quota( WP_Post $listing, stdClass $plan ): void {
        if ( ! directorist_plan_has_listing_quota( $plan ) ) {
            throw new Exception( esc_html( directorist_plan_no_listing_quota_message() ), 400 );
        }

        if ( PlanType::PACKAGE !== ( $plan->type ?? PlanType::PACKAGE ) ) {
            return;
        }

        $usage = directorist_package_usage();
        $total = $usage->get_total_uses( (int) $listing->post_author, $plan );

        if ( 0 === (int) $total['remaining'] ) {
            throw new Exception( esc_html__( 'The selected plan has no remaining listing quota.', 'directorist-pricing-plans' ), 400 );
        }

        if ( '1' !== (string) get_post_meta( $listing->ID, '_featured', true ) ) {
            return;
        }

        $featured = $usage->get_featured_uses( (int) $listing->post_author, $plan );

        if ( 0 === (int) $featured['remaining'] ) {
            throw new Exception( esc_html__( 'The selected plan has no remaining featured listing quota.', 'directorist-pricing-plans' ), 400 );
        }
    }

    private function get_listing( int $listing_id, ?int $requesting_user_id = null ): WP_Post {
        $listing = get_post( $listing_id );

        if ( ! $listing || ATBDP_POST_TYPE !== $listing->post_type ) {
            throw new Exception( esc_html__( 'The listing was not found.', 'directorist-pricing-plans' ), 404 );
        }

        if ( null !== $requesting_user_id && (int) $listing->post_author !== $requesting_user_id ) {
            throw new Exception( esc_html__( 'You are not allowed to manage this listing plan.', 'directorist-pricing-plans' ), 403 );
        }

        return $listing;
    }
}
