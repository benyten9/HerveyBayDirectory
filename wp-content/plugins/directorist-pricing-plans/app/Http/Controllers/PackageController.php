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

class PackageController extends Controller {
    public UserPackageRepository $package_repository;

    public function __construct( UserPackageRepository $user_package_repository ) {
        $this->package_repository = $user_package_repository;
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
            ->set_with_usage_data( true )
            ->set_page( $request->has_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1 )
            ->set_per_page( $request->has_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 10 )
            ->set_search( $request->has_param( 'search' ) ? $request->get_param( 'search' ) : null )
            ->set_directory_type_id( $request->has_param( 'directory_type_id' ) ? (int) $request->get_param( 'directory_type_id' ) : null )
            ->set_is_recurring( $request->has_param( 'is_recurring' ) ? 1 === (int) $request->get_param( 'is_recurring' ) : null );

        return Response::send( $this->package_repository->get( $dto ) );
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