<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class ListingPlanColumnProvider implements Provider {
    private const COLUMN_KEY = 'directorist_pricing_plan';

    /**
     * Directorist's original listing column renderer.
     *
     * @var object|null
     */
    private $directorist_column_renderer;

    public function boot() {
        $column_hook = 'manage_' . ATBDP_POST_TYPE . '_posts_custom_column';

        add_filter( 'atbdp_add_new_listing_column', [ $this, 'add_plan_column' ], 10, 1 );
        $this->replace_directorist_column_renderer( $column_hook );
        add_action( $column_hook, [ $this, 'render_plan_column' ], 10, 2 );
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

        $plan = $this->get_listing_plan_display( $post_id );

        if ( null === $plan ) {
            printf(
                '<span style="color:#b32d2e;">%s</span>',
                esc_html__( 'No plan assigned', 'directorist-pricing-plans' )
            );
            return;
        }

        printf(
            '<span style="color:%s;">%s</span>',
            esc_attr( $plan['has_issue'] ? '#b32d2e' : 'green' ),
            esc_html( $plan['title'] )
        );
    }

    public function render_directorist_column( string $column_name, int $post_id ): void {
        if ( 'atbdp_status' === $column_name ) {
            $status        = get_post_status( $post_id );
            $status_object = $status ? get_post_status_object( $status ) : null;
            $status_label  = $status_object ? $status_object->label : ucfirst( (string) $status );

            echo esc_html( $status_label );
            return;
        }

        if ( $this->directorist_column_renderer ) {
            $this->directorist_column_renderer->manage_listing_columns( $column_name, $post_id );
        }
    }

    private function replace_directorist_column_renderer( string $column_hook ): void {
        if ( ! function_exists( 'ATBDP' ) ) {
            return;
        }

        $directorist = ATBDP();

        if ( empty( $directorist->custom_post ) ) {
            return;
        }

        $callback = [ $directorist->custom_post, 'manage_listing_columns' ];

        if ( ! remove_action( $column_hook, $callback, 10 ) ) {
            return;
        }

        $this->directorist_column_renderer = $directorist->custom_post;
        add_action( $column_hook, [ $this, 'render_directorist_column' ], 10, 2 );
    }

    /**
     * Get the assigned plan title and whether the assignment needs attention.
     *
     * @return array{title: string, has_issue: bool}|null
     */
    private function get_listing_plan_display( int $listing_id ): ?array {
        $assigned_plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );
        $package          = directorist_get_listing_package( $listing_id );
        $plan_id          = $assigned_plan_id ?: (int) ( $package->plan_id ?? 0 );

        if ( ! $plan_id ) {
            return null;
        }

        $plan = directorist_get_pricing_plan_by_id( $plan_id );

        if ( ! $plan ) {
            return null;
        }

        $has_active_package = $package && (int) $package->plan_id === $plan_id;

        return [
            'title'     => (string) $plan->title,
            'has_issue' => 'publish' !== get_post_status( $listing_id ) || ! $has_active_package,
        ];
    }
}
