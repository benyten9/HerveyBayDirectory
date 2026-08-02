<?php

namespace DirectoristPricingPlan\App\Http\Controllers;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Repositories\PlanRepository;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\WpMVC\Routing\Response;
use DirectoristPricingPlan\WpMVC\RequestValidator\Validator;
use WP_REST_Request;

class PlanController extends Controller {
    public PlanRepository $repository;

    public function __construct( PlanRepository $repository ) {
        $this->repository = $repository;
    }

    public function get_by_directory_type( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                "directory_type_id" => "required|numeric",
                "mode"              => "string|accepted:all,plan-switch",
            ]
        );

        $mode = $request->get_param( "mode" ) ?? 'all';

        return Response::send( $this->repository->get_by_directory_type( (int) $request->get_param( "directory_type_id" ), $mode ) );
    }
}