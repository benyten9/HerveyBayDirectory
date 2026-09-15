<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Enqueue\Enqueue;

Enqueue::register_style( 'directorist-pricing-plans-frontend', 'build/css/frontend' );
Enqueue::script( 'directorist-pricing-plans-listing-owner-dashboard', 'build/js/frontend/listing-owner-dashboard' );

$allow_multiple_plans = directorist_allow_multiple_plans_per_directory_type();

wp_localize_script(
    'directorist-pricing-plans-listing-owner-dashboard',
    'directorist_pricing_plans_dashboard',
    [
        'allow_multiple_plans' => $allow_multiple_plans,
        'can_switch_plans'     => ! $allow_multiple_plans,
    ]
);

wp_set_script_translations(
    'directorist-pricing-plans-listing-owner-dashboard',
    'directorist-pricing-plans',
    dirname( __DIR__ ) . '/languages'
);

Enqueue::register_script( 'directorist-pricing-plans-plans', 'build/js/frontend/plans', ['jquery'] );
