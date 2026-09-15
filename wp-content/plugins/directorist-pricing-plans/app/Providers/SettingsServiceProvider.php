<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class SettingsServiceProvider implements Provider {

    public function boot() {
        add_filter( 'directorist_is_force_disabled_featured_listings', '__return_true', 20, 1 );
        add_filter( 'directorist_is_featured_listing_enabled', '__return_true', 20, 1 );
        add_filter( 'directorist_is_monetization_enabled', '__return_true', 20, 1 );
    }
}