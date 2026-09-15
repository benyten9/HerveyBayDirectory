<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Services\ExpiredListingRenewalService;
use DirectoristPricingPlan\App\Services\ListingOrderService;
use DirectoristPricingPlan\App\Services\ListingPlanAssignmentService;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use WP_Post;

class ListingPlanMetaboxProvider implements Provider {
    public function boot() {
        add_action( 'add_meta_boxes_' . ATBDP_POST_TYPE, [ $this, 'add_metabox' ] );
        add_action(
            'admin_post_directorist_pricing_plan_renew_listing',
            [ $this, 'handle_renew_listing' ]
        );
        add_action(
            'admin_post_directorist_pricing_plan_assign_listing_order',
            [ $this, 'handle_assign_listing_order' ]
        );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        add_action(
            'admin_post_directorist_pricing_plan_change_listing_plan',
            [ $this, 'handle_change_listing_plan' ]
        );
        add_action(
            'admin_post_directorist_pricing_plan_assign_listing_plan',
            [ $this, 'handle_assign_listing_plan' ]
        );
        add_action(
            'admin_post_directorist_pricing_plan_remove_listing_plan',
            [ $this, 'handle_remove_listing_plan' ]
        );
        add_action( 'admin_footer-post.php', [ $this, 'render_plan_assignment_script' ] );
    }

    public function add_metabox(): void {
        add_meta_box(
            'directorist-pricing-plan-metabox',
            __( 'Plan', 'directorist-pricing-plans' ),
            [ $this, 'render_metabox' ],
            ATBDP_POST_TYPE,
            'side',
            'high'
        );
    }

    public function render_metabox( WP_Post $post ): void {
        $this->render_metabox_styles();

        $package            = directorist_get_listing_package( (int) $post->ID );
        $assigned_plan_id   = (int) get_post_meta( (int) $post->ID, directorist_plan_key(), true );
        $plan_id            = $assigned_plan_id ?: (int) ( $package->plan_id ?? 0 );
        $plan               = $plan_id ? directorist_get_pricing_plan_by_id( $plan_id ) : null;
        $has_active_package = $package && (int) $package->plan_id === $plan_id;
        $has_plan_issue     = ! $has_active_package || 'expired' === $post->post_status;

        if ( ! $plan ) {
            printf(
                '<div class="directorist-plan-empty-state"><strong>%s</strong></div>',
                esc_html__( 'No plan assigned yet', 'directorist-pricing-plans' )
            );
            $this->render_plan_assignment_form( $post );
            return;
        }

        $plan_type_label = PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE )
            ? __( 'Pay Per Listing', 'directorist-pricing-plans' )
            : __( 'Package', 'directorist-pricing-plans' );

        ?>
        <div class="directorist-plan-summary">
            <strong class="<?php echo esc_attr( 'directorist-plan-summary__name' . ( $has_plan_issue ? ' directorist-plan-summary__name--attention' : '' ) ); ?>">
                <?php echo esc_html( $plan->title ); ?>
            </strong>
            <span class="directorist-plan-summary__badge"><?php echo esc_html( $plan_type_label ); ?></span>
        </div>
        <?php

        $renewal = 'expired' === $post->post_status && $has_active_package
            ? directorist_pricing_plans_singleton( ExpiredListingRenewalService::class )->get_listing_summary( (int) $post->ID )
            : null;

        if ( $renewal ) {
            $this->render_action_button(
                'directorist_pricing_plan_renew_listing',
                (int) $post->ID,
                __( 'Renew Listing', 'directorist-pricing-plans' )
            );
        }

        $this->render_plan_assignment_form( $post );

        if ( PlanType::PACKAGE === ( $plan->type ?? PlanType::PACKAGE ) ) {
            $this->render_remove_plan_button( (int) $post->ID );
            return;
        }

        if ( ! $package ) {
            return;
        }

        $listing_order_service = directorist_pricing_plans_singleton( ListingOrderService::class );
        $last_order            = $listing_order_service->get_latest_order( (int) $post->ID, (int) $plan->id );

        if ( $last_order ) {
            printf(
                '<div class="directorist-plan-order"><span>%s</span><a href="%s">#%d</a></div>',
                esc_html__( 'Last Assigned Order:', 'directorist-pricing-plans' ),
                esc_url( $this->get_order_url( (int) $last_order->id ) ),
                (int) $last_order->id
            );
        }

        if ( ! $last_order ) {
            $this->render_action_button(
                'directorist_pricing_plan_assign_listing_order',
                (int) $post->ID,
                __( 'Assign Order', 'directorist-pricing-plans' )
            );
        }
    }

    public function handle_renew_listing(): void {
        $this->handle_listing_action( 'directorist_pricing_plan_renew_listing', 'renew_listing' );
    }

    public function handle_assign_listing_order(): void {
        $this->handle_listing_action( 'directorist_pricing_plan_assign_listing_order', 'assign_order' );
    }

    public function handle_change_listing_plan(): void {
        $listing_id = isset( $_POST['listing_id'] ) ? absint( wp_unslash( $_POST['listing_id'] ) ) : 0;
        $plan_id    = isset( $_POST['plan_id'] ) ? absint( wp_unslash( $_POST['plan_id'] ) ) : 0;

        if ( ! $listing_id || ! $plan_id || ! current_user_can( 'edit_post', $listing_id ) ) {
            wp_die(
                esc_html__( 'You are not allowed to perform this action.', 'directorist-pricing-plans' ),
                '',
                [ 'response' => 403 ]
            );
        }

        check_admin_referer( 'directorist_pricing_plan_change_listing_plan_' . $listing_id );

        try {
            directorist_pricing_plans_singleton( ListingPlanAssignmentService::class )->assign_admin_listing_plan(
                $listing_id,
                $plan_id
            );
            $query_args = [ 'directorist_plan_action' => 'success' ];
        } catch ( \Exception $exception ) {
            $query_args = [
                'directorist_plan_action' => 'error',
                'directorist_plan_error'  => $exception->getMessage(),
            ];
        }

        wp_safe_redirect( add_query_arg( $query_args, admin_url( 'post.php?post=' . $listing_id . '&action=edit' ) ) );
        exit;
    }

    public function handle_assign_listing_plan(): void {
        $listing_id = isset( $_POST['listing_id'] ) ? absint( wp_unslash( $_POST['listing_id'] ) ) : 0;
        $plan_id    = isset( $_POST['plan_id'] ) ? absint( wp_unslash( $_POST['plan_id'] ) ) : 0;

        if ( ! $listing_id || ! $plan_id || ! current_user_can( 'edit_post', $listing_id ) ) {
            wp_die(
                esc_html__( 'You are not allowed to perform this action.', 'directorist-pricing-plans' ),
                '',
                [ 'response' => 403 ]
            );
        }

        check_admin_referer( 'directorist_pricing_plan_assign_listing_plan_' . $listing_id );

        try {
            directorist_pricing_plans_singleton( ListingPlanAssignmentService::class )->assign_unassigned_listing(
                $listing_id,
                $plan_id
            );
            $query_args = [ 'directorist_plan_action' => 'success' ];
        } catch ( \Exception $exception ) {
            $query_args = [
                'directorist_plan_action' => 'error',
                'directorist_plan_error'  => $exception->getMessage(),
            ];
        }

        wp_safe_redirect( add_query_arg( $query_args, admin_url( 'post.php?post=' . $listing_id . '&action=edit' ) ) );
        exit;
    }

    public function handle_remove_listing_plan(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce is verified after validating the listing ID.
        $listing_id = isset( $_GET['listing_id'] )
            ? absint( wp_unslash( $_GET['listing_id'] ) )
            : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( ! $listing_id || ! current_user_can( 'edit_post', $listing_id ) ) {
            wp_die(
                esc_html__( 'You are not allowed to perform this action.', 'directorist-pricing-plans' ),
                '',
                [ 'response' => 403 ]
            );
        }

        check_admin_referer( 'directorist_pricing_plan_remove_listing_plan_' . $listing_id );

        try {
            directorist_pricing_plans_singleton( ListingPlanAssignmentService::class )->remove_package_plan( $listing_id );
            $query_args = [ 'directorist_plan_action' => 'removed' ];
        } catch ( \Exception $exception ) {
            $query_args = [
                'directorist_plan_action' => 'error',
                'directorist_plan_error'  => $exception->getMessage(),
            ];
        }

        wp_safe_redirect( add_query_arg( $query_args, admin_url( 'post.php?post=' . $listing_id . '&action=edit' ) ) );
        exit;
    }

    public function admin_notices(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Notice data is read from the post-action redirect URL.
        $action = isset( $_GET['directorist_plan_action'] )
            ? sanitize_key( wp_unslash( $_GET['directorist_plan_action'] ) )
            : '';

        if ( empty( $action ) ) {
            return;
        }

        if ( 'success' === $action ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'Plan action completed successfully.', 'directorist-pricing-plans' )
            );
            return;
        }

        if ( 'removed' === $action ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'Listing removed from plan and expired successfully.', 'directorist-pricing-plans' )
            );
            return;
        }

        $message = isset( $_GET['directorist_plan_error'] )
            ? sanitize_text_field( wp_unslash( $_GET['directorist_plan_error'] ) )
            : __( 'Plan action failed.', 'directorist-pricing-plans' );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            esc_html( $message )
        );
    }

    private function render_action_button( string $action, int $listing_id, string $label ): void {
        $url = wp_nonce_url(
            add_query_arg(
                [
                    'action'     => $action,
                    'listing_id' => $listing_id,
                ],
                admin_url( 'admin-post.php' )
            ),
            $action . '_' . $listing_id
        );

        printf(
            '<div class="directorist-plan-action-row"><a class="button button-secondary" href="%s">%s</a></div>',
            esc_url( $url ),
            esc_html( $label )
        );
    }

    private function render_remove_plan_button( int $listing_id ): void {
        $action = 'directorist_pricing_plan_remove_listing_plan';
        $url    = wp_nonce_url(
            add_query_arg(
                [
                    'action'     => $action,
                    'listing_id' => $listing_id,
                ],
                admin_url( 'admin-post.php' )
            ),
            $action . '_' . $listing_id
        );

        printf(
            '<div class="directorist-plan-removal"><a class="button directorist-plan-removal__button" href="%s" data-confirm="%s">%s</a></div>',
            esc_url( $url ),
            esc_attr__( 'Removing this listing from its plan will expire the listing. Continue?', 'directorist-pricing-plans' ),
            esc_html__( 'Remove from plan', 'directorist-pricing-plans' )
        );
    }

    private function render_metabox_styles(): void {
        ?>
        <style>
            #directorist-pricing-plan-metabox .inside {
                margin: 0;
                padding: 12px;
            }

            .directorist-plan-summary,
            .directorist-plan-empty-state {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-height: 80px;
                padding: 12px;
                box-sizing: border-box;
                border-radius: 8px;
                background: #fafafa;
                text-align: center;
            }

            .directorist-plan-summary__name {
                display: block;
                overflow-wrap: anywhere;
                margin-bottom: 7px;
                color: #1d2327;
                font-size: 14px;
                font-weight: 600;
                line-height: 1.35;
            }

            .directorist-plan-summary__name--attention {
                color: #b32d2e;
            }

            .directorist-plan-summary__badge {
                display: inline-flex;
                align-items: center;
                min-height: 19px;
                padding: 0 9px;
                border-radius: 9px;
                color: #135e96;
                background: #dceeff;
                font-size: 11px;
                font-weight: 600;
                line-height: 19px;
            }

            .directorist-plan-empty-state {
                color: #50575e;
            }

            .directorist-plan-empty-state strong {
                font-size: 13px;
                font-weight: 600;
                line-height: 1.4;
            }

            .directorist-plan-assignment {
                margin-top: 16px;
            }

            .directorist-plan-assignment__heading {
                display: flex;
                align-items: center;
                margin: 0 0 9px;
                color: #1d2327;
            }

            .directorist-plan-assignment__title {
                display: inline-flex;
                align-items: center;
                font-size: 13px;
            }

            .directorist-plan-assignment__help {
                position: relative;
                margin-left: 5px;
            }

            .directorist-plan-assignment__help-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 18px;
                height: 18px;
                padding: 0;
                border: 0;
                border-radius: 50%;
                color: #646970;
                background: transparent;
                cursor: help;
            }

            .directorist-plan-assignment__help-button:hover,
            .directorist-plan-assignment__help-button:focus {
                color: #1d2327;
                background: transparent;
                outline: none;
                box-shadow: 0 0 0 1px #8c8f94;
            }

            .directorist-plan-assignment__help-button .dashicons {
                width: 15px;
                height: 15px;
                font-size: 15px;
            }

            .directorist-plan-assignment__tooltip {
                position: absolute;
                z-index: 10;
                right: 0;
                bottom: calc(100% + 8px);
                display: none;
                width: 220px;
                padding: 9px 11px;
                border-radius: 6px;
                color: #fff;
                background: #1d2327;
                font-size: 12px;
                font-weight: 400;
                line-height: 1.45;
                box-shadow: 0 4px 12px rgba(0, 0, 0, .2);
            }

            .directorist-plan-assignment__tooltip::after {
                position: absolute;
                top: 100%;
                right: 8px;
                border: 5px solid transparent;
                border-top-color: #1d2327;
                content: '';
            }

            .directorist-plan-assignment__help:hover .directorist-plan-assignment__tooltip,
            .directorist-plan-assignment__help:focus-within .directorist-plan-assignment__tooltip {
                display: block;
            }

            .directorist-plan-assignment select {
                width: 100%;
                height: 36px;
                min-height: 0;
                margin: 0 0 6px;
                box-sizing: border-box;
                border-color: #dcdcde;
                border-radius: 6px;
                background-color: #fff;
                font-size: 14px;
            }

            .directorist-plan-assignment select:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
            }

            .directorist-plan-assignment__submit,
            .directorist-plan-action-row .button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                height: 36px;
                min-height: 0;
                box-sizing: border-box;
                border-radius: 6px;
                font-weight: 600;
            }

            .directorist-plan-assignment__submit {
                border-color: #050505 !important;
                color: #fff !important;
                background: #050505 !important;
            }

            .directorist-plan-assignment__submit:hover,
            .directorist-plan-assignment__submit:focus {
                border-color: #2c3338 !important;
                background: #2c3338 !important;
            }

            .directorist-plan-order {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin-top: 12px;
                padding: 10px 12px;
                border-radius: 6px;
                color: #50575e;
                background: #f6f7f7;
                font-size: 12px;
            }

            .directorist-plan-order a {
                font-weight: 600;
                text-decoration: none;
            }

            .directorist-plan-action-row {
                margin-top: 12px;
            }

            .directorist-plan-removal {
                margin-top: 10px;
            }

            .directorist-plan-removal__button {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                width: 100%;
                min-height: 36px;
                border-color: #dcdcde !important;
                border-radius: 6px !important;
                color: #b32d2e !important;
                background: #fff !important;
                font-weight: 600;
            }

            .directorist-plan-removal__button:hover,
            .directorist-plan-removal__button:focus {
                border-color: #b32d2e !important;
                color: #b32d2e !important;
                background: #fcf0f1 !important;
            }
        </style>
        <?php
    }

    private function render_plan_assignment_form( WP_Post $post ): void {
        $plan_groups               = [];
        $assignment_service        = directorist_pricing_plans_singleton( ListingPlanAssignmentService::class );
        $assigned_plan_id          = (int) get_post_meta( (int) $post->ID, directorist_plan_key(), true );
        $package                   = directorist_get_listing_package( (int) $post->ID );
        $has_active_assignment     = $assigned_plan_id
            && $package
            && (int) $package->plan_id === $assigned_plan_id;
        $is_new_package_assignment = ! $has_active_assignment;

        try {
            $plan_groups = $assignment_service->get_admin_plan_groups( (int) $post->ID );
        } catch ( \Exception $exception ) {
            return;
        }

        if ( empty( $plan_groups['active'] ) && empty( $plan_groups['inactive'] ) ) {
            return;
        }

        $form_action  = $is_new_package_assignment
            ? 'directorist_pricing_plan_assign_listing_plan'
            : 'directorist_pricing_plan_change_listing_plan';
        $nonce_action = $form_action . '_' . $post->ID;
        $title        = $is_new_package_assignment
            ? __( 'Assign plan', 'directorist-pricing-plans' )
            : __( 'Change plan', 'directorist-pricing-plans' );
        $button_label = $is_new_package_assignment
            ? __( 'Assign plan', 'directorist-pricing-plans' )
            : __( 'Change plan', 'directorist-pricing-plans' );
        $help_label   = $is_new_package_assignment
            ? __( 'About assigning a plan to this listing', 'directorist-pricing-plans' )
            : __( 'About changing this listing plan', 'directorist-pricing-plans' );
        $description  = $is_new_package_assignment
            ? __( 'Assign this listing to an active package, or create a paid order and package when an inactive plan is selected.', 'directorist-pricing-plans' )
            : __( 'Move this listing to another active package, or create a paid order and package when an inactive plan is selected.', 'directorist-pricing-plans' );
        $select_label = __( 'Select a plan', 'directorist-pricing-plans' );
        $placeholder  = $is_new_package_assignment
            ? __( 'Choose a plan', 'directorist-pricing-plans' )
            : __( 'Choose a new plan', 'directorist-pricing-plans' );
        $select_id    = 'directorist-pricing-plan-assignment-' . $post->ID;

        ?>
        <div
            class="directorist-plan-assignment"
            data-action-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
            data-form-action="<?php echo esc_attr( $form_action ); ?>"
            data-listing-id="<?php echo esc_attr( $post->ID ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( $nonce_action ) ); ?>"
            data-empty-message="<?php echo esc_attr__( 'Select a plan before continuing.', 'directorist-pricing-plans' ); ?>"
        >
            <div class="directorist-plan-assignment__heading">
                <span class="directorist-plan-assignment__title">
                    <strong><?php echo esc_html( $title ); ?></strong>
                </span>
                <span class="directorist-plan-assignment__help">
                    <button
                        type="button"
                        class="directorist-plan-assignment__help-button"
                        aria-label="<?php echo esc_attr( $help_label ); ?>"
                        aria-describedby="directorist-plan-assignment-tooltip-<?php echo esc_attr( $post->ID ); ?>"
                    >
                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                    </button>
                    <span
                        id="directorist-plan-assignment-tooltip-<?php echo esc_attr( $post->ID ); ?>"
                        class="directorist-plan-assignment__tooltip"
                        role="tooltip"
                    >
                        <?php echo esc_html( $description ); ?>
                    </span>
                </span>
            </div>
            <label class="screen-reader-text" for="<?php echo esc_attr( $select_id ); ?>">
                <?php echo esc_html( $select_label ); ?>
            </label>
            <select id="<?php echo esc_attr( $select_id ); ?>" class="widefat">
                <option value=""><?php echo esc_html( $placeholder ); ?></option>
                <?php if ( ! empty( $plan_groups['active'] ) ) : ?>
                    <optgroup label="<?php echo esc_attr__( 'User Plans', 'directorist-pricing-plans' ); ?>">
                        <?php foreach ( $plan_groups['active'] as $plan ) : ?>
                            <option value="<?php echo esc_attr( $plan['id'] ); ?>">
                                <?php echo esc_html( $plan['title'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if ( ! empty( $plan_groups['inactive'] ) ) : ?>
                    <optgroup label="<?php echo esc_attr__( 'Available Plans', 'directorist-pricing-plans' ); ?>">
                        <?php foreach ( $plan_groups['inactive'] as $plan ) : ?>
                            <option value="<?php echo esc_attr( $plan['id'] ); ?>">
                                <?php echo esc_html( $plan['title'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <button type="button" class="button button-primary directorist-plan-assignment__submit">
                <?php echo esc_html( $button_label ); ?>
            </button>
        </div>
        <?php
    }

    public function render_plan_assignment_script(): void {
        $screen = get_current_screen();

        if ( ! $screen || ATBDP_POST_TYPE !== $screen->post_type ) {
            return;
        }

        ?>
        <script>
            document.addEventListener( 'click', function( event ) {
                var target = event.target;
                var removalButton = target instanceof Element
                    ? target.closest( '.directorist-plan-removal__button' )
                    : null;

                if ( removalButton ) {
                    if ( ! window.confirm( removalButton.dataset.confirm || '' ) ) {
                        event.preventDefault();
                    }

                    return;
                }

                var button = target instanceof Element
                    ? target.closest( '.directorist-plan-assignment__submit' )
                    : null;

                if ( ! button ) {
                    return;
                }

                var container = button.closest( '.directorist-plan-assignment' );
                var select = container ? container.querySelector( 'select' ) : null;

                if ( ! container || ! select ) {
                    return;
                }

                if ( ! select.value ) {
                    select.setCustomValidity( container.dataset.emptyMessage || '' );
                    select.reportValidity();
                    return;
                }

                select.setCustomValidity( '' );
                button.disabled = true;

                var form = document.createElement( 'form' );
                var fields = {
                    action: container.dataset.formAction,
                    listing_id: container.dataset.listingId,
                    plan_id: select.value,
                    _wpnonce: container.dataset.nonce
                };

                form.method = 'post';
                form.action = container.dataset.actionUrl;

                Object.keys( fields ).forEach( function( name ) {
                    var input = document.createElement( 'input' );
                    input.type = 'hidden';
                    input.name = name;
                    input.value = fields[ name ];
                    form.appendChild( input );
                } );

                document.body.appendChild( form );
                form.submit();
            } );

            document.addEventListener( 'change', function( event ) {
                var target = event.target;

                if ( target instanceof Element && target.matches( '.directorist-plan-assignment select' ) ) {
                    target.setCustomValidity( '' );
                }
            } );
        </script>
        <?php
    }

    private function handle_listing_action( string $action, string $method ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce is verified immediately after validating the listing ID.
        $listing_id = isset( $_GET['listing_id'] )
            ? absint( wp_unslash( $_GET['listing_id'] ) )
            : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( ! $listing_id || ! current_user_can( 'edit_post', $listing_id ) ) {
            wp_die(
                esc_html__( 'You are not allowed to perform this action.', 'directorist-pricing-plans' ),
                '',
                [ 'response' => 403 ]
            );
        }

        check_admin_referer( $action . '_' . $listing_id );

        try {
            if ( 'renew_listing' === $method ) {
                $plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );
                $plan    = $plan_id ? directorist_get_pricing_plan_by_id( $plan_id ) : null;

                if ( $plan && PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ) {
                    directorist_pricing_plans_singleton( ExpiredListingRenewalService::class )->validate_pay_per_listing_checkout(
                        $listing_id,
                        $plan_id,
                        (int) get_post_field( 'post_author', $listing_id )
                    );
                    directorist_pricing_plans_singleton( ListingOrderService::class )->renew_listing( $listing_id );
                } else {
                    directorist_pricing_plans_singleton( ExpiredListingRenewalService::class )->renew_listing( $listing_id );
                }
            } else {
                directorist_pricing_plans_singleton( ListingOrderService::class )->{$method}( $listing_id );
            }
            $query_args = [
                'directorist_plan_action' => 'success',
            ];
        } catch ( \Exception $exception ) {
            $query_args = [
                'directorist_plan_action' => 'error',
                'directorist_plan_error'  => $exception->getMessage(),
            ];
        }

        wp_safe_redirect(
            add_query_arg(
                $query_args,
                admin_url( 'post.php?post=' . $listing_id . '&action=edit' )
            )
        );
        exit;
    }

    private function get_order_url( int $order_id ): string {
        return admin_url(
            'edit.php?post_type=at_biz_dir&page=directorist-orders#/edit/' . $order_id
        );
    }
}
