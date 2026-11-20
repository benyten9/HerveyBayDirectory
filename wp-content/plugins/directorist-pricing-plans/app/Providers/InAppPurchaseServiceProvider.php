<?php

namespace DirectoristPricingPlan\App\Providers;

defined( 'ABSPATH' ) || exit;

use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\DTO\Payment\DTO as PaymentDTO;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Enums\Payment\Status as PaymentStatus;
use DirectoristPricingPlan\App\Repositories\Admin\PlanAppConfigurationRepository;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use WP_Error;
use WP_REST_Request;

class InAppPurchaseServiceProvider implements Provider {
    const PAYMENT_METHOD = 'apple_in_app_purchase';

    public function boot() {
        add_filter( 'directorist_app_toolkit_iap_plan_context', [ $this, 'get_plan_context' ], 10, 3 );
        add_filter( 'directorist_app_toolkit_iap_create_pending_order', [ $this, 'create_pending_order' ], 10, 3 );
        add_filter( 'directorist_app_toolkit_iap_complete_order', [ $this, 'complete_order' ], 10, 5 );
        add_action( 'directorist_app_toolkit_iap_fail_order', [ $this, 'fail_order' ], 10, 1 );
        add_filter( 'directorist_option', [ $this, 'get_payment_method_title' ], 10, 2 );
    }

    public function get_plan_context( $context, $plan_id, WP_REST_Request $request ) {
        if ( class_exists( 'DWPP_Pricing_Plans' ) || null !== $context ) {
            return $context;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $plan_id );
        if ( ! $plan ) {
            return $context;
        }

        $this->prepare_checkout_request( $request, $plan_id );
        do_action( 'directorist_checkout_validation', CheckoutServiceProvider::CHECKOUT_TYPE, $request );

        $configurations = directorist_pricing_plans_singleton( PlanAppConfigurationRepository::class )->get_by_plan_id( (int) $plan_id );
        $configuration  = null;

        foreach ( $configurations as $item ) {
            if ( isset( $item->type ) && 'apple_app_store' === $item->type ) {
                $configuration = $item;
                break;
            }
        }

        if ( ! $configuration ) {
            return new WP_Error( 'directorist_iap_plan_not_configured', __( 'This plan is not configured for the selected app store.', 'directorist-pricing-plans' ), [ 'status' => 422 ] );
        }

        $price = '' !== trim( (string) $configuration->product_price ) ? $configuration->product_price : $plan->price;

        return [
            'provider'        => 'directorist_pricing_plans',
            'order_type'      => 'directorist',
            'plan_id'         => (int) $plan_id,
            'product_id'      => (string) $configuration->product_id,
            'expected_amount' => (string) $price,
            'currency'        => strtoupper( (string) atbdp_get_payment_currency() ),
        ];
    }

    public function create_pending_order( $pending, array $context, WP_REST_Request $request ) {
        if ( 'directorist_pricing_plans' !== ( $context['provider'] ?? '' ) ) {
            return $pending;
        }

        $this->prepare_checkout_request( $request, $context['plan_id'] );

        $dto = ( new OrderDTO() )
            ->set_user_id( get_current_user_id() )
            ->set_currency( $context['currency'] )
            ->set_status( OrderStatus::PENDING );

        do_action( 'directorist_checkout_create_order', $dto, CheckoutServiceProvider::CHECKOUT_TYPE, $request );

        if ( ! $dto->is_initialized( 'id' ) || ! $dto->get_id() ) {
            return new WP_Error( 'directorist_iap_order_create_failed', __( 'Failed to create the pending Directorist order.', 'directorist-pricing-plans' ), [ 'status' => 500 ] );
        }

        directorist_order_repository()->silent_update(
            ( new OrderDTO() )
                ->set_id( $dto->get_id() )
                ->set_amount( (float) $context['expected_amount'] )
                ->set_sub_total( (float) $context['expected_amount'] )
                ->set_currency( $context['currency'] )
        );

        return [
            'order_type' => 'directorist',
            'order_id'   => $dto->get_id(),
        ];
    }

    public function complete_order( $completed, array $pending, array $verification, array $context, WP_REST_Request $request ) {
        if ( 'directorist_pricing_plans' !== ( $context['provider'] ?? '' ) ) {
            return $completed;
        }

        $payment_id = directorist_payment_repository()->create(
            ( new PaymentDTO() )
                ->set_order_id( (int) $pending['order_id'] )
                ->set_amount( (float) $verification['amount'] )
                ->set_currency( $verification['currency'] )
                ->set_status( PaymentStatus::PAID )
                ->set_method( self::PAYMENT_METHOD )
                ->set_transaction_id( $verification['transaction_id'] )
        );

        if ( ! $payment_id ) {
            return new WP_Error( 'directorist_iap_payment_create_failed', __( 'The store purchase was verified, but the Directorist payment could not be recorded.', 'directorist-pricing-plans' ), [ 'status' => 500 ] );
        }

        return directorist_order_repository()->single( (int) $pending['order_id'] );
    }

    public function fail_order( array $pending ) {
        if ( 'directorist' !== ( $pending['order_type'] ?? '' ) || empty( $pending['order_id'] ) ) {
            return;
        }

        directorist_order_repository()->update_status( (int) $pending['order_id'], OrderStatus::FAILED );
    }

    public function get_payment_method_title( $value, $name ) {
        if ( self::PAYMENT_METHOD . '_title' !== $name ) {
            return $value;
        }

        return __( 'Apple in-app Purchase', 'directorist-pricing-plans' );
    }

    private function prepare_checkout_request( WP_REST_Request $request, $plan_id ) {
        $request->set_param( 'plan_id', (int) $plan_id );
        $request->set_param( 'checkout_type', CheckoutServiceProvider::CHECKOUT_TYPE );
        $request->set_param( 'is_featured', $request->get_param( 'is_featured' ) ? '1' : '0' );
    }
}
