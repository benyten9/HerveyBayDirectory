<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use WP_Post;

class ListingPlanMetaboxProvider implements Provider {
    public function boot() {
        add_action( 'add_meta_boxes_' . ATBDP_POST_TYPE, [ $this, 'add_metabox' ] );
    }

    public function add_metabox(): void {
        add_meta_box(
            'directorist-pricing-plan-metabox',
            __( 'Plan', 'directorist-pricing-plans' ),
            [ $this, 'render_metabox' ],
            ATBDP_POST_TYPE,
            'side',
            'high'
        );
    }

    public function render_metabox( WP_Post $post ): void {
        $package = directorist_get_listing_package( (int) $post->ID );

        if ( ! $package ) {
            echo '<p>' . esc_html__( 'No active package.', 'directorist-pricing-plans' ) . '</p>';
            return;
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

        if ( ! $plan ) {
            echo '<p>' . esc_html__( 'No active package.', 'directorist-pricing-plans' ) . '</p>';
            return;
        }

        printf(
            '<p><strong>%s</strong></p>',
            esc_html( $plan->title )
        );

        printf(
            '<p class="description"><strong>Type:</strong> %s</p>',
            esc_html( PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ? __( 'Pay Per Listing', 'directorist-pricing-plans' ) : __( 'Package', 'directorist-pricing-plans' ) )
        );
    }
}
