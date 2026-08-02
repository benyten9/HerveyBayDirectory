<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class SettingsServiceProvider implements Provider {

    public function boot() {
        add_filter( 'directorist_is_featured_listing_enabled', [ $this, 'set_featured_listing_status' ], 20, 2 );
        add_filter( 'directorist_is_monetization_enabled', '__return_true' );
    }

    public function set_featured_listing_status( bool $status, array $context = [] ) {
        if ( empty( $context['type'] ) ) {
            return true;
        }

        if ( 'featured_listing_checkout' === $context['type'] ) {
            return false;
        }

        return true;
    }
}