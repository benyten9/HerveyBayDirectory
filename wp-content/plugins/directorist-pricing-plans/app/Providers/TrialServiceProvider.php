<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use stdClass;
use WP_REST_Request;
use Directorist\Utils\RequestValidator as DirectoristValidator;
use Directorist\Utils\Mime;
use Directorist\DTO\Order\DTO;
use Directorist\Contracts\PaymentInterface;

use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\DTO\Plan\DTO as PlanDTO;
use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\WpMVC\View\View;

class TrialServiceProvider implements Provider {
    const CHECKOUT_TYPE = 'plan';

    public function boot() {
        add_action( 'directorist_checkout_validation', [ $this, 'validate_checkout' ], 10, 2 );
        add_filter( 'directorist_plan_checkout_create_order', [ $this, 'handle_checkout_create_order' ], 10, 3 );
        add_action( 'directorist_checkout_validate_payment_processor', [ $this, 'validate_payment_processor' ], 10, 4 );
        add_filter( 'directorist_pricing_plan_hide_gateway_for_subscription', [ $this, 'handle_hide_gateway_for_subscription' ], 10, 5 );
        add_filter( 'directorist_checkout_submit_button_label', [ $this, 'handle_trial_button_label' ], 10, 4 );
        add_filter( 'directorist_checkout_show_payment_gateways', [ $this, 'handle_trial_gateway_visibility' ], 10, 4 );
        add_action( 'atbdp_before_checkout_form_end', [ $this, 'before_checkout_form_end' ], 10 );
        add_filter( 'directorist_checkout_plan_summary_data', [ $this, 'handle_trial_plan_summary_data' ], 10, 1 );
        add_filter( 'directorist_checkout_table_after_total', [ $this, 'handle_trial_plan_due_summary' ], 10, 4 );
    }

    public function validate_payment_processor( PaymentInterface $payment_processor, DTO $order_dto, string $checkout_type, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return;
        }

        if ( (int) $request->get_param( 'is_trial' ) !== 1 ) {
            return;
        }

        if ( ! $payment_processor->supports_trial() ) {
            throw new Exception( __( 'This payment processor does not support trials.', 'directorist-pricing-plans' ), 400 );
        }
    }

    public function validate_checkout( string $checkout_type, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return;
        }

        $validator = new DirectoristValidator( $request, new Mime );

        $errors = $validator->validate(
            [
                'is_trial' => 'accepted:0,1',
            ]
        );

        if ( ! empty( $errors ) ) {
            throw new \Exception( array_values( $errors )[0][0], 422 );
        }

        // Skip if trial is not enabled
        if ( (int) $request->get_param( 'is_trial' ) !== 1 ) {
            return;
        }

        // Get the trial plan
        $plan = $this->get_plan_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            throw new \Exception( __( 'Trial plan not found.', 'directorist-pricing-plans' ), 404 );
        }

        // Trial is not enabled for this plan
        if ( ! (bool) $plan->is_trial_enabled ) {
            throw new \Exception( __( 'Trial is not enabled for this plan.', 'directorist-pricing-plans' ), 400 );
        }

        // Free plans cannot have trials
        if ( $plan->fee_type === FeeType::FREE ) {
            throw new \Exception( __( 'Free plans do not offer trials.', 'directorist-pricing-plans' ), 400 );
        }

        if ( ! $this->is_user_trial_eligible( $plan ) ) {
            throw new \Exception( __( 'You are not eligible for trial.', 'directorist-pricing-plans' ), 400 );
        }
    }

    public function handle_checkout_create_order( DTO $order_dto, WP_REST_Request $request, stdClass $plan ): DTO {
        $is_trial = $request->get_param( 'is_trial' );

        if ( (int) $is_trial !== 1 ) {
            return $order_dto;
        }

        $user_id = get_current_user_id();

        // Check if user already used trial for this directory
        if ( directorist_user_has_used_trial( $user_id, $plan->directory_type_id ) ) {
            throw new Exception( __( 'You have already used a trial for this directory.', 'directorist-pricing-plans' ), 400 );
        }

        $order_dto->set_amount( 0 )->set_sub_total( 0 );

        return $order_dto;
    }

    public function handle_trial_button_label( string $label, string $checkout_type, float $subtotal, WP_REST_Request $request ): string {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $label;
        }

        $trial_plan = $this->get_trial_plan( $request->get_params() );

        if ( ! $trial_plan ) {
            return $label;
        }

        if ( ! $this->is_user_trial_eligible( $trial_plan ) ) {
            return $label;
        }

        return __( 'Start Trial', 'directorist-pricing-plans' );
    }

    public function handle_trial_gateway_visibility( bool $show, string $checkout_type, float $subtotal, WP_REST_Request $request ): bool {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $show;
        }

        $trial_plan = $this->get_trial_plan( $request->get_params() );

        if ( ! $trial_plan ) {
            return $show;
        }

        // If user is not eligible for trial, show gateways
        if ( ! $this->is_user_trial_eligible( $trial_plan ) ) {
            return $show;
        }

        $plan = $this->get_plan_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            return $show;
        }

        // If subscription is enabled, show gateways
        if ( directorist_plan_has_subscription( $plan ) ) {
            return $show;
        }

        // If subscription is not enabled and user is eligible for trial so hide gateways
        return false;
    }

    private function get_trial_plan( array $params ): ?stdClass {
        static $trial_plan = null;

        if ( null !== $trial_plan ) {
            return $trial_plan;
        }

        $plan = $this->get_plan_by_id( isset( $params['plan_id'] ) ? $params['plan_id'] : null );

        if ( ! $plan ) {
            return null;
        }

        if ( empty( $plan->is_trial_enabled ) ) {
            return null;
        }

        if ( $plan->fee_type === FeeType::FREE ) {
            return null;
        }

        return $plan;
    }

    private function is_user_trial_eligible( stdClass $plan ): bool {
        static $is_eligible = null;

        if ( null !== $is_eligible ) {
            return $is_eligible;
        }

        $is_eligible = directorist_is_user_trial_eligible( $plan->directory_type_id );

        return $is_eligible;
    }

    public function handle_hide_gateway_for_subscription( bool $hide, PaymentInterface $payment_processor, stdClass $plan, array $active_gateways, string $checkout_type ): bool {
        $trial_plan = $this->get_trial_plan( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $trial_plan ) {
            return $hide;
        }
        
        if ( ! $this->is_user_trial_eligible( $trial_plan ) ) {
            return $hide;
        }

        if ( ! $payment_processor->supports_trial() ) {
            return true;
        }

        return $hide;
    }

    public function handle_trial_plan_summary_data( array $data ) {
        /**
         * @var PlanDTO $plan
         */
        $plan = $data['plan'];

        if ( ! $plan->is_is_trial_enabled() ) {
            return $data;
        }

        if ( $plan->get_fee_type() === FeeType::FREE ) {
            return $data;
        }

        if ( ! $this->is_user_trial_eligible( $data['plan_data'] ) ) {
            return $data;
        }

        $data['trial_end_at'] = directorist_get_expiry_date( $plan->get_trial_interval_count(), $plan->get_trial_interval_type() );

        return $data;
    }

    public function handle_trial_plan_due_summary( string $checkout_type, float $total, float $subtotal, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $request->get_param( 'plan_id' ) );

        if ( ! $plan ) {
            throw new Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }
        
        if ( ! directorist_is_trial_eligible( $plan ) ) {
            return;
        }

        View::render( 'plan-due-summary', [ 'total_due' => 0 ] );
    }

    public function before_checkout_form_end() {
        // Output hidden is_trial field if user is eligible for trial
        $trial_plan = $this->get_trial_plan( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $trial_plan ) {
            return;
        }

        if ( $this->is_user_trial_eligible( $trial_plan ) ) {
            echo '<input type="hidden" name="is_trial" value="1">';
        }
    }

    private function get_plan_by_id( ?int $plan_id ): ?stdClass {
        static $plan     = null;
        static $resolved = false;

        if ( $resolved ) {
            return $plan;
        }

        if ( ! $plan_id ) {
            return null;
        }

        $resolved = true;

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $plan_id );

        return $plan;
    }
}