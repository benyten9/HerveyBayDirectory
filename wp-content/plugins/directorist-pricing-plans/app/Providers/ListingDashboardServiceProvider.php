<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use stdClass;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\WpMVC\View\View;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Services\ExpiredListingRenewalService;
use DirectoristPricingPlan\App\Services\ListingPlanAssignmentService;

class ListingDashboardServiceProvider implements Provider {
    public function boot() {
        add_filter( 'directorist_show_user_order_history_tab', '__return_true', 10, 1 );
        add_filter( 'directorist_can_renew_listing', '__return_false', 10, 1 );
        add_filter( 'directorist_can_promote_listing', '__return_false', 10, 1 );
        add_filter( 'directorist_can_unfeature_listing', '__return_false', 10, 1 );
        add_filter( 'directorist_dashboard_listing_action_items_end', [ $this, 'add_listing_action_items' ], 10, 2 );
        add_action( 'atbdp_before_renewal', [ $this, 'handle_listing_renewal' ] );
        add_action( 'directorist_dashboard_listing_th_2', [ $this, 'add_listing_plan_header' ] );
        add_action( 'directorist_dashboard_listing_td_2', [ $this, 'add_listing_plan_column' ] );
        add_action( 'directorist_dashboard_tabs', [ $this, 'directorist_dashboard_tabs' ] );
    }

    public function add_listing_plan_header(): void {
        printf(
            '<th class="directorist-table-listing-plan">%s</th>',
            esc_html__( 'Plan', 'directorist-pricing-plans' )
        );
    }

    public function add_listing_plan_column(): void {
        $plan_title = $this->get_listing_plan_title( get_the_ID() );

        if ( null === $plan_title ) {
            printf(
                '<td><span class="directorist-listing-plan directorist-listing-plan--empty">%s</span></td>',
                esc_html__( 'No plan assigned', 'directorist-pricing-plans' )
            );
            return;
        }

        printf(
            '<td><span class="directorist-listing-plan">%s</span></td>',
            esc_html( $plan_title )
        );
    }

    public function add_listing_action_items( array $items, int $post_id ) {
        $new_items                = [];
        $package                  = directorist_get_listing_package( $post_id );
        $assigned_plan_id         = (int) get_post_meta( $post_id, directorist_plan_key(), true );
        $plan_id                  = $package ? (int) $package->plan_id : $assigned_plan_id;
        $plan                     = $plan_id ? directorist_get_pricing_plan_by_id( $plan_id ) : null;
        $listing_status           = get_post_status( $post_id );
        $has_active_assigned_plan = $assigned_plan_id
            && $package
            && (int) $package->plan_id === $assigned_plan_id;

        $renewal = 'expired' === $listing_status && $has_active_assigned_plan
            ? directorist_pricing_plans_singleton( ExpiredListingRenewalService::class )->get_listing_summary(
                $post_id,
                get_current_user_id()
            )
            : null;

        if ( $renewal ) {
            $is_pay_per_listing = PlanType::PAY_PER_LISTING === $renewal['plan_type'];
            $renewal_url        = $is_pay_per_listing
                ? $this->get_pay_per_listing_renewal_url( $post_id, $renewal )
                : '#';

            if ( ! $is_pay_per_listing ) {
                $this->enqueue_dashboard_assets();
            }

            $new_items['renew_listing'] = [
                'class'     => $is_pay_per_listing ? 'directorist-renew-listing' : 'directorist-renew-package-listing',
                'data_attr' => $is_pay_per_listing ? '' : "data-post-id=$post_id",
                'link'      => $renewal_url,
                'icon'      => directorist_icon( 'las la-sync', false ),
                'label'     => __( 'Renew Listing', 'directorist-pricing-plans' ),
            ];
        }

        if ( 'expired' === $listing_status
            && ( directorist_allow_multiple_plans_per_directory_type() || ! $has_active_assigned_plan )
        ) {
            $this->enqueue_dashboard_assets();
            $new_items['change_plan'] = [
                'class'     => 'directorist-change-listing-plan',
                'data_attr' => "data-post-id=$post_id data-has-plan=0",
                'link'      => '#',
                'icon'      => directorist_icon( 'las la-exchange-alt', false ),
                'label'     => __( 'Assign Plan', 'directorist-pricing-plans' ),
            ];
        }

        if ( 'publish' === $listing_status && $package && $plan && (int) $package->plan_id === $assigned_plan_id ) {
            // Mark as Featured/Regular.
            $is_featured  = '1' === strval( get_post_meta( $post_id, '_featured', true ) );
            $mark_as_task = $is_featured ? 'mark-as-regular' : 'mark-as-featured';

            if ( $is_featured || $this->can_mark_as_featured( $plan ) ) {
                $new_items['mark_as'] = [
                    'class'     => 'directorist-mark-listing-as',
                    'data_attr' => "data-task='$mark_as_task' data-post-id=$post_id",
                    'link'      => '#',
                    'icon'      => directorist_icon( 'lar la-star', false ),
                    'label'     => $is_featured ? __( 'Mark as Regular', 'directorist-pricing-plans' ) : __( 'Mark as Featured', 'directorist-pricing-plans' ),
                ];
            }
        }

        if ( 'expired' !== $listing_status ) {
            try {
                $plan_options     = directorist_pricing_plans_singleton( ListingPlanAssignmentService::class )->get_dashboard_plan_options(
                    $post_id,
                    get_current_user_id()
                );
                $selectable_plans = array_filter(
                    array_merge( $plan_options['my_plans'], $plan_options['available_plans'] ),
                    static fn( array $option ): bool => ! empty( $option['is_assignable'] )
                );

                if ( ! $assigned_plan_id || ! empty( $selectable_plans ) ) {
                    $this->enqueue_dashboard_assets();
                    $new_items['change_plan'] = [
                        'class'     => 'directorist-change-listing-plan',
                        'data_attr' => "data-post-id=$post_id data-has-plan=" . ( $assigned_plan_id ? '1' : '0' ),
                        'link'      => '#',
                        'icon'      => directorist_icon( 'las la-exchange-alt', false ),
                        'label'     => $assigned_plan_id
                            ? __( 'Switch Plan', 'directorist-pricing-plans' )
                            : __( 'Assign Plan', 'directorist-pricing-plans' ),
                    ];
                }
            } catch ( \Exception $exception ) {
                // Keep the listing menu available when plan discovery fails.
            }
        }

        if ( 'expired' !== $listing_status && $plan && PlanType::PACKAGE === ( $plan->type ?? PlanType::PACKAGE ) ) {
            $this->enqueue_dashboard_assets();
            $new_items['remove_plan'] = [
                'class'     => 'directorist-remove-listing-plan',
                'data_attr' => "data-post-id=$post_id",
                'link'      => '#',
                'icon'      => directorist_icon( 'las la-unlink', false ),
                'label'     => __( 'Remove from plan', 'directorist-pricing-plans' ),
            ];
        }

        return array_merge( $new_items, $items );
    }

    /**
     * Route Directorist's legacy email renewal flow through Pricing Plans.
     */
    public function handle_listing_renewal( int $listing_id ): void {
        $renewal_service = directorist_pricing_plans_singleton( ExpiredListingRenewalService::class );
        $renewal         = $renewal_service->get_listing_summary( $listing_id, get_current_user_id() );

        if ( ! $renewal ) {
            return;
        }

        if ( PlanType::PAY_PER_LISTING === $renewal['plan_type'] ) {
            update_post_meta( $listing_id, '_refresh_renewal_token', 1 );
            wp_safe_redirect( $this->get_pay_per_listing_renewal_url( $listing_id, $renewal ) );
            exit;
        }

        $renewal_service->renew_listing( $listing_id, get_current_user_id() );

        delete_post_meta( $listing_id, '_renewal_token' );
        delete_post_meta( $listing_id, '_refresh_renewal_token' );

        wp_safe_redirect(
            add_query_arg( 'renew', 'success', \ATBDP_Permalink::get_dashboard_page_link() )
        );
        exit;
    }

    public function directorist_dashboard_tabs( $tabs ): array {
        $new_tabs = [
            'packages' => [
                'title'   => __( 'Packages', 'directorist-pricing-plans' ),
                'content' => $this->packages_tab_content(),
                'icon'    => 'las la-box',
            ],
        ];

        if ( empty( $tabs ) ) {
            return $new_tabs;
        }

        $length     = 1;
        $first_part = array_slice( $tabs, 0, $length, true );
        $last_part  = array_slice( $tabs, $length, null, true );

        return $first_part + $new_tabs + $last_part;
    }

    protected function packages_tab_content() {
        $this->enqueue_dashboard_assets();
        return View::get( 'listing-owner-subscriptions' );
    }

    private function enqueue_dashboard_assets(): void {
        wp_enqueue_style( 'directorist-pricing-plans-frontend' );
        wp_enqueue_script( 'directorist-notification' );
        wp_enqueue_script( 'directorist-pricing-plans-listing-owner-dashboard' );
    }

    private function can_mark_as_featured( stdClass $plan ): bool {
        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ) {
            return ! empty( $plan->is_featured );
        }

        return ! empty( $plan->is_allowed_unlimited_featured_listings ) || (int) $plan->allowed_featured_listings > 0;
    }

    /**
     * Build the checkout URL for an expired pay-per-listing assignment.
     *
     * @param array<string, mixed> $renewal Renewal summary.
     */
    private function get_pay_per_listing_renewal_url( int $listing_id, array $renewal ): string {
        return directorist_get_checkout_page_url(
            'plan',
            [
                'plan_id'         => (int) $renewal['plan_id'],
                'listing_id'      => $listing_id,
                'listing_renewal' => '1',
                'is_featured'     => ! empty( $renewal['will_be_featured'] ) ? '1' : '0',
            ]
        );
    }

    private function get_listing_plan_title( int $listing_id ): ?string {
        if ( ! $listing_id ) {
            return null;
        }

        $package = directorist_get_listing_package( $listing_id );
        $plan_id = $package ? (int) $package->plan_id : (int) get_post_meta( $listing_id, directorist_plan_key(), true );

        if ( ! $plan_id ) {
            return null;
        }

        $plan = directorist_get_pricing_plan_by_id( $plan_id );

        return $plan ? $plan->title : null;
    }
}
