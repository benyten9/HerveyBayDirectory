<?php

namespace DirectoristPricingPlan\App\Providers;

use stdClass;

defined( "ABSPATH" ) || exit;

use WP_REST_Request;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\Contracts\PaymentInterface;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanIntervalType;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Repositories\PlanOrderMetaRepository;
use DirectoristPricingPlan\WpMVC\View\View;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

class PaymentCheckoutServiceProvider implements Provider {
    const CHECKOUT_TYPE = 'payment';

    public function boot() {
        add_filter( 'directorist_checkout_process_payment', [$this, 'checkout_process_payment'], 10, 3 );
        add_filter( 'directorist_checkout_show_payment_gateways', [ $this, 'handle_gateway_visibility' ], 10, 4 );
        add_action( 'directorist_checkout_table', [ $this, 'handle_checkout_table' ], 5, 4 );
        add_filter( 'directorist_checkout_payment_order', [ $this, 'handle_checkout_payment_order' ], 10, 1 );
        add_filter( 'directorist_checkout_total', [ $this, 'handle_checkout_payment_total' ], 10, 3 );
        add_filter( 'directorist_checkout_submit_button_label', [ $this, 'handle_checkout_submit_button_label' ], 10, 4 );
        add_filter( 'directorist_checkout_active_gateways', [ $this, 'handle_active_gateways' ], 40, 3 );
        add_filter( 'directorist_payment_receipt_order_dto', [ $this, 'handle_payment_receipt_order_dto' ], 10, 1 );
        add_filter( 'directorist_checkout_table_after_total', [ $this, 'handle_trial_plan_due_summary' ], 10, 4 );
    }

    public function handle_payment_receipt_order_dto( OrderDTO $order_dto ) {
        if ( empty( $order_dto->get_ref_type() ) || empty( $order_dto->get_ref() ) ) {
            return $order_dto;
        }
        
        if ( $order_dto->get_ref_type() !== OrderRefType::PRICING_PLAN || empty( $order_dto->get_ref() ) ) {
            return $order_dto;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order_dto->get_id() );

        if ( ! $order_meta ) {
            return $order_dto;
        }

        if ( (int) $order_meta->is_trial !== 1 ) {
            return $order_dto;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $order_dto->get_ref() );

        if ( ! $plan ) {
            return $order_dto;
        }

        $order_dto->set_sub_total( $plan->price );

        return $order_dto;
    }

    public function checkout_process_payment( bool $process_payment, OrderDTO $order_dto, WP_REST_Request $request ) {
        if ( self::CHECKOUT_TYPE !== $request->get_param( 'checkout_type' )  ) {
            return $process_payment;
        }

        if ( ! $order_dto->is_initialized( 'ref_type' ) || ! $order_dto->is_initialized( 'ref' ) ) {
            return $process_payment;
        }

        if ( $order_dto->get_ref_type() !== OrderRefType::PRICING_PLAN || empty( $order_dto->get_ref() ) ) {
            return $process_payment;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order_dto->get_id() );

        if ( ! $order_meta ) {
            return $process_payment;
        }

        if ( (int) $order_meta->is_trial !== 1 ) {
            return $process_payment;
        }

        if ( (int) $order_meta->is_recurring !== 1 ) {
            return $process_payment;
        }

        return true;
    }

    public function handle_gateway_visibility( bool $show, string $checkout_type, float $subtotal, WP_REST_Request $request ): bool {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $show;
        }

        $order = directorist_get_order_by_id( $request->get_param( 'order_id' ) );

        if ( ! $order ) {
            return $show;
        }

        if ( empty( $order->ref_type ) || empty( $order->ref ) ) {
            return $show;
        }

        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return $show;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->id );

        if ( ! $order_meta ) {
            return $show;
        }

        if ( (int) $order_meta->is_trial !== 1 ) {
            return $show;
        }

        if ( (int) $order_meta->is_recurring !== 1 ) {
            return $show;
        }

        return true;
    }

    public function handle_active_gateways( array $active_gateways, string $checkout_type, WP_REST_Request $request ): array {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $active_gateways;
        }

        $order = directorist_get_order_by_id( $request->get_param( 'order_id' ) );

        if ( ! $order ) {
            return $active_gateways;
        }

        if ( empty( $order->ref_type ) || empty( $order->ref ) ) {
            return $active_gateways;
        }

        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return $active_gateways;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->id );

        if ( ! $order_meta ) {
            return $active_gateways;
        }
 
        $payment_processors = directorist_get_payment_processors();

        // If no payment processors found, return default active gateways
        if ( empty( $payment_processors ) || ! is_array( $payment_processors ) ) {
            return $active_gateways;
        }

        foreach ( $active_gateways as $gateway_name ) {
            if ( ! isset( $payment_processors[ $gateway_name ] ) ) {
                continue;
            }

            /**
             * @var PaymentInterface $instance
             */
            $instance = directorist_make( $payment_processors[ $gateway_name ], __( 'Invalid payment gateway.', 'directorist-pricing-plans' ) );

            if ( (int) $order_meta->is_recurring === 1 && ! $instance->supports_recurring() ) {
                $index = array_search( $gateway_name, $active_gateways );
                unset( $active_gateways[ $index ] );
            }
            
            if ( (int) $order_meta->is_trial === 1 && ! $instance->supports_trial() ) {
                $index = array_search( $gateway_name, $active_gateways );
                unset( $active_gateways[ $index ] );
            }
        }

        return $active_gateways;
    }

    public function handle_checkout_table( string $checkout_type, float $total, float $subtotal, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return;
        }

        $order = directorist_get_order_by_id( $request->get_param( 'order_id' ) );

        if ( ! $order ) {
            return;
        }

        if ( empty( $order->ref_type ) || empty( $order->ref ) ) {
            return;
        }

        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $order->ref );

        if ( ! $plan ) {
            throw new Exception( __( 'Invalid plan id.', 'directorist-pricing-plans' ) );
        }

        View::render(
            'order-payment-summary', [
                'plan'   => $plan_repository->to_dto( $plan ),
                'expiry' => $this->order_expiry( $order, $plan ),
            ]
        );
    }

    public function order_expiry( stdClass $order, stdClass $plan ) {
        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->id );

        if ( $order_meta ) {
            $expiry_date          = null;
            $trial_duration_label = '';

            if ( 1 === (int) $order_meta->is_trial ) {
                $trial_duration_label = sprintf( ' ( %d %s )', $order_meta->interval_count, ( $order_meta->interval_type . ( (int) $order_meta->interval_count > 1 ? 's' : '' ) ) );
            }

            if ( ! empty( $order_meta->current_period_end ) ) {
                $expiry_date = date_i18n( get_option( 'date_format' ), strtotime( $order_meta->current_period_end ) );
            } else if ( ! empty( $order_meta->interval_count ) && ! empty( $order_meta->interval_type ) ) {
                $expiry_date = directorist_get_expiry_date( $order_meta->interval_count, $order_meta->interval_type );
            }

            if ( ! $expiry_date ) {
                return null;
            }

            $expiry_date = date_i18n( get_option( 'date_format' ), strtotime( $expiry_date ) );

            if ( 1 === (int) $order_meta->is_trial ) {
                $expiry_label = __( 'Trial ends on', 'directorist-pricing-plans' ) . $trial_duration_label;
            } else if ( 1 === (int) $order_meta->is_recurring ) {
                $expiry_label = __( 'First renewal', 'directorist-pricing-plans' );
            } else {
                $expiry_label = __( 'Expires on', 'directorist-pricing-plans' );
            }

            return [
                'label' => $expiry_label,
                'value' => $expiry_date,
            ];
        }

        if ( PlanIntervalType::LIFETIME === $plan->interval_type ) {
            return [
                'label' => __( 'Expires on', 'directorist-pricing-plans' ),
                'value' => __( 'Never Expires', 'directorist-pricing-plans' ),
            ];
        }

        $is_trial_eligible = directorist_is_plan_trial_eligible( $plan ) && directorist_is_user_trial_eligible( $plan->directory_type_id );

        if ( $is_trial_eligible ) {
            $expiry_date = directorist_get_expiry_date( $plan->trial_interval_count, $plan->trial_interval_type );
        } else {
            $expiry_date = directorist_get_expiry_date( $plan->interval_count, $plan->interval_type );
        }

        if ( ! $expiry_date ) {
            return null;
        }

        $expiry_date = date_i18n( get_option( 'date_format' ), strtotime( $expiry_date ) );

        if ( $is_trial_eligible ) {
            $trial_duration_label = sprintf( ' ( %d %s )', $plan->trial_interval_count, ( $plan->trial_interval_type . ( (int) $plan->trial_interval_count > 1 ? 's' : '' ) ) );
            $expiry_label         = __( 'Trial ends on', 'directorist-pricing-plans' ) . $trial_duration_label;
        } else if ( directorist_plan_has_subscription( $plan ) ) {
            $expiry_label = __( 'First renewal', 'directorist-pricing-plans' );
        } else {
            $expiry_label = __( 'Expires on', 'directorist-pricing-plans' );
        }

        return [
            'label' => $expiry_label,
            'value' => $expiry_date,
        ];
    }

    public function handle_checkout_payment_order( stdClass $order ) {
        if ( empty( $order->ref_type ) || empty( $order->ref ) ) {
            return;
        }

        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return $order;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->id );

        if ( ! $order_meta ) {
            return $order;
        }

        if ( (int) $order_meta->is_trial !== 1 ) {
            return $order;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $order->ref );

        if ( ! $plan ) {
            return $order;
        }

        $order->sub_total = $plan->price;

        return $order;
    }

    public function handle_checkout_payment_total( float $total, string $checkout_type, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $total;
        }

        $order = directorist_get_order_by_id( $request->get_param( 'order_id' ) );

        if ( ! $order ) {
            return $total;
        }

        if ( empty( $order->ref_type ) || empty( $order->ref ) ) {
            return $total;
        }

        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return $total;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->id );

        if ( ! $order_meta ) {
            return $total;
        }

        if ( (int) $order_meta->is_trial !== 1 ) {
            return $total;
        }

        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );
        $plan            = $plan_repository->get_by_id( $order->ref );

        if ( ! $plan ) {
            return $total;
        }

        $order->sub_total = $plan->price;

        return directorist_order_total_amount( $order );
    }

    public function handle_checkout_submit_button_label( string $label, string $checkout_type, float $subtotal, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return $label;
        }

        $order = directorist_get_order_by_id( $request->get_param( 'order_id' ) );

        if ( ! $order ) {
            return $label;
        }

        if ( empty( $order->ref_type ) || empty( $order->ref ) ) {
            return $label;
        }

        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return $label;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->id );

        if ( ! $order_meta ) {
            return $label;
        }

        if ( (int) $order_meta->is_trial !== 1 ) {
            return $label;
        }

        return __( 'Start Trial', 'directorist-pricing-plans' );
    }

    public function handle_trial_plan_due_summary( string $checkout_type, float $total, float $subtotal, WP_REST_Request $request ) {
        if ( $checkout_type !== self::CHECKOUT_TYPE ) {
            return;
        }

        $order = directorist_get_order_by_id( $request->get_param( 'order_id' ) );

        if ( ! $order ) {
            return;
        }

        if ( empty( $order->ref_type ) || empty( $order->ref ) ) {
            return;
        }

        if ( OrderRefType::PRICING_PLAN !== $order->ref_type ) {
            return;
        }

        $order_meta_repo = directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
        $order_meta      = $order_meta_repo->get_by_order_id( $order->id );

        if ( ! $order_meta ) {
            return;
        }

        if ( (int) $order_meta->is_trial !== 1 ) {
            return;
        }

        View::render( 'plan-due-summary', [ 'total_due' => 0 ] );
    }
}