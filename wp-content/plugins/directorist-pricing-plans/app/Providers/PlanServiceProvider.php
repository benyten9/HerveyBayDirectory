<?php

namespace DirectoristPricingPlan\App\Providers;

use stdClass;

defined( "ABSPATH" ) || exit;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\Enums\Order\Status as OrderStatus;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Repositories\UserPackageRepository;
use DirectoristPricingPlan\App\Services\SubscriptionPaymentService;
use DirectoristPricingPlan\App\DTO\UserPackage\DTO as UserPackageDTO;
use DirectoristPricingPlan\App\DTO\Plan\DTO as PlanDTO;
use DirectoristPricingPlan\App\DTO\UserPackage\Activation as UserPackageActivationDTO;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;

class PlanServiceProvider implements Provider {
    public UserPackageRepository $user_package_repository;

    private SubscriptionPaymentService $subscription_payment_service;

    private array $restricted_listing_access = [];

    private bool $is_frontend_listing_update = false;

    private bool $has_rendered_package_less_notice = false;

    public function __construct( UserPackageRepository $user_package_repository, SubscriptionPaymentService $subscription_payment_service ) {
        $this->user_package_repository      = $user_package_repository;
        $this->subscription_payment_service = $subscription_payment_service;
    }

    public function boot() {
        add_action( 'transition_post_status', [ $this, 'sync_listing_expiration_with_package' ], 10, 3 );
        add_action( 'save_post', [ $this, 'sync_admin_listing_expiration_with_package' ], 100, 3 );
        add_action( 'atbdp_listing_inserted', [ $this, 'validate_non_admin_listing_publish_package' ], 100, 1 );
        add_action( 'atbdp_listing_updated', [ $this, 'validate_non_admin_listing_publish_package' ], 100, 1 );
        add_action( 'atbdp_after_renewal', [ $this, 'validate_non_admin_listing_publish_package' ], 100, 1 );
        add_filter( 'the_content', [ $this, 'restrict_package_less_listing_content' ], PHP_INT_MAX );
        add_filter( 'directorist_custom_single_listing_pre_page_content', [ $this, 'restrict_custom_single_listing_content' ], PHP_INT_MAX );
        add_filter( 'directorist_single_listing_header', [ $this, 'restrict_single_listing_header' ], PHP_INT_MAX, 2 );
        add_filter( 'directorist_single_listings_contents', [ $this, 'restrict_single_listing_sections' ], PHP_INT_MAX, 2 );
        add_filter( 'sidebars_widgets', [ $this, 'restrict_single_listing_sidebar' ], PHP_INT_MAX );
        add_action( 'directorist_single_listing_after_title', [ $this, 'render_listing_unavailable_notice' ], PHP_INT_MAX );
        add_filter( 'atbdp_add_listing_page_template', [ $this, 'add_package_less_notice_to_listing_form' ], PHP_INT_MAX, 2 );
        add_action( 'edit_form_top', [ $this, 'render_admin_package_less_listing_notice' ] );
        add_action( 'atbdp_before_processing_to_update_listing', [ $this, 'mark_frontend_listing_update' ], PHP_INT_MAX );
        add_filter( 'wp_insert_post_data', [ $this, 'prevent_frontend_listing_publish_without_package' ], PHP_INT_MAX, 2 );
        add_action( 'directorist_before_update_listing_status', [ $this, 'before_update_listing_status' ], 10, 2 );
        add_action( 'directorist_after_listing_plan_approval', [ $this, 'after_listing_plan_approval' ], 10, 2 );
        add_action( 'directorist_validate_listing_plan_approval', [ $this, 'handle_listing_plan_approval_validation' ], 10, 4 );
        add_filter( 'directorist_has_plan_remaining_quota', [ $this, 'has_plan_remaining_quota' ], 10, 3 );
        add_action( 'directorist_package_created', [ $this, 'maybe_schedule_package_expiration' ], 10, 1 );
        add_action( 'directorist_package_updated', [ $this, 'maybe_schedule_package_expiration' ], 10, 2 );
        add_action( 'directorist_package_created', [ $this, 'handle_package_listing_status' ], 20, 1 );
        add_action( 'directorist_package_updated', [ $this, 'handle_package_listing_status' ], 20, 2 );
        add_action( 'directorist_package_expiry_event', [ $this, 'handle_package_expiry_event' ], 10, 1 );
        add_action( 'directorist_after_update_plan', [ $this, 'after_update_plan' ], 10, 1 );
        add_filter( 'rest_request_before_callbacks', [ $this, 'prevent_dashboard_listing_publish_without_package' ], 10, 3 );
        add_filter( 'directorist_renewal_order', [ $this, 'create_renewal_order' ], 10, 2 );
    }

    public function restrict_package_less_listing_content( string $content ): string {
        if ( ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        if ( $this->should_restrict_listing_content() ) {
            return $this->get_listing_unavailable_notice();
        }

        if ( ! $this->has_rendered_package_less_notice && $this->should_show_package_less_listing_notice() ) {
            $this->has_rendered_package_less_notice = true;

            return $this->get_package_less_listing_notice() . $content;
        }

        return $content;
    }

    public function restrict_custom_single_listing_content( string $content ): string {
        return $this->should_restrict_listing_content() ? $this->get_restricted_listing_content() : $content;
    }

    public function restrict_single_listing_header( array $header_data, array $data ): array {
        $listing_id = (int) ( $data['listing_id'] ?? 0 );

        if ( ! $this->should_restrict_listing_content( $listing_id ) ) {
            return $header_data;
        }

        return [
            [
                'type'            => 'placeholder_item',
                'placeholderKey'  => 'listing-title-placeholder',
                'selectedWidgets' => [
                    [
                        'type'           => 'title',
                        'widget_name'    => 'title',
                        'widget_key'     => 'title',
                        'enable_tagline' => false,
                    ],
                ],
            ],
        ];
    }

    public function restrict_single_listing_sections( array $sections, array $data ): array {
        $listing_id = (int) ( $data['listing_id'] ?? 0 );

        if ( ! $this->should_restrict_listing_content( $listing_id ) ) {
            return $sections;
        }

        return [
            'fields' => [],
            'groups' => [],
        ];
    }

    public function restrict_single_listing_sidebar( array $sidebars ): array {
        if ( $this->should_restrict_listing_content() ) {
            $sidebars['right-sidebar-listing'] = [];
        }

        return $sidebars;
    }

    public function render_listing_unavailable_notice( int $listing_id = 0 ): void {
        if ( ! $this->has_rendered_package_less_notice && $this->should_show_package_less_listing_notice( $listing_id ) ) {
            $this->has_rendered_package_less_notice = true;

            echo $this->get_package_less_listing_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The method returns escaped static markup.
            return;
        }

        if ( ! $this->should_restrict_listing_content() ) {
            return;
        }

        echo $this->get_listing_unavailable_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The method returns escaped static markup.
    }

    public function add_package_less_notice_to_listing_form( string $template, array $data ): string {
        $listing_id = (int) ( $data['listing_id'] ?? 0 );

        if ( empty( $data['is_edit_mode'] ) || ! $this->should_show_package_less_listing_notice( $listing_id ) ) {
            return $template;
        }

        $container          = '<div class="directorist-container-fluid">';
        $container_position = strpos( $template, $container );

        if ( false === $container_position ) {
            return $template;
        }

        return substr_replace(
            $template,
            $container . $this->get_package_less_listing_notice(),
            $container_position,
            strlen( $container )
        );
    }

    public function render_admin_package_less_listing_notice( WP_Post $post ): void {
        if ( ! $this->is_listing_post( $post ) || ! $this->should_show_package_less_listing_notice( (int) $post->ID ) ) {
            return;
        }

        printf(
            '<div class="notice notice-warning inline directorist-package-less-listing-notice" style="margin-top: 20px; margin-bottom: 20px;"><p>%s</p></div>',
            $this->get_package_less_listing_notice_message() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The method returns an escaped static message.
        );
    }

    private function should_restrict_listing_content( int $listing_id = 0 ): bool {
        $listing_post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

        if ( ! is_singular( $listing_post_type ) ) {
            return false;
        }

        $listing_id = $listing_id ?: get_queried_object_id();

        if ( array_key_exists( $listing_id, $this->restricted_listing_access ) ) {
            return $this->restricted_listing_access[ $listing_id ];
        }

        $listing = $listing_id ? get_post( $listing_id ) : null;

        if ( ! $listing || directorist_get_listing_package( $listing_id ) ) {
            return $this->restricted_listing_access[ $listing_id ] = false;
        }

        $is_listing_owner = get_current_user_id() === (int) $listing->post_author;
        $is_restricted    = ! $is_listing_owner && ! current_user_can( 'edit_others_at_biz_dirs' );

        if ( $is_restricted ) {
            nocache_headers();
        }

        return $this->restricted_listing_access[ $listing_id ] = $is_restricted;
    }

    private function should_show_package_less_listing_notice( int $listing_id = 0 ): bool {
        if ( is_preview() ) {
            return false;
        }

        $listing_id = $listing_id ?: get_queried_object_id();
        $listing    = $listing_id ? get_post( $listing_id ) : null;

        if ( ! $listing || ! $this->is_listing_post( $listing ) || directorist_get_listing_package( $listing_id ) ) {
            return false;
        }

        return get_current_user_id() === (int) $listing->post_author || current_user_can( 'edit_others_at_biz_dirs' );
    }

    private function get_restricted_listing_content(): string {
        return sprintf(
            '<section class="directorist-single-listing-header"><h1 class="directorist-listing-details__listing-title">%s</h1></section>%s',
            esc_html( get_the_title() ),
            $this->get_listing_unavailable_notice()
        );
    }

    private function get_listing_unavailable_notice(): string {
        return sprintf(
            '<section class="directorist-alert directorist-alert-info directorist-single-listing-notice"><div class="directorist-alert__content">%s</div></section>',
            esc_html__( 'The listing data is temporarily unavailable.', 'directorist-pricing-plans' )
        );
    }

    private function get_package_less_listing_notice(): string {
        return sprintf(
            '<section class="directorist-alert directorist-alert-warning directorist-package-less-listing-notice" style="margin-top: 20px; margin-bottom: 20px;"><div class="directorist-alert__content">%s</div></section>',
            $this->get_package_less_listing_notice_message()
        );
    }

    private function get_package_less_listing_notice_message(): string {
        return esc_html__( 'This listing does not have an active package and is currently unavailable to public visitors. Please purchase a plan to make the listing publicly visible.', 'directorist-pricing-plans' );
    }

    public function mark_frontend_listing_update(): void {
        $this->is_frontend_listing_update = true;
    }

    public function prevent_frontend_listing_publish_without_package( array $data, array $postarr ): array {
        $listing_id = (int) ( $postarr['ID'] ?? 0 );
        $post        = $listing_id ? get_post( $listing_id ) : null;

        if ( ! $this->is_frontend_listing_update || ! $post || ! $this->is_listing_post( $post ) ) {
            return $data;
        }

        $this->is_frontend_listing_update = false;

        if ( 'publish' !== ( $data['post_status'] ?? '' ) ) {
            return $data;
        }

        if ( ! directorist_get_listing_package( $listing_id ) ) {
            $data['post_status'] = 'pending';
        }

        return $data;
    }

    public function after_update_plan( PlanDTO $dto ) {
        $this->user_package_repository->update_plan_listing_display_priority( $dto->get_id(), $dto->get_listing_display_priority() );
    }

    public function before_update_listing_status( int $id, string $status ) {
        if ( 'publish' !== $status ) {
            return;
        }

        $current_package = directorist_get_listing_package( $id );

        if ( ! $current_package ) {
            if ( current_user_can( 'edit_others_at_biz_dirs' ) ) {
                return;
            }

            $this->force_listing_pending_on_next_status_update( $id );
            return;
        }

        $directory_type_id = directorist_get_listing_directory( $id );

        $this->handle_listing_plan_approval_validation( $directory_type_id, $id, false, $current_package );
        $this->sync_listing_expiration_for_listing( $id );
    }

    public function prevent_dashboard_listing_publish_without_package( $response, array $handler, WP_REST_Request $request ) {
        if ( null !== $response ) {
            return $response;
        }

        if ( 'POST' !== $request->get_method() || 'publish' !== $request->get_param( 'status' ) ) {
            return $response;
        }

        if ( ! preg_match( '#^/directorist/v1/listings/(\d+)/update-status$#', $request->get_route(), $matches ) ) {
            return $response;
        }

        $listing_id = (int) $matches[1];
        $post       = get_post( $listing_id );

        if ( ! $post || ! $this->is_listing_post( $post ) ) {
            return $response;
        }

        if ( directorist_get_listing_package( $listing_id ) ) {
            return $response;
        }

        return new WP_Error(
            'directorist_pricing_plan_no_active_package',
            esc_html__( 'No active plan found.', 'directorist-pricing-plans' ),
            [ 'status' => 400 ]
        );
    }

    public function sync_listing_expiration_with_package( string $new_status, string $old_status, WP_Post $post ) {
        if ( 'publish' !== $new_status || $old_status === $new_status || ! $this->is_listing_post( $post ) ) {
            return;
        }

        $listing_id = (int) $post->ID;

        if ( $this->should_make_non_admin_listing_pending( $listing_id ) ) {
            directorist_set_listing_status( $listing_id, 'pending' );
            return;
        }

        $this->sync_listing_expiration_for_listing( $listing_id, true );
    }

    public function sync_admin_listing_expiration_with_package( int $post_id, WP_Post $post, bool $update ): void {
        if ( ! is_admin() || ! $this->is_listing_post( $post ) ) {
            return;
        }

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $this->sync_listing_expiration_for_listing( $post_id );
    }

    public function validate_non_admin_listing_publish_package( int $listing_id ): void {
        $post = get_post( $listing_id );

        if ( ! $post || ! $this->is_listing_post( $post ) || 'publish' !== get_post_status( $listing_id ) ) {
            return;
        }

        if ( current_user_can( 'edit_others_at_biz_dirs' ) ) {
            return;
        }

        if ( $this->should_make_non_admin_listing_pending( $listing_id ) ) {
            directorist_set_listing_status( $listing_id, 'pending' );
            return;
        }

        $package = directorist_get_listing_package( $listing_id );

        if ( ! $package ) {
            return;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

        if ( ! $plan ) {
            directorist_set_listing_status( $listing_id, 'pending' );
            return;
        }

        if ( PlanType::PACKAGE === ( $plan->type ?? PlanType::PACKAGE ) ) {
            directorist_pricing_plan_apply_package_listing_expiration( $listing_id, $package->current_period_end );
        }
    }

    private function is_listing_post( WP_Post $post ): bool {
        $listing_post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

        return $listing_post_type === $post->post_type;
    }

    private function force_listing_pending_on_next_status_update( int $listing_id ): void {
        $force_pending_filter = null;

        $force_pending_filter = function( array $data, array $postarr ) use ( $listing_id, &$force_pending_filter ): array {
            if ( (int) ( $postarr['ID'] ?? 0 ) !== $listing_id ) {
                return $data;
            }

            $post = get_post( $listing_id );

            if ( $post && $this->is_listing_post( $post ) ) {
                $data['post_status'] = 'pending';
            }

            remove_filter( 'wp_insert_post_data', $force_pending_filter, 10 );

            return $data;
        };

        add_filter( 'wp_insert_post_data', $force_pending_filter, 10, 2 );
    }

    private function should_make_non_admin_listing_pending( int $listing_id ): bool {
        if ( current_user_can( 'edit_others_at_biz_dirs' ) ) {
            return false;
        }

        if ( ! directorist_get_listings_directory_type( $listing_id ) ) {
            return false;
        }

        return ! directorist_get_listing_package( $listing_id );
    }

    private function sync_listing_expiration_for_listing( int $listing_id, bool $include_pay_per_listing = false ): void {
        $package = directorist_get_listing_package( $listing_id );

        if ( ! $package ) {
            return;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

        if ( ! $plan ) {
            return;
        }

        $plan_type = $plan->type ?? PlanType::PACKAGE;

        if ( PlanType::PACKAGE === $plan_type ) {
            directorist_pricing_plan_apply_package_listing_expiration( $listing_id, $package->current_period_end );
            return;
        }

        if ( $include_pay_per_listing && PlanType::PAY_PER_LISTING === $plan_type ) {
            directorist_pricing_plan_apply_plan_listing_expiration( $listing_id, $plan );
        }
    }

    public function maybe_schedule_package_expiration( UserPackageDTO $package_dto, ?UserPackageDTO $previous_package_dto = null ) {
        if ( in_array( $package_dto->get_status(), [ UserPackageStatus::CANCELLED, UserPackageStatus::ARCHIVED, UserPackageStatus::EXPIRED, UserPackageStatus::PAST_DUE ], true ) ) {
            wp_clear_scheduled_hook( 'directorist_package_expiry_event', [ [ 'package_id' => $package_dto->get_id() ] ] );
            return;
        }
        
        if ( ! $package_dto->get_current_period_end() ) {
            return;
        }

        if ( $previous_package_dto && $previous_package_dto->get_current_period_end() && $previous_package_dto->get_current_period_end()->getTimestamp() === $package_dto->get_current_period_end()->getTimestamp() ) {
            return;
        }

        wp_clear_scheduled_hook( 'directorist_package_expiry_event', [ [ 'package_id' => $package_dto->get_id() ] ] );
        wp_schedule_single_event( $package_dto->get_current_period_end()->getTimestamp(), 'directorist_package_expiry_event', [ [ 'package_id' => $package_dto->get_id() ] ] );

        do_action( 'after_schedule_package_expiration', $package_dto );
    }

    public function handle_package_expiry_event( array $package_data ) {
        $package_id = (int) ( $package_data['package_id'] ?? 0 );

        if ( ! $package_id ) {
            return;
        }

        $user_package = $this->user_package_repository->get_by_id( $package_id );

        if ( ! $this->is_package_due_for_expiration( $user_package ) ) {
            return;
        }

        // Check if the plan still exists
        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $user_package->plan_id );

        if ( ! $plan ) {
            return;
        }

        if ( UserPackageStatus::CANCELLED_AT_PERIOD_END !== $user_package->status ) {
            $prepaid_order = $this->user_package_repository->get_prepaid_order_for_package( (int) $user_package->id );

            if ( $prepaid_order ) {
                $this->user_package_repository->renew( (int) $user_package->id, (int) $prepaid_order->id, 'prepaid_order' );
                return;
            }

            if ( ! empty( $user_package->is_recurring ) && ! empty( $user_package->subscription_method ) && ! empty( $user_package->subscription_id ) ) {
                $renewal_order_id = $this->subscription_payment_service->recheck( $package_id, (string) $user_package->subscription_method );

                // Gateways may synchronize a webhook while resolving the renewal.
                $user_package = $this->user_package_repository->get_by_id( $package_id );

                if ( ! $this->is_package_due_for_expiration( $user_package ) ) {
                    return;
                }

                if ( $renewal_order_id ) {
                    return;
                }
            }
        }

        // A payment webhook can renew the package while this cron callback is running.
        // Re-read it so a stale expiration event cannot overwrite the renewed period.
        $user_package = $this->user_package_repository->get_by_id( $package_id );

        if ( ! $this->is_package_due_for_expiration( $user_package ) ) {
            return;
        }

        // Activate the fallback plan if it exists
        if ( $plan->fallback_plan_id ) {
            $activated = $this->activate_fallback_plan(
                (int) $user_package->user_id,
                (int) $plan->fallback_plan_id,
                (int) $plan->directory_type_id,
                (int) $plan->id
            );

            if ( $activated ) {
                $this->mark_package_expired_or_past_due( $user_package );

                // Get the new package ID to send notification
                $new_package = $this->user_package_repository->get_package_by_plan( (int) $user_package->user_id, (int) $plan->fallback_plan_id );
                
                if ( $new_package ) {
                    do_action( 'directorist_fallback_plan_activated', $new_package->id, $plan->id );
                }
                return;
            }
        }

        $this->mark_package_expired_or_past_due( $user_package );
    }

    private function is_package_due_for_expiration( ?stdClass $package ): bool {
        if ( ! $package || ! in_array( $package->status, [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ], true ) ) {
            return false;
        }

        $current_period_end = $this->user_package_repository->to_dto( $package )->get_current_period_end();

        return $current_period_end && $current_period_end->getTimestamp() <= directorist_now()->getTimestamp();
    }

    private function mark_package_expired_or_past_due( stdClass $package ): void {
        if ( UserPackageStatus::CANCELLED_AT_PERIOD_END !== $package->status && ! empty( $package->is_recurring ) ) {
            $this->user_package_repository->mark_package_past_due( (int) $package->id );
            return;
        }

        $this->user_package_repository->expire_package( (int) $package->id );
    }

    public function activate_fallback_plan( int $user_id, int $fallback_plan_id, int $current_directory_type_id, int $source_plan_id = 0 ): bool {
        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $fallback_plan_id );

        if ( ! $plan || (int) $plan->directory_type_id !== $current_directory_type_id || $plan->fee_type !== FeeType::FREE ) {
            return false;
        }

        $currency  = atbdp_get_payment_currency();
        $order_dto = ( new OrderDTO )->set_user_id( $user_id )->set_ref_type( OrderRefType::PRICING_PLAN )->set_ref( $plan->id )->set_currency( $currency )->set_status( OrderStatus::PAID );

        $order_dto->set_amount( $plan->price )->set_sub_total( $plan->price );

        if ( $plan->is_taxable ) {
            $order_dto->set_tax_type( $plan->tax_type )->set_tax_rate( $plan->tax_rate );
        }

        $order_id = directorist_order_repository()->create( $order_dto );

        if ( ! $order_id ) {
            return false;
        }

        if ( $source_plan_id ) {
            $plan_repository->reassign_belonging_listings( $user_id, $source_plan_id, (int) $plan->id );
        }

        if ( 0 === $plan->is_allowed_unlimited_listings ) {
            $plan_repository->make_exceeding_listings_as_private( $user_id, (int) $plan->id, $plan->allowed_listings );
        }

        if ( 0 === $plan->is_allowed_unlimited_featured_listings ) {
            $plan_repository->make_exceeding_featured_listings_as_regular( $user_id, (int) $plan->id, $plan->allowed_featured_listings );
        }

        $user_package_activation_dto = ( new UserPackageActivationDTO )
            ->set_user_id( $user_id )
            ->set_plan( $plan )
            ->set_order_id( $order_id );

        directorist_pricing_plan_activate_package( $user_package_activation_dto );

        return true;
    }

    public function create_renewal_order( $order, string $subscription_id ) {
        if ( $order ) {
            return $order;
        }

        return $this->user_package_repository->create_renewal_order_by_subscription_id( $subscription_id );
    }

    /**
     * @throws Exception
     */
    public function handle_listing_plan_approval_validation( int $directory_type_id, int $listing_id, bool $is_featured_listing, ?stdClass $current_package = null ) {
        if ( ! $current_package ) {
            $current_package = directorist_get_listing_package( $listing_id );
        }

        if ( ! $current_package ) {
            throw new Exception( __( 'No active plan found.', 'directorist-pricing-plans' ), 404 );
        }

        $plan = directorist_get_pricing_plan_by_id( $current_package->plan_id );

        if ( ! $plan ) {
            throw new Exception( __( 'The plan associated with this package no longer exists.', 'directorist-pricing-plans' ), 404 );
        }

        $plan_type = $plan->type ?? PlanType::PACKAGE;

        if ( PlanType::PAY_PER_LISTING === $plan_type ) {
            // For pay per listing, check if the listing has a paid order by this plan
            $order = directorist_order_repository()->get_query_builder()
                ->where( 'listing_id', $listing_id )
                ->where( 'plan_id', $plan->id )
                ->where( 'status', 'paid' )
                ->first();

            if ( ! $order ) {
                throw new Exception( __( 'Payment is required for this listing.', 'directorist-pricing-plans' ), 400 );
            }

            $this->after_listing_plan_approval( $listing_id, $is_featured_listing );
            return;
        }

        // Check if plan has quota, if not then throw an exception
        if ( ! $this->has_plan_remaining_quota( true, $plan, $is_featured_listing ) ) {
            throw new Exception( 'You have reached the maximum number of allowed listings.', 400 );
        }

        $this->after_listing_plan_approval( $listing_id, $is_featured_listing );
    }

    public function handle_package_listing_status( UserPackageDTO $package_dto, ?UserPackageDTO $previous_package_dto = null ) {
        if ( $package_dto->is_is_legacy() ) {
            return;
        }

        $status = $package_dto->get_status();

        /**
         * @var PlanRepository $plan_repository
         */
        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );

        if ( in_array( $status, [ UserPackageStatus::CANCELLED, UserPackageStatus::ARCHIVED, UserPackageStatus::EXPIRED, UserPackageStatus::PAST_DUE ], true ) ) {
            $plan_repository->expire_belonging_listings( $package_dto->get_user_id(), $package_dto->get_plan_id() );
            return;
        }

        if ( UserPackageStatus::ACTIVE !== $status ) {
            return;
        }

        $plan      = directorist_get_pricing_plan_by_id( $package_dto->get_plan_id() );
        $plan_type = $plan ? $plan->type : PlanType::PACKAGE;

        $this->handle_last_order_listing_activation( $package_dto, $plan, $plan_type );

        if ( ! $plan ) {
            return;
        }

        if ( PlanType::PACKAGE !== $plan_type ) {
            return;
        }

        $was_expired = false;

        if ( $previous_package_dto ) {
            $was_expired = in_array( $previous_package_dto->get_status(), [ UserPackageStatus::EXPIRED, UserPackageStatus::PAST_DUE ], true )
                || ( $previous_package_dto->get_current_period_end()
                    && $previous_package_dto->get_current_period_end()->getTimestamp() < directorist_now()->getTimestamp() );
        }

        if ( ! $package_dto->is_is_recurring() && ! $was_expired ) {
            return;
        }

        $current_period_end = $package_dto->get_current_period_end() ? $package_dto->get_current_period_end()->format( 'Y-m-d H:i:s' ) : '';

        if ( empty( $current_period_end ) ) {
            return;
        }

        $max_limit = ! empty( $plan->is_allowed_unlimited_listings ) ? -1 : (int) $plan->allowed_listings;

        if ( 0 === $max_limit ) {
            return;
        }

        $plan_repository->renew_belonging_listings_expiration(
            $package_dto->get_user_id(),
            $package_dto->get_plan_id(),
            $current_period_end,
            $max_limit
        );
    }

    private function handle_last_order_listing_activation( UserPackageDTO $package_dto, ?stdClass $plan, string $plan_type ): void {
        $last_order = $this->user_package_repository->get_last_order( $package_dto->get_id() );

        if ( ! $last_order || ! $last_order->is_initialized( 'listing_id' ) || null === $last_order->get_listing_id() ) {
            return;
        }

        $listing_id          = (int) $last_order->get_listing_id();
        $is_featured_listing = $last_order->is_initialized( 'is_featured_listing' ) ? (bool) $last_order->get_is_featured_listing() : false;

        if ( PlanType::PAY_PER_LISTING === $plan_type && $plan && ! empty( $plan->is_featured ) ) {
            $is_featured_listing = true;
        }

        if ( $plan ) {
            update_post_meta( $listing_id, directorist_plan_key(), (int) $plan->id );
        }

        do_action( 'directorist_after_listing_plan_approval', $listing_id, $is_featured_listing );

        if ( ! $plan ) {
            return;
        }

        if ( PlanType::PAY_PER_LISTING === $plan_type ) {
            directorist_pricing_plan_apply_plan_listing_expiration( $listing_id, $plan );
            return;
        }

        directorist_pricing_plan_apply_package_listing_expiration(
            $listing_id,
            $package_dto->get_current_period_end() ? $package_dto->get_current_period_end()->format( 'Y-m-d H:i:s' ) : null
        );
    }

    public function after_listing_plan_approval( int $listing_id, bool $is_featured_listing = false ) {
        // Make the listing featured if it is true
        directorist_set_listing_featured( $listing_id, $is_featured_listing );

        $directory_type_id = directorist_get_listing_directory( $listing_id );

        if ( ! $directory_type_id ) {
            return;
        }

        directorist_set_listing_status( $listing_id, directorist_get_listing_create_status( $directory_type_id ) );
    }

    public function has_plan_remaining_quota( bool $has_remaining_quota, stdClass $plan, bool $is_featured_listing ): bool {
        $plan_type = $plan->type ?? PlanType::PACKAGE;

        // Quota check only applies to package type plans
        if ( PlanType::PAY_PER_LISTING === $plan_type ) {
            return true;
        }

        if ( ! directorist_plan_has_listing_quota( $plan ) ) {
            return false;
        }

        $current_package = directorist_user_package_repository()->get_current_package( get_current_user_id(), $plan->directory_type_id );
        $package_usage   = directorist_package_usage( ! empty( $current_package->is_legacy ) );
        $uses            = $package_usage->get_listings_uses( get_current_user_id(), $plan, $is_featured_listing );

        return $uses['remaining'] === -1 || $uses['remaining'] > 0;
    }
}
