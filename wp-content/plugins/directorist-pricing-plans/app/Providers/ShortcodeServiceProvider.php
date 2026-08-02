<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Utils\View;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class ShortcodeServiceProvider implements Provider {
    public function boot() {
        add_shortcode( 'directorist_pricing_plans', [ $this, 'render' ] );
    }

    public function render( array $atts = [], bool $echo = false ) {
        wp_enqueue_style( 'directorist-pricing-plans-frontend' );
        wp_enqueue_script( 'directorist-pricing-plans-plans' );

        $atts = \is_array( $atts ) ? $atts : [];

        if ( $echo ) {
            View::render( 'shortcode', [ 'atts' => $atts ] );
            return;
        }

        $output = View::get( 'shortcode', [ 'atts' => $atts ] );

        return apply_filters( 'directorist_pricing_plan_shortcode_output', $output, $atts );
    }
}