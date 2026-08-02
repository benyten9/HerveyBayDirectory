<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( "ABSPATH" ) || exit;

use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Repositories\OrderRepository;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Jobs\UnassignedPlanOrderQueue;

class NoticeServiceProvider implements Provider {
    private OrderRepository $order_repository;

    private UnassignedPlanOrderQueue $unassigned_plan_order_queue;

    public function __construct( OrderRepository $order_repository, UnassignedPlanOrderQueue $unassigned_plan_order_queue ) {
        $this->order_repository            = $order_repository;
        $this->unassigned_plan_order_queue = $unassigned_plan_order_queue;
    }

    public function boot() {
        add_action( 'admin_notices', [$this, 'show_plan_assignment_notice'] );
        add_action( 'admin_init', [$this, 'handle_notice_dismiss'] );
        add_action( 'admin_init', [$this, 'handle_plan_assignment_dismiss'] );
    }

    /**
     * Show admin notice for listing owners without pricing plans
     */
    public function show_plan_assignment_notice() {
        // Allow other providers (e.g., migration) to suppress this notice
        if ( ! apply_filters( 'directorist_should_show_plan_assignment_notice', true ) ) {
            return;
        }

        // Only show on relevant admin pages
        $screen = get_current_screen();

        if ( ! $screen || ! in_array( $screen->id, $this->get_relevant_screen_ids(), true ) ) {
            return;
        }

        // Check if user has permission
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Show status notice if queue is finished
        if ( $this->unassigned_plan_order_queue->is_finished() ) {
            $this->render_plan_assignment_status_notice();
            return;
        }

        // Show progress notice if queue is dispatched and still processing
        if ( $this->unassigned_plan_order_queue->is_active() ) {
            if ( ! $this->unassigned_plan_order_queue->is_processing() ) {
                $this->unassigned_plan_order_queue->dispatch();
            }

            $this->render_plan_assignment_progress_notice();
            return;
        }

        $this->show_listing_directory_assignment_notice();

        // Get directory types with no plans
        $directory_types_with_no_plans = directorist_pricing_plans_singleton( PlanRepository::class )->get_directory_types_with_no_plans();

        if ( empty( $directory_types_with_no_plans ) ) {
            return;
        }

        $all_plans = directorist_pricing_plans_singleton( PlanRepository::class )->get_all_plans_by_directory_types( $directory_types_with_no_plans );

        $all_plans = array_map(
            function( $plan ) {
                return [
                    'id'                => (int) $plan->id,
                    'title'             => $plan->title,
                    'fee_type'          => $plan->fee_type,
                    'directory_type_id' => (int) $plan->directory_type_id,
                ];
            }, $all_plans 
        );

        // Fetch all directories (terms) by directory type ids
        $directories = [];

        $directory_type_ids = array_map( 'intval', $directory_types_with_no_plans );
        $terms              = get_terms(
            [
                'taxonomy'   => ATBDP_DIRECTORY_TYPE,
                'include'    => $directory_type_ids,
                'hide_empty' => false,
            ] 
        );

        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            foreach ( $terms as $term ) {
                $plans = array_values(
                    array_map(
                        function( $plan ) {
                            unset( $plan['directory_type_id'] );
                            return $plan;
                        },
                        array_filter(
                            $all_plans, function( $plan ) use ( $term ) {
                                return (int) $plan['directory_type_id'] === (int) $term->term_id;
                            } 
                        )
                    )
                );

                $directories[] = [
                    'id'    => (int) $term->term_id,
                    'name'  => $term->name,
                    'slug'  => $term->slug,
                    'plans' => $plans,
                ];
            }
        }

        if ( empty( $directories ) ) {
            return;
        }

        wp_enqueue_script( 'directorist-pricing-plans-listing-table-notice' );

        wp_localize_script(
            'directorist-pricing-plans-listing-table-notice', 'directorist_plans_assignment_data', 
            [
                'directories'  => $directories,
                'order_status' => [ 
                    [ 'value' => OrderStatus::PAID, 'label' => __( 'Paid', 'directorist-pricing-plans' ) ], 
                    [ 'value' => OrderStatus::PENDING, 'label' => __( 'Pending', 'directorist-pricing-plans' ) ] 
                ],
            ] 
        );

        $this->render_notice( count( $directories ) );
    }

    private function show_listing_directory_assignment_notice() {
        $listings = $this->get_listings_with_invalid_directory_type();

        if ( empty( $listings ) ) {
            return;
        }

        $directories = array_map(
            function( $directory ) {
                return [
                    'id'   => (int) $directory->term_id,
                    'name' => $directory->name,
                ];
            },
            directorist_get_directories(
                [
                    'hide_empty'   => false,
                    'default_only' => false,
                ]
            )
        );

        if ( empty( $directories ) ) {
            return;
        }

        wp_enqueue_script( 'directorist-pricing-plans-listing-table-notice' );

        wp_localize_script(
            'directorist-pricing-plans-listing-table-notice',
            'directorist_listing_directory_assignment_data',
            [
                'listings'    => $listings,
                'directories' => $directories,
            ]
        );

        $this->render_listing_directory_assignment_notice( count( $listings ) );
    }

    private function get_listings_with_invalid_directory_type(): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
            "SELECT post.ID, post.post_title, directory_type.meta_value AS directory_type_id
            FROM {$wpdb->posts} AS post
            LEFT JOIN {$wpdb->postmeta} AS directory_type
                ON post.ID = directory_type.post_id
                AND directory_type.meta_key = '_directory_type'
            WHERE post.post_type = %s
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->term_relationships} AS directory_relationship
                    INNER JOIN {$wpdb->term_taxonomy} AS directory_taxonomy
                        ON directory_relationship.term_taxonomy_id = directory_taxonomy.term_taxonomy_id
                    WHERE directory_relationship.object_id = post.ID
                        AND directory_taxonomy.taxonomy = %s
                )
            ORDER BY post.ID DESC",
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ATBDP_POST_TYPE,
            ATBDP_DIRECTORY_TYPE
        );

        $listings = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.

        return array_map(
            function( $listing ) {
                $directory_type_id = absint( $listing->directory_type_id );

                return [
                    'id'                       => (int) $listing->ID,
                    'title'                    => $listing->post_title ?: sprintf( '#%d', (int) $listing->ID ),
                    'current_directory_type'   => $directory_type_id,
                    'current_directory_status' => $directory_type_id ? sprintf( __( 'Meta has directory #%d, but term is missing', 'directorist-pricing-plans' ), $directory_type_id ) : __( 'Directory term not assigned', 'directorist-pricing-plans' ),
                ];
            },
            $listings
        );
    }

    /**
     * Handle notice dismissal
     */
    public function handle_notice_dismiss() {
        if ( ! isset( $_GET['directorist_dismiss_plan_notice'], $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'directorist_dismiss_plan_notice' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Redirect to remove query parameters
        wp_safe_redirect( remove_query_arg( ['directorist_dismiss_plan_notice', '_wpnonce'] ) );
        exit;
    }

    /**
     * Handle plan assignment dismissal
     */
    public function handle_plan_assignment_dismiss() {
        if ( ! isset( $_GET['directorist_dismiss_plan_assignment'], $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'directorist_dismiss_plan_assignment' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->unassigned_plan_order_queue->reset();
        $this->unassigned_plan_order_queue->clear_failed_queues();

        // Redirect to remove query parameters
        wp_safe_redirect( remove_query_arg( ['directorist_dismiss_plan_assignment', '_wpnonce'] ) );
        exit;
    }

    /**
     * Get relevant screen IDs where notice should be shown
     */
    private function get_relevant_screen_ids() {
        return [
            'edit-at_biz_dir',
            'at_biz_dir_page_atbdp-settings',
            'at_biz_dir_page_atbdp-directory-types',
            'at_biz_dir_page_directorist-status',
            'dashboard',
            'plugins',
        ];
    }

    /**
     * Render the admin notice
     */
    private function render_notice( $count ) {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'directorist_dismiss_plan_notice', '1' ),
            'directorist_dismiss_plan_notice'
        );

        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e( 'Directorist Notice:', 'directorist-pricing-plans' ); ?></strong>
                <?php
                printf(
                    esc_html(
                        _n( 
                            'There is %d directory type needs plan assignment. Assign now?',
                            'There are %d directory types needs plan assignment. Assign them now?', 
                            $count, 
                            'directorist-pricing-plans' 
                        ) 
                    ),
                    intval( $count )
                );
                ?>
                <button href="#" class="button button-primary directorist-plan-assign-btn" style="margin-left: 10px;">
                    <?php esc_html_e( 'Assign Plans', 'directorist-pricing-plans' ); ?>
                </button>
                <div class="directorist-plan-assign-modal"></div>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="notice-dismiss" style="text-decoration: none;">
                    <span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'directorist-pricing-plans' ); ?></span>
                </a>
            </p>
        </div>
        
        <style>
        @media (max-width: 768px) {
            .directorist-plan-assign-btn {
                display: block !important;
                width: 100% !important;
                margin-left: 0 !important;
                margin-top: 10px !important;
                text-align: center !important;
            }
        }
        </style>
        <?php
    }

    private function render_listing_directory_assignment_notice( int $count ) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Directorist Notice:', 'directorist-pricing-plans' ); ?></strong>
                <?php
                printf(
                    esc_html(
                        _n(
                            'There is %d listing with missing or deleted directory type.',
                            'There are %d listings with missing or deleted directory types.',
                            $count,
                            'directorist-pricing-plans'
                        )
                    ),
                    intval( $count )
                );
                ?>
                <button href="#" class="button button-primary directorist-listing-directory-assign-btn" style="margin-left: 10px;">
                    <?php esc_html_e( 'Assign Directory Type', 'directorist-pricing-plans' ); ?>
                </button>
                <div class="directorist-listing-directory-assign-modal"></div>
            </p>
        </div>
        <?php
    }

    /**
     * Render the plan assignment progress notice
     */
    private function render_plan_assignment_progress_notice() {
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e( 'Directorist Notice:', 'directorist-pricing-plans' ); ?></strong>
                <?php echo esc_html__( 'Plan assignment to the listing owners is in progress...', 'directorist-pricing-plans' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the plan assignment status notice
     */
    private function render_plan_assignment_status_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'directorist_dismiss_plan_assignment', '1' ),
            'directorist_dismiss_plan_assignment'
        );

        $total_failed_queues = $this->unassigned_plan_order_queue->get_total_failed_queues();

        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong><?php esc_html_e( 'Directorist Notice:', 'directorist-pricing-plans' ); ?></strong>
                <?php echo esc_html__( 'Plan assignment to the listing owners has been completed.', 'directorist-pricing-plans' ); ?>
                <?php if ( $total_failed_queues > 0 ) { ?>
                    <span class="notice-error">
                        <?php printf(
                            esc_html(
                                _n( 
                                    'There was some issue while assigning plans to %d listing owner, please check the logs for more details.',
                                    'There was some issue while assigning plans to %d listing owners, please check the logs for more details.',
                                    $total_failed_queues,
                                    'directorist-pricing-plans' 
                                ) 
                            ),
                            intval( $total_failed_queues )
                        ); ?>
                    </span>
                <?php } ?>
            </p>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" class="notice-dismiss" style="text-decoration: none;">
                <span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'directorist-pricing-plans' ); ?></span>
            </a>
        </div>
        <?php
    }
}
