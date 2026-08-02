<?php

namespace DirectoristPricingPlan\App\Providers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Server;
use DirectoristPricingPlan\App\Http\Controllers\Legacy\PlanController;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class LegacyPlanApiProvider implements Provider {
    private PlanController $controller;

    public function __construct( PlanController $controller ) {
        $this->controller = $controller;
    }

    public function boot() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ], 100 );
    }

    public function register_routes(): void {
        register_rest_route(
            'directorist/v1',
            '/plans',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this->controller, 'get_items' ],
                    'permission_callback' => [ $this->controller, 'get_items_permissions_check' ],
                    'args'                => $this->controller->get_collection_params(),
                ],
                'schema' => [ $this->controller, 'get_public_item_schema' ],
            ],
            true
        );

        register_rest_route(
            'directorist/v1',
            '/plans/(?P<id>[\d]+)',
            [
                'args'   => [
                    'id' => [
                        'description' => __( 'Plan id.', 'directorist-pricing-plans' ),
                        'type'        => 'integer',
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this->controller, 'get_item' ],
                    'permission_callback' => [ $this->controller, 'get_item_permissions_check' ],
                    'args'                => [
                        'context' => [
                            'default' => 'view',
                            'type'    => 'string',
                        ],
                    ],
                ],
                'schema' => [ $this->controller, 'get_public_item_schema' ],
            ],
            true
        );
    }
}
