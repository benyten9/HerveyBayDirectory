<?php

namespace DirectoristPricingPlan\App\Http\Controllers;

defined( "ABSPATH" ) || exit;

use WP_REST_Request;
use DirectoristPricingPlan\WpMVC\Routing\Response;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\WpMVC\RequestValidator\Validator;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\App\Repositories\UserPackageRepository;
use DirectoristPricingPlan\App\DTO\UserPackage\Read;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Services\ExpiredListingRenewalService;
use DirectoristPricingPlan\App\Services\SubscriptionPaymentService;

class PackageController extends Controller {
    public UserPackageRepository $package_repository;

    private SubscriptionPaymentService $subscription_payment_service;

    public function __construct( UserPackageRepository $user_package_repository, SubscriptionPaymentService $subscription_payment_service ) {
        $this->package_repository           = $user_package_repository;
        $this->subscription_payment_service = $subscription_payment_service;
    }

    public function user_packages( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'page'              => 'numeric',
                'per_page'          => 'numeric',
                'search'            => 'string',
                'directory_type_id' => 'numeric',
                'is_recurring'      => 'accepted:0,1',
            ] 
        );

        $dto = ( new Read )
            ->set_user_id( get_current_user_id() )
            ->set_page( $request->has_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1 )
            ->set_per_page( $request->has_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 10 )
            ->set_search( $request->has_param( 'search' ) ? $request->get_param( 'search' ) : null )
            ->set_directory_type_id( $request->has_param( 'directory_type_id' ) ? (int) $request->get_param( 'directory_type_id' ) : null )
            ->set_is_recurring( $request->has_param( 'is_recurring' ) ? 1 === (int) $request->get_param( 'is_recurring' ) : null );

        return Response::send( $this->package_repository->get( $dto ) );
    }

    public function renewable_listings(): array {
        $service = directorist_pricing_plans_singleton( ExpiredListingRenewalService::class );

        return Response::send(
            [
                'data' => [
                    'packages' => $service->get_eligible_packages( get_current_user_id() ),
                ],
            ]
        );
    }

    public function renew_expired_listings( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'id' => 'required|numeric',
            ]
        );

        $result = directorist_pricing_plans_singleton( ExpiredListingRenewalService::class )->renew(
            (int) $request->get_param( 'id' ),
            get_current_user_id()
        );

        return Response::send(
            [
                'message' => sprintf(
                    esc_html( _n( '%d listing renewed successfully.', '%d listings renewed successfully.', $result['renewed_count'], 'directorist-pricing-plans' ) ),
                    $result['renewed_count']
                ),
                'data'    => $result,
            ]
        );
    }

    public function usage( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $package = $this->package_repository->get_usage_by_id( (int) $request->get_param( "id" ), get_current_user_id() );

        if ( null === $package ) {
            throw new Exception( esc_html__( "Package not found.", 'directorist-pricing-plans' ) );
        }

        return Response::send(
            [
                "data" => [
                    "uses" => $package->uses,
                ],
            ]
        );
    }

    public function recheck_payment( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'id' => 'required|numeric',
            ]
        );

        $package_id = (int) $request->get_param( 'id' );
        $package    = $this->package_repository->get_by_id( $package_id );

        if ( ! $package ) {
            throw new Exception( esc_html__( 'Package not found.', 'directorist-pricing-plans' ), 404 );
        }

        if ( ! current_user_can( 'manage_options' ) && (int) $package->user_id !== get_current_user_id() ) {
            throw new Exception( esc_html__( 'You are not authorized to re-check payment for this package.', 'directorist-pricing-plans' ), 403 );
        }

        $triggered_by = current_user_can( 'manage_options' ) ? 'admin' : 'user';
        $order_id     = $this->subscription_payment_service->recheck( $package_id, $triggered_by );

        if ( ! $order_id ) {
            throw new Exception( esc_html__( 'No paid renewal payment was found.', 'directorist-pricing-plans' ), 409 );
        }

        return Response::send(
            [
                'message' => esc_html__( 'Payment found and package renewed successfully.', 'directorist-pricing-plans' ),
                'data'    => [
                    'renewed'  => true,
                    'order_id' => $order_id,
                ],
            ]
        );
    }

    public function cancel_at_period_end( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $package = $this->package_repository->get_by_id( $request->get_param( "id" ) );

        if ( ! $package ) {
            throw new Exception( esc_html__( "Package not found.", 'directorist-pricing-plans' ) );
        }

        if ( (int) $package->user_id !== get_current_user_id() ) {
            throw new Exception( esc_html__( "You are not authorized to cancel this package.", 'directorist-pricing-plans' ) );
        }

        $plan      = $package->plan_id ? Plan::query()->where( 'id', $package->plan_id )->get() : null;
        $plan_type = $plan->type ?? PlanType::PACKAGE;

        if ( PlanType::PAY_PER_LISTING === $plan_type ) {
            throw new Exception( esc_html__( "Cancel at period end is not applicable for pay per listing packages.", 'directorist-pricing-plans' ) );
        }

        if ( PlanType::PACKAGE === $plan_type && PlanInterval::LIFETIME === $plan->interval_type ) {
            throw new Exception( esc_html__( "Cancel at period end is not applicable for lifetime packages.", 'directorist-pricing-plans' ) );
        }

        // If recurring cancel via subscription gateway
        if ( (int) $package->is_recurring === 1 ) {
            $is_cancelled = apply_filters( "directorist_pricing_plan_subscription_canceled_at_period_end", true, $this->package_repository->to_dto( $package ), 'subscriber' );

            if ( ! $is_cancelled ) {
                throw new Exception( esc_html__( "Package was not cancelled, please try again.", 'directorist-pricing-plans' ) );
            }
        }

        $this->package_repository->cancel_package_at_period_end( $package->id, 'user' );

        return Response::send(
            [
                "message" => esc_html__( "Package was scheduled for cancellation.", 'directorist-pricing-plans' )
            ]
        );
    }

    public function cancel( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $package = $this->package_repository->get_by_id( $request->get_param( "id" ) );

        if ( ! $package ) {
            throw new Exception( esc_html__( "Package not found.", 'directorist-pricing-plans' ) );
        }

        if ( (int) $package->user_id !== get_current_user_id() ) {
            throw new Exception( esc_html__( "You are not authorized to cancel this package.", 'directorist-pricing-plans' ) );
        }

        // If recurring cancel via subscription gateway
        if ( (int) $package->is_recurring === 1 ) {
            $is_cancelled = apply_filters( "directorist_pricing_plan_subscription_canceled", true, $this->package_repository->to_dto( $package ), 'subscriber' );

            if ( ! $is_cancelled ) {
                throw new Exception( esc_html__( "Package was not cancelled, please try again.", 'directorist-pricing-plans' ) );
            }
        }

        $this->package_repository->cancel_package( $package->id, 'user' );

        return Response::send(
            [
                "message" => esc_html__( "Package was cancelled.", 'directorist-pricing-plans' )
            ]
        );
    }
}
