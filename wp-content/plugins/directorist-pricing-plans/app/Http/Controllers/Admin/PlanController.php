<?php

namespace DirectoristPricingPlan\App\Http\Controllers\Admin;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\TaxType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\App\Enums\Plan\TrialInterval as PlanTrialInterval;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\WpMVC\Routing\Response;
use DirectoristPricingPlan\WpMVC\RequestValidator\Validator;
use DirectoristPricingPlan\App\DTO\Plan\DTO;
use DirectoristPricingPlan\App\DTO\Plan\Read;
use DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository;
use DirectoristPricingPlan\App\Repositories\Admin\PlanAppConfigurationRepository;
use WP_REST_Request;

class PlanController extends Controller {
    public PlanRepository $repository;

    public PlanFeatureRepository $plan_feature_repository;

    public PlanAppConfigurationRepository $plan_app_configuration_repository;

    public function __construct( PlanRepository $repository, PlanFeatureRepository $plan_feature_repository, PlanAppConfigurationRepository $plan_app_configuration_repository ) {
        $this->repository                        = $repository;
        $this->plan_feature_repository           = $plan_feature_repository;
        $this->plan_app_configuration_repository = $plan_app_configuration_repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Validator $validator Instance of the Validator.
     * @param WP_REST_Request $request The REST request instance.
     * @return array
     */
    public function index( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "page"     => "numeric",
                "per_page" => "numeric",
                "search"   => "string"
            ]
        );

        $dto = ( new Read )
            ->set_page( (int) $request->get_param( "page" ) )
            ->set_per_page( (int) $request->get_param( "per_page" ) )
            ->set_search( (string) $request->get_param( "search" ) );

        return Response::send( $this->repository->get( $dto ) );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Validator $validator Instance of the Validator.
     * @param WP_REST_Request $request The REST request instance.
     * @return array
     */
    public function store( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'title'          => 'required|string|max:255',
                'directory_type' => 'required|integer',
                'type'           => 'string|accepted:' . implode( ',', PlanType::all() ),
            ]
        );

        $directory_type      = $request->get_param( "directory_type" );
        $directory_type_term = $this->get_directory_type( $directory_type );

        $dto = ( new DTO )
            ->set_title( $request->get_param( "title" ) )
            ->set_directory_type_id( $directory_type_term->term_id );

        if ( $request->get_param( 'type' ) ) {
            $dto->set_type( $request->get_param( 'type' ) );
        }

        if ( $dto->get_type() === PlanType::PAY_PER_LISTING ) {
            $dto->set_fee_type( PlanFeeType::PAID )->set_price( 1 );
        }

        $id = $this->repository->create( $dto );

        return Response::send(
            [
                "message" => esc_html__( "Plan was created successfully", 'directorist-pricing-plans' ),
                "data"    => [
                    "id" => $id
                ]
            ], 201
        );
    }

    /**
     * Display the specified resource.
     *
     * @param Validator $validator Instance of the Validator.
     * @param WP_REST_Request $request The REST request instance.
     * @return array
     * @throws Exception
     */
    public function show( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $plan = $this->repository->get_single( $request->get_param( "id" ) );

        if ( ! $plan ) {
            throw new Exception( esc_html__( "Plan not found", 'directorist-pricing-plans' ) );
        }

        return Response::send(
            apply_filters(
                'directorist_pricing_plan_admin_plan_response',
                (array) $plan,
                $plan,
                $request
            )
        );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Validator $validator Instance of the Validator.
     * @param WP_REST_Request $request The REST request instance.
     * @return array
     */
    public function update( Validator $validator, WP_REST_Request $request ): array {
        $request = apply_filters( 'directorist_pricing_plan_admin_plan_request', $request );

        $plan_type          = $request->get_param( "type" ) ?? PlanType::PACKAGE;
        $is_pay_per_listing = PlanType::PAY_PER_LISTING === $plan_type;

        $validation_rules = [
            "id"                        => "required|numeric",
            // Basic Information
            "title"                     => "required|string|max:255",
            "description"               => "string|max:1000",
            // Plan Type
            "type"                      => "required|string|accepted:" . implode( ",", PlanType::all() ),
            "fee_type"                  => "required|string|accepted:" . implode( ",", PlanFeeType::all() ),
            "price"                     => "required|numeric",
            // Tax
            "is_taxable"                => "required|boolean",
            "tax_type"                  => "required|string|accepted:" . implode( ",", TaxType::all() ),
            "tax_rate"                  => "required|numeric",
            // Subscription
            "interval_type"             => "required|string|accepted:" . implode( ",", PlanInterval::all() ),
            "interval_count"            => "required|numeric",
            "is_subscription_enabled"   => "required|boolean",
            // Sort Order
            "sort_order"                => "required|numeric",
            // Status
            "is_published"              => "required|boolean",
            // Visibility
            "is_hidden_from_plans_list" => "required|boolean",
            "is_marked_as_recommended"  => "required|boolean",
            // Fallback
            "is_fallback"               => "boolean",
            "fallback_plan_id"          => "numeric",
            "listing_display_priority"  => "numeric",
            "features"                  => "required|array",
            "app_configurations"        => "array",
        ];

        if ( $is_pay_per_listing ) {
            $validation_rules['is_featured'] = 'required|boolean';
        } else {
            // Listing Limits — only for package type
            $validation_rules['allowed_listings']                       = 'required|numeric';
            $validation_rules['is_allowed_unlimited_listings']          = 'required|boolean';
            $validation_rules['allowed_featured_listings']              = 'required|numeric';
            $validation_rules['is_allowed_unlimited_featured_listings'] = 'required|boolean';
            // Trial — only for package type
            $validation_rules['is_trial_enabled']     = 'required|boolean';
            $validation_rules['trial_interval_type']  = 'required|string|accepted:' . implode( ',', PlanTrialInterval::all() );
            $validation_rules['trial_interval_count'] = 'required|numeric';
        }

        $validator->validate( $validation_rules );

        if ( ! $is_pay_per_listing ) {
            $allowed_listings          = absint( $request->get_param( 'allowed_listings' ) );
            $allowed_featured_listings = absint( $request->get_param( 'allowed_featured_listings' ) );
            
            if ( rest_sanitize_boolean( $request->get_param( 'is_allowed_unlimited_listings' ) ) ) {
                if ( $allowed_featured_listings > $allowed_listings ) {
                    $request->set_param( 'allowed_featured_listings', $allowed_listings );
                }
            } else {
                if ( $allowed_featured_listings > $allowed_listings ) {
                    throw new Exception(
                        esc_html__( 'The number of featured listings cannot exceed the total listings allowed.', 'directorist-pricing-plans' ),
                        400
                    );
                }
            }
        }

        $dto = ( new DTO )
            ->set_id( $request->get_param( "id" ) )
            ->set_title( $request->get_param( "title" ) )
            ->set_description( (string) $request->get_param( "description" ) )
            ->set_type( $plan_type )
            ->set_fee_type( $request->get_param( "fee_type" ) )
            ->set_price( $request->get_param( "price" ) )
            ->set_is_taxable( $request->get_param( "is_taxable" ) )
            ->set_tax_type( $request->get_param( "tax_type" ) )
            ->set_tax_rate( $request->get_param( "tax_rate" ) )
            ->set_interval_type( $request->get_param( "interval_type" ) )
            ->set_interval_count( absint( $request->get_param( "interval_count" ) ) )
            ->set_is_subscription_enabled( $request->get_param( "is_subscription_enabled" ) )
            ->set_sort_order( absint( $request->get_param( "sort_order" ) ) )
            ->set_is_published( $request->get_param( "is_published" ) )
            ->set_is_hidden_from_plans_list( $request->get_param( "is_hidden_from_plans_list" ) )
            ->set_is_marked_as_recommended( $request->get_param( "is_marked_as_recommended" ) )
            ->set_is_fallback( (bool) $request->get_param( "is_fallback" ) )
            ->set_fallback_plan_id( (int) $request->get_param( "fallback_plan_id" ) )
            ->set_listing_display_priority( (int) $request->get_param( "listing_display_priority" ) );

        if ( $is_pay_per_listing ) {
            $dto
                ->set_fee_type( PlanFeeType::PAID )
                ->set_is_featured( (bool) $request->get_param( "is_featured" ) )
                ->set_allowed_listings( 0 )
                ->set_is_allowed_unlimited_listings( false )
                ->set_allowed_featured_listings( 0 )
                ->set_is_allowed_unlimited_featured_listings( false )
                ->set_is_trial_enabled( false )
                ->set_trial_interval_type( PlanTrialInterval::MONTH )
                ->set_trial_interval_count( 1 );
        } else {
            $dto->set_is_featured( false )
                ->set_allowed_listings( $request->get_param( "allowed_listings" ) )
                ->set_is_allowed_unlimited_listings( $request->get_param( "is_allowed_unlimited_listings" ) )
                ->set_allowed_featured_listings( absint( $request->get_param( "allowed_featured_listings" ) ) )
                ->set_is_allowed_unlimited_featured_listings( $request->get_param( "is_allowed_unlimited_featured_listings" ) )
                ->set_is_trial_enabled( $request->get_param( "is_trial_enabled" ) )
                ->set_trial_interval_type( $request->get_param( "trial_interval_type" ) )
                ->set_trial_interval_count( absint( $request->get_param( "trial_interval_count" ) ) );
        }

        if ( $dto->get_fee_type() === PlanFeeType::PAID && $dto->get_price() <= 0 ) {
            $dto->set_price( 1 );
        }

        do_action( 'directorist_pricing_plan_admin_plan_update_before', $request, $dto );

        $this->repository->update_plan( $dto );
        $this->plan_feature_repository->update_features( $request->get_param( "features" ), $request->get_param( "id" ) );

        if ( null !== $request->get_param( "app_configurations" ) ) {
            $this->plan_app_configuration_repository->update_configurations(
                (array) $request->get_param( "app_configurations" ),
                (int) $request->get_param( "id" )
            );
        }

        do_action( 'directorist_pricing_plan_admin_plan_update_after', (int) $request->get_param( "id" ), $request, $dto );

        return Response::send(
            [
                "message" => esc_html__( "Plan was updated successfully", 'directorist-pricing-plans' )
            ]
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Validator $validator Instance of the Validator.
     * @param WP_REST_Request $request The REST request instance.
     * @return array
     */
    public function delete( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "id" => "required|numeric"
            ]
        );

        $this->repository->delete_by_id( $request->get_param( "id" ) );

        return Response::send(
            [
                "message" => esc_html__( "Plan was deleted successfully", 'directorist-pricing-plans' )
            ]
        );
    }

    public function assign_directory_type( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'id'                => 'required|numeric',
                'directory_type_id' => 'required|numeric',
            ]
        );

        $plan = $this->repository->get_by_id( (int) $request->get_param( 'id' ) );

        if ( ! $plan ) {
            throw new Exception( esc_html__( 'Plan not found', 'directorist-pricing-plans' ), 404 );
        }

        $current_directory_type = get_term_by( 'id', (int) $plan->directory_type_id, ATBDP_DIRECTORY_TYPE );

        if ( $current_directory_type ) {
            throw new Exception( esc_html__( 'This plan already has a valid directory type.', 'directorist-pricing-plans' ), 400 );
        }

        $directory_type = $this->get_directory_type( (int) $request->get_param( 'directory_type_id' ) );

        $updated = $this->repository->get_query_builder()
            ->where( 'id', (int) $plan->id )
            ->update(
                [
                    'directory_type_id' => (int) $directory_type->term_id,
                    'updated_at'        => current_time( 'mysql' ),
                ]
            );

        if ( false === $updated ) {
            throw new Exception( esc_html__( 'Failed to assign directory type.', 'directorist-pricing-plans' ), 400 );
        }

        return Response::send(
            [
                'message' => esc_html__( 'Directory type was assigned successfully.', 'directorist-pricing-plans' ),
                'data'    => [
                    'id'                => (int) $plan->id,
                    'directory_type_id' => (int) $directory_type->term_id,
                ],
            ]
        );
    }

    public function get_fallback_plans( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "directory_type_id" => "required|numeric"
            ]
        );

        $directory_type_id = $request->get_param( "directory_type_id" );
        $plans             = $this->repository->get_query_builder()->select( 'plan.id as value', 'plan.title as label' )->where( "plan.fee_type", "free" )->where( "plan.directory_type_id", $directory_type_id )->get();

        return Response::send( (array) $plans );
    }

    protected function get_directory_type( $id ) {
        $directory_type_term = get_term_by( "id", $id, ATBDP_DIRECTORY_TYPE );

        if ( ! $directory_type_term ) {
            throw new Exception( esc_html__( "Directory type not found", 'directorist-pricing-plans' ) );
        }

        return $directory_type_term;
    }
}
