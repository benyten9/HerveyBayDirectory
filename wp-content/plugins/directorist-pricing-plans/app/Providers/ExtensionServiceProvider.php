<?php

namespace DirectoristPricingPlan\App\Providers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Http\Controllers\Legacy\PlanController;

class ExtensionServiceProvider implements Provider {
    private PlanController $legacy_plans_controller;

    public function __construct( PlanController $controller ) {
        $this->legacy_plans_controller = $controller;
    }

    public function boot() {
        add_filter( 'directorist_is_active_pricing_plans', '__return_true' );
        add_filter( 'directorist_rest_pricing_plans_permissions_check', [ $this, 'get_items_permissions_check' ], 10, 2 );
        add_filter( 'directorist_rest_pricing_plan_permissions_check', [ $this, 'get_item_permissions_check' ], 10, 2 );
        add_filter( 'directorist_rest_pricing_plans_data', [ $this, 'get_items' ], 10, 2 );
        add_filter( 'directorist_rest_pricing_plan_data', [ $this, 'get_item' ], 10, 3 );
    }

    public function get_items_permissions_check( $permission, WP_REST_Request $request ) {
        return $this->legacy_plans_controller->get_items_permissions_check( $request );
    }

    public function get_item_permissions_check( $permission, WP_REST_Request $request ) {
        return $this->legacy_plans_controller->get_item_permissions_check( $request );
    }

    public function get_items( $data, WP_REST_Request $request ) {
        return $this->legacy_plans_controller->get_items( $request );
    }

    public function get_item( $data, WP_REST_Request $request, $id ) {
        return $this->legacy_plans_controller->get_item( $request );
    }
}
