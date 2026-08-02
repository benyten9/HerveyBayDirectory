<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Enqueue\Enqueue;

if ( 'at_biz_dir_page_directorist-orders' === $hook_suffix ) {
    Enqueue::script( 'directorist-pricing-plans-app-script', 'build/js/app' );
    Enqueue::style( 'directorist-pricing-plans-app-style', 'build/css/app' );

    wp_localize_script(
        'directorist-pricing-plans-app-script',
        'directoristPricingPlans',
        [
            'planAppConfigurations' => directorist_pricing_plans_config( 'plan-app-configurations' ),
        ]
    );
}

Enqueue::register_script( 'directorist-pricing-plans-listing-table-notice', 'build/js/admin/listing-table-notice' );

wp_enqueue_style( 'wp-components' );
