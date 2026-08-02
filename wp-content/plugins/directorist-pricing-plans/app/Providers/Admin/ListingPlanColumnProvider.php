<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class ListingPlanColumnProvider implements Provider {
    private const COLUMN_KEY = 'directorist_pricing_plan';

    public function boot() {
        add_filter( 'atbdp_add_new_listing_column', [ $this, 'add_plan_column' ], 10, 1 );
        add_action( 'manage_' . ATBDP_POST_TYPE . '_posts_custom_column', [ $this, 'render_plan_column' ], 10, 2 );
    }

    public function add_plan_column( array $columns ): array {
        $updated_columns = [];

        foreach ( $columns as $key => $label ) {
            $updated_columns[ $key ] = $label;

            if ( 'atbdp_date' === $key ) {
                $updated_columns[ self::COLUMN_KEY ] = __( 'Plan', 'directorist-pricing-plans' );
            }
        }

        return $updated_columns;
    }

    public function render_plan_column( string $column_name, int $post_id ): void {
        if ( self::COLUMN_KEY !== $column_name ) {
            return;
        }

        $plan_title = $this->get_listing_plan_title( $post_id );

        if ( null === $plan_title ) {
            printf(
                '<span style="color:#b32d2e;">%s</span>',
                esc_html__( 'No plan assigned', 'directorist-pricing-plans' )
            );
            return;
        }

        printf(
            '<span style="color:green;">%s</span>',
            esc_html( $plan_title )
        );
    }

    private function get_listing_plan_title( int $listing_id ): ?string {
        $package = directorist_get_listing_package( $listing_id );

        if ( ! $package ) {
            return null;
        }

        $plan = directorist_get_pricing_plan_by_id( $package->plan_id );

        return $plan ? $plan->title : null;
    }
}
