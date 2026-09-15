<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use stdClass;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Jobs\OldDataMigrationQueue;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;

class ListingFormManager implements Provider {
    private const DEFAULT_PLAN_COLUMNS = 3;

    private const SUPPORTED_PLAN_COLUMNS = [ 1, 2, 3, 4, 6 ];

    private $shortcode_plan_columns;

    private $elementor_plan_columns;

    public function boot() {
        add_filter( 'atbdp_add_listing_page_template', [ $this, 'plans_page' ], 10, 3 );
        add_filter( 'pre_do_shortcode_tag', [ $this, 'capture_add_listing_shortcode_columns' ], 10, 4 );
        add_filter( 'do_shortcode_tag', [ $this, 'reset_add_listing_plan_columns' ], PHP_INT_MAX, 4 );
        add_action( 'elementor/element/directorist_add_listing/sec_general/before_section_end', [ $this, 'register_elementor_plan_columns_control' ] );
        add_action( 'elementor/frontend/widget/before_render', [ $this, 'capture_elementor_plan_columns' ] );
    }

    /**
     * Capture attributes before Directorist normalizes the Add Listing shortcode.
     */
    public function capture_add_listing_shortcode_columns( $output, $tag, $atts ) {
        if ( 'directorist_add_listing' !== $tag ) {
            return $output;
        }

        $this->shortcode_plan_columns = isset( $atts['columns'] )
            ? $this->normalize_plan_columns( $atts['columns'] )
            : null;

        return $output;
    }

    /**
     * Prevent one Add Listing instance from leaking its column choice into another.
     */
    public function reset_add_listing_plan_columns( $output, $tag ) {
        if ( 'directorist_add_listing' !== $tag ) {
            return $output;
        }

        $this->shortcode_plan_columns = null;
        $this->elementor_plan_columns = null;

        return $output;
    }

    /**
     * Extend the native Directorist Add Listing Elementor widget.
     */
    public function register_elementor_plan_columns_control( $element ) {
        if ( ! class_exists( '\\Elementor\\Controls_Manager' ) ) {
            return;
        }

        if ( method_exists( $element, 'update_control' ) ) {
            $element->update_control(
                'sec_heading',
                [
                    'label' => __( 'Configure the Add Listing form and its pricing plan layout.', 'directorist-pricing-plans' ),
                ]
            );
        }

        $element->add_control(
            'pricing_plan_columns',
            [
                'label'       => __( 'Pricing Plan Columns', 'directorist-pricing-plans' ),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'default'     => (string) self::DEFAULT_PLAN_COLUMNS,
                'options'     => [
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                    '6' => '6',
                ],
                'description' => __( 'Choose how many pricing plans appear per row before the listing form.', 'directorist-pricing-plans' ),
            ]
        );
    }

    /**
     * Capture the selected Elementor value before the widget renders its shortcode.
     */
    public function capture_elementor_plan_columns( $widget ) {
        if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'directorist_add_listing' !== $widget->get_name() ) {
            return;
        }

        if ( ! method_exists( $widget, 'get_settings_for_display' ) ) {
            return;
        }

        $settings = $widget->get_settings_for_display();

        $this->elementor_plan_columns = isset( $settings['pricing_plan_columns'] )
            ? $this->normalize_plan_columns( $settings['pricing_plan_columns'] )
            : null;
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
            $listing_id        = (int) $data['listing_id'];
            $directory_type_id = directorist_get_listings_directory_type( $listing_id );

            if ( ! $directory_type_id ) {
                return $this->notice( __( 'Invalid directory selected.', 'directorist-pricing-plans' ) );
            }

            if ( ! directorist_get_listing_package( $listing_id ) && 'publish' === get_post_status( $listing_id ) ) {
                directorist_set_listing_status( $listing_id, 'pending' );
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

        $package_count  = count( $active_packages );
        $allow_multiple = directorist_allow_multiple_plans_per_directory_type();

        // Multiple active packages, or the opt-in multiple-plan flow, require explicit plan selection.
        if ( $package_count > 1 || ( $allow_multiple && $package_count > 0 ) ) {
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

                if ( $allow_multiple ) {
                    return $this->next_template( $template, $directory->slug, $data );
                }

                // plan_id given but does not match any active package — block the purchase attempt.
                return $this->notice( __( 'You already have multiple active plans. You cannot purchase or activate a new plan at this time. Please select one of your existing plans to submit a listing.', 'directorist-pricing-plans' ) );
            }

            // No plan selected yet — show the plan selection page (existing plans only).
            do_action( 'directorist_before_pricing_plan_page', $data );

            return $this->render_plans(
                $data,
                $allow_multiple ? [] : array_map( 'absint', wp_list_pluck( $active_packages, 'plan_id' ) )
            );
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

            return $this->render_plans( $data );
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

    private function render_plans( array $data, array $include_plan_ids = [] ): string {
        $columns = $this->shortcode_plan_columns ?? $this->elementor_plan_columns ?? self::DEFAULT_PLAN_COLUMNS;

        /**
         * Filters the pricing plan columns shown in the Add Listing flow.
         *
         * @param int   $columns Number of plans per row.
         * @param array $data    Add Listing form data.
         */
        $columns = apply_filters( 'directorist_pricing_plans_add_listing_columns', $columns, $data );

        return (string) directorist_render_plans(
            null,
            [
                'columns'          => $this->normalize_plan_columns( $columns ),
                'include_plan_ids' => implode( ',', array_map( 'absint', $include_plan_ids ) ),
            ]
        );
    }

    private function normalize_plan_columns( $columns ): int {
        $columns = absint( $columns );

        return in_array( $columns, self::SUPPORTED_PLAN_COLUMNS, true )
            ? $columns
            : self::DEFAULT_PLAN_COLUMNS;
    }

    private function redirect_to_checkout( int $plan_id, string $directory_type_slug ): string {
        $checkout_url = directorist_get_checkout_page_url(
            'plan',
            [
                'plan_id'        => $plan_id,
                'directory_type' => $directory_type_slug,
            ]
        );

        return $this->notice_with_actions(
            __( 'Redirecting to checkout...', 'directorist-pricing-plans' ),
            $checkout_url,
            __( 'Continue to checkout', 'directorist-pricing-plans' ),
            '',
            '',
            'info'
        ) . sprintf(
            '<script>window.location.href = %s;</script>',
            wp_json_encode( $checkout_url )
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

    public function notice_with_actions( string $message, string $primary_url, string $primary_label, string $secondary_url = '', string $secondary_label = '', string $type = 'warning' ): string {
        ob_start();
        ?>
        <div class="directorist-col-md-12">
            <section class="directorist-alert directorist-alert-<?php echo esc_attr( $type ); ?> directorist-single-listing-notice" style="display: flex; align-items: center; justify-content: center; text-align: center;">
                <div class="directorist-alert__content" style="display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; text-align: center;">
                    <p style="margin: 0;"><?php echo esc_html( $message ); ?></p>
                    <p class="directorist-single-listing-notice__actions" style="display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 5px; margin: 10px 0 0;">
                        <a class="directorist-btn directorist-btn-primary" href="<?php echo esc_url( $primary_url ); ?>">
                            <?php echo esc_html( $primary_label ); ?>
                        </a>
                        <?php if ( $secondary_url && $secondary_label ) : ?>
                            <a class="directorist-btn directorist-btn-light" href="<?php echo esc_url( $secondary_url ); ?>">
                                <?php echo esc_html( $secondary_label ); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            </section>
        </div>
        <?php

        return ob_get_clean();
    }
}
