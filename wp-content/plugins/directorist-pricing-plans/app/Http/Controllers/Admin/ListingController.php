<?php

namespace DirectoristPricingPlan\App\Http\Controllers\Admin;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\App\Services\ListingOrderService;
use DirectoristPricingPlan\WpMVC\RequestValidator\Validator;
use DirectoristPricingPlan\WpMVC\Routing\Response;

class ListingController extends Controller {
    private ListingOrderService $listing_order_service;

    public function __construct( ListingOrderService $listing_order_service ) {
        $this->listing_order_service = $listing_order_service;
    }

    public function renew( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'id' => 'required|numeric',
            ]
        );

        $order_id = $this->listing_order_service->renew_listing( (int) $request->get_param( 'id' ) );

        return Response::send(
            [
                'message' => esc_html__( 'Listing renewed successfully.', 'directorist-pricing-plans' ),
                'data'    => [
                    'order_id' => $order_id,
                ],
            ]
        );
    }

    public function assign_order( Validator $validator, WP_REST_Request $request ): array {
        $validator->validate(
            [
                'id' => 'required|numeric',
            ]
        );

        $order_id = $this->listing_order_service->assign_order( (int) $request->get_param( 'id' ) );

        return Response::send(
            [
                'message' => esc_html__( 'Order assigned successfully.', 'directorist-pricing-plans' ),
                'data'    => [
                    'order_id' => $order_id,
                ],
            ]
        );
    }
}
