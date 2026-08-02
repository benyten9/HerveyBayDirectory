<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use stdClass;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Jobs\OldDataMigrationQueue;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;

class ListingFormManager implements Provider {
    public function boot() {
        add_filter( 'atbdp_add_listing_page_template', [ $this, 'plans_page' ], 10, 3 );
    }
    
    public function plans_page( $template, $data ) {
        wp_enqueue_style( 'directorist-pricing-plans-frontend' );
        wp_enqueue_script( 'directorist-pricing-plans-plans' );
        wp_localize_script(
            'directorist-pricing-plans-plans',
            'directoristPricingPlanValidator',
            [
                'remainingText'  => __( 'Remaining Character:', 'directorist-pricing-plans' ),
                'maxReachedText' => __( 'Max character limit reached!', 'directorist-pricing-plans' ),
            ]
        );

        $migration_queue = directorist_pricing_plans_singleton( OldDataMigrationQueue::class );

        if ( $migration_queue->is_active() ) {
            return $this->maintenance_notice();
        }

        if ( $data['is_edit_mode'] ) {
            $directory_type_id = directorist_get_listings_directory_type( $data['listing_id'] );

            if ( ! $directory_type_id ) {
                return $this->notice( __( 'Invalid directory selected.', 'directorist-pricing-plans' ) );
            }

            $current_package = directorist_get_listing_package( (int) $data['listing_id'] );

            if ( ! $current_package ) {
                return $this->notice( __( 'You must have an active plan to access this page.', 'directorist-pricing-plans' ) );
            }

            return $template;
        }

        if ( count( $data['listing_types'] ) > 1 && empty( $_GET['directory_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return $template;
        }

        $directory = null;

        if ( isset( $_GET['directory_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $directory = directorist_get_directory_by_slug( sanitize_text_field( wp_unslash( $_GET['directory_type'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( ! $directory && count( $data['listing_types'] ) === 1 ) {
            $directory = $data['listing_types'][0]['term'];
        }

        if ( ! $directory ) {
            return $this->notice( __( 'Invalid directory selected.', 'directorist-pricing-plans' ) );
        }

        $active_packages = directorist_user_package_repository()->get_active_packages_for_directory(
            get_current_user_id(),
            $directory->term_id
        );

        $package_count = count( $active_packages );

        // Multiple active packages (users migrated from old version): require explicit plan selection.
        if ( $package_count > 1 ) {
            if ( isset( $_GET['plan_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $selected_plan_id = absint( wp_unslash( $_GET['plan_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

                foreach ( $active_packages as $pkg ) {
                    if ( (int) $pkg->plan_id === $selected_plan_id ) {
                        $selected_plan = directorist_get_pricing_plan_by_id( $selected_plan_id );

                        if ( ! $selected_plan ) {
                            return $this->next_template( $template, $directory->slug, $data );
                        }

                        if ( ! directorist_plan_has_listing_quota( $selected_plan ) ) {
                            return $this->notice( directorist_plan_no_listing_quota_message() );
                        }

                        if ( ! apply_filters( 'directorist_has_plan_remaining_quota', true, $selected_plan, false ) ) {
                            return $this->notice( __( 'You have reached the maximum number of allowed listings. Please upgrade your plan to continue.', 'directorist-pricing-plans' ) );
                        }

                        $is_direct_pay_per_listing = PlanType::PAY_PER_LISTING === $pkg->plan_type && directorist_direct_purchase();

                        if ( $is_direct_pay_per_listing && ! directorist_has_paid_order_without_listing( $selected_plan_id ) ) {
                            return $this->redirect_to_checkout( $selected_plan_id, $directory->slug );
                        }
                        return $template;
                    }
                }

                // plan_id given but does not match any active package — block the purchase attempt.
                return $this->notice( __( 'You already have multiple active plans. You cannot purchase or activate a new plan at this time. Please select one of your existing plans to submit a listing.', 'directorist-pricing-plans' ) );
            }

            // No plan selected yet — show the plan selection page (existing plans only).
            do_action( 'directorist_before_pricing_plan_page', $data );

            return directorist_render_plans();
        }

        // Single active package: proceed with the existing quota-check flow.
        $current_package = $package_count === 1 ? $active_packages[0] : null;

        if ( $current_package ) {
            if ( isset( $_GET['plan_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $selected_plan_id = absint( wp_unslash( $_GET['plan_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

                if ( $selected_plan_id && (int) $current_package->plan_id !== $selected_plan_id ) {
                    return $this->next_template( $template, $directory->slug, $data );
                }
            }

            $selected_plan = directorist_get_pricing_plan_by_id( $current_package->plan_id );

            if ( ! $selected_plan ) {
                return $this->next_template( $template, $directory->slug, $data );
            }

            if ( ! directorist_plan_has_listing_quota( $selected_plan ) ) {
                return $this->notice( directorist_plan_no_listing_quota_message() );
            }

            if ( ! apply_filters( 'directorist_has_plan_remaining_quota', true, $selected_plan, false ) ) {
                return $this->notice( __( 'You have reached the maximum number of allowed listings. Please upgrade your plan to continue.', 'directorist-pricing-plans' ) );
            }

            $is_direct_pay_per_listing = PlanType::PAY_PER_LISTING === $current_package->plan_type && directorist_direct_purchase();

            if ( $is_direct_pay_per_listing && ! directorist_has_paid_order_without_listing( (int) $current_package->plan_id ) ) {
                return $this->redirect_to_checkout( (int) $current_package->plan_id, $directory->slug );
            }

            return $template;
        }

        // No active package — existing checkout / plan-selection flow.
        return $this->next_template( $template, $directory->slug, $data );
    }

    protected function next_template( string $template, string $directory_type_slug, array $data ) {
        if ( ! isset( $_GET['plan_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            do_action( 'directorist_before_pricing_plan_page', $data );

            return directorist_render_plans();
        }

        $plan_id       = absint( wp_unslash( $_GET['plan_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selected_plan = directorist_get_pricing_plan_by_id( $plan_id );

        if ( ! $selected_plan ) {
            return $this->notice( __( 'Invalid plan selected.', 'directorist-pricing-plans' ) );
        }

        if ( ! directorist_plan_has_listing_quota( $selected_plan ) ) {
            return $this->notice( directorist_plan_no_listing_quota_message() );
        }

        if ( directorist_direct_purchase() && ( is_user_logged_in() || ! directorist_is_guest_submission_enabled() ) ) {
            $has_reusable_paid_order = PlanType::PAY_PER_LISTING === ( $selected_plan->type ?? PlanType::PACKAGE )
                && directorist_has_paid_order_without_listing( $plan_id );

            if ( ! $has_reusable_paid_order ) {
                return $this->redirect_to_checkout( $plan_id, $directory_type_slug );
            }
        }

        return $template;
    }

    private function redirect_to_checkout( int $plan_id, string $directory_type_slug ): string {
        $checkout_url = directorist_get_checkout_page_url(
            'plan',
            [
                'plan_id'        => $plan_id,
                'directory_type' => $directory_type_slug,
            ]
        );

        if ( ! headers_sent() ) {
            wp_safe_redirect( $checkout_url );
            exit;
        }

        return sprintf(
            '<script>window.location.href = %1$s;</script><p><a href="%2$s">%3$s</a></p>',
            wp_json_encode( $checkout_url ),
            esc_url( $checkout_url ),
            esc_html__( 'Continue to checkout', 'directorist-pricing-plans' )
        );
    }

    private function maintenance_notice(): string {
        ob_start();
        ?>
        <div class="directorist-col-md-12">
            <div style="text-align:center;padding:48px 24px;background:#fff;border-radius:10px;border:1px solid #e2e8f0;box-shadow:0 4px 16px rgba(0,0,0,0.07);max-width:560px;margin:0 auto;">
                <div style="width:56px;height:56px;background:#fff3cd;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;border:2px solid #ffc107;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#856404" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h3 style="font-size:20px;font-weight:700;color:#1d2327;margin:0 0 12px;"><?php esc_html_e( 'Site Maintenance in Progress', 'directorist-pricing-plans' ); ?></h3>
                <p style="font-size:15px;color:#50575e;margin:0 0 10px;line-height:1.6;"><?php esc_html_e( 'We are currently updating our database to improve your experience. Listing submission is temporarily unavailable.', 'directorist-pricing-plans' ); ?></p>
                <p style="font-size:14px;color:#888;margin:0;line-height:1.6;"><?php esc_html_e( 'We are sorry for the inconvenience. Please check back in a few minutes — this process will complete shortly.', 'directorist-pricing-plans' ); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function notice( $message, $type = 'warning' ): string {
        ob_start();
        ?>
        <div class="directorist-col-md-12">
            <section class="directorist-alert directorist-alert-<?php echo esc_attr( $type ); ?> directorist-single-listing-notice">
                <div class="directorist-alert__content">
                    <?php echo esc_html( $message ); ?>
                </div>
            </section>
        </div>
        <?php

        return ob_get_clean();
    }
}
