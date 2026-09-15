<?php

namespace DirectoristPricingPlan\App\Http\Controllers;

defined( "ABSPATH" ) || exit;

use WP_REST_Request;
use DirectoristPricingPlan\WpMVC\Routing\Response;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\WpMVC\RequestValidator\Validator;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Services\ExpiredListingRenewalService;
use DirectoristPricingPlan\App\Services\ListingPlanAssignmentService;

class ListingsController extends Controller {
    public PlanRepository $plan_repository;

    private ListingPlanAssignmentService $assignment_service;

    private ExpiredListingRenewalService $renewal_service;

    public function __construct(
        PlanRepository $plan_repository,
        ListingPlanAssignmentService $assignment_service,
        ExpiredListingRenewalService $renewal_service
    ) {
        $this->plan_repository    = $plan_repository;
        $this->assignment_service = $assignment_service;
        $this->renewal_service    = $renewal_service;
    }

    public function compatible_plans( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate( [ 'id' => 'required|numeric' ] );

        return Response::send(
            $this->assignment_service->get_dashboard_plan_options(
                (int) $request->get_param( 'id' ),
                get_current_user_id()
            )
        );
    }

    public function change_plan( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'id'      => 'required|numeric',
                'plan_id' => 'required|integer',
            ]
        );

        return Response::send(
            $this->assignment_service->assign(
                (int) $request->get_param( 'id' ),
                (int) $request->get_param( 'plan_id' ),
                get_current_user_id()
            )
        );
    }

    public function remove_plan( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate( [ 'id' => 'required|numeric' ] );

        $this->assignment_service->remove_package_plan(
            (int) $request->get_param( 'id' ),
            get_current_user_id()
        );

        return Response::send(
            [
                'removed' => true,
                'status'  => 'expired',
                'message' => esc_html__( 'The listing was removed from its plan and expired.', 'directorist-pricing-plans' ),
            ]
        );
    }

    public function renew( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate( [ 'id' => 'required|numeric' ] );

        $result = $this->renewal_service->renew_listing(
            (int) $request->get_param( 'id' ),
            get_current_user_id()
        );

        return Response::send(
            [
                'message' => ! empty( $result['converted_to_regular'] )
                    ? esc_html__( 'The listing was renewed as a regular listing because no featured quota was available.', 'directorist-pricing-plans' )
                    : esc_html__( 'The listing was renewed successfully.', 'directorist-pricing-plans' ),
                'data'    => $result,
            ]
        );
    }

    public function mark_listing_as( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'listing_id' => 'required|integer',
                'mark_as'    => 'required|string|accepted:regular,featured',
            ]
        );

        $listing_id = (int) $request->get_param( 'listing_id' );
        $mark_as    = $request->get_param( 'mark_as' );

        $listing = get_post( $listing_id );

        if ( ! $listing ) {
            throw new Exception( esc_html__( 'The listing was not found', 'directorist-pricing-plans' ), 404 );
        }

        if ( $listing->post_status !== 'publish' ) {
            throw new Exception( esc_html__( 'The listing is not published yet', 'directorist-pricing-plans' ), 400 );
        }

        $current_package = directorist_get_listing_package( $listing_id );

        if ( ! $current_package ) {
            throw new Exception( esc_html__( 'No active plan was found', 'directorist-pricing-plans' ), 404 );
        }

        $plan = directorist_get_pricing_plan_by_id( $current_package->plan_id );

        if ( ! $plan ) {
            throw new Exception( esc_html__( 'The plan associated with your package is not available anymore.', 'directorist-pricing-plans' ), 404 );
        }

        if ( PlanType::PAY_PER_LISTING === $plan->type ) {
            if ( 'featured' === $mark_as && ! $plan->is_featured ) {
                throw new Exception( esc_html__( 'Your package does not support featured listings, please upgrade your plan.', 'directorist-pricing-plans' ), 400 );
            }

            if ( 'featured' === $mark_as ) {
                $this->plan_repository->mark_listing_as_featured( $listing_id );

                return Response::send(
                    [
                        'message' => esc_html__( 'The listing was marked as featured successfully', 'directorist-pricing-plans' )
                    ]
                );
            }

            $this->plan_repository->mark_listing_as_regular( $listing_id );

            return Response::send(
                [
                    'message' => esc_html__( 'The listing was marked as regular successfully', 'directorist-pricing-plans' )
                ]
            );
        }

        $package_usage       = directorist_package_usage( ! empty( $current_package->is_legacy ) );
        $is_featured_request = 'featured' === $mark_as;

        if ( $is_featured_request ) {
            if ( ! (bool) $plan->is_allowed_unlimited_featured_listings && (int) $plan->allowed_featured_listings < 1 ) {
                throw new Exception( esc_html__( 'Your package does not support featured listings, please upgrade your plan.', 'directorist-pricing-plans' ), 400 );
            }
        } else {
            if ( ! (bool) $plan->is_allowed_unlimited_listings && (int) $plan->allowed_listings < 1 ) {
                throw new Exception( esc_html__( 'Your package does not support regular listings, please upgrade your plan.', 'directorist-pricing-plans' ), 400 );
            }
        }

        if ( ! $package_usage->has_plan_remaining_quota( $plan, $is_featured_request ) ) {
            if ( $is_featured_request ) {
                throw new Exception( esc_html__( 'You have no remaining quota for featured listings', 'directorist-pricing-plans' ), 400 );
            }

            throw new Exception( esc_html__( 'You have no remaining quota for regular listings', 'directorist-pricing-plans' ), 400 );
        }

        if ( $is_featured_request ) {
            $this->plan_repository->mark_listing_as_featured( $listing_id );

            return Response::send(
                [
                    'message' => esc_html__( 'The listing was marked as featured successfully', 'directorist-pricing-plans' )
                ]
            );
        }

        $this->plan_repository->mark_listing_as_regular( $listing_id );

        return Response::send(
            [
                'message' => esc_html__( 'The listing was marked as regular successfully', 'directorist-pricing-plans' )
            ]
        );
    }
}
