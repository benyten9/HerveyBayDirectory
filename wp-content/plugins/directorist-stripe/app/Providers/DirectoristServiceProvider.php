<?php

namespace DirectoristStripe\App\Providers;

defined( "ABSPATH" ) || exit;

use DirectoristStripe\WpMVC\Contracts\Provider;
use DirectoristStripe\App\Stripe;

class DirectoristServiceProvider implements Provider {
    public function boot() {
        add_filter( 'directorist_active_gateways', [$this, 'default_active_gateways'] );
        add_filter( 'atbdp_default_gateways', [$this, 'default_active_gateways'] );
        add_filter( 'directorist_payment_processors', [$this, 'register_processor'] );
    }

    /**
     * It adds our gateways to the active and default gateways list
     * @param array $gateways Arrays of all old gateways
     * @return array It returns the new gateways list after adding stripe gateways
     * @since 1.0.0
     */
    public function default_active_gateways( $gateways ) {
        $gateways[] = [
            'value' => Stripe::get_key(),
            'label' => __( 'Stripe', 'directorist-stripe' ),
        ];
        return $gateways;
    }

    public function register_processor( $processors ) {
        $processors[Stripe::get_key()] = Stripe::class;
        return $processors;
    }
}