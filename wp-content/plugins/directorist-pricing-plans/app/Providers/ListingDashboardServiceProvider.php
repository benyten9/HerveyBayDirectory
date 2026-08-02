<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\WpMVC\View\View;

class ListingDashboardServiceProvider implements Provider {
    public function boot() {
        add_filter( 'directorist_show_user_order_history_tab', '__return_true', 10, 1 );
        add_filter( 'directorist_can_renew_listing', '__return_false', 10, 1 );
        add_filter( 'directorist_can_promote_listing', '__return_false', 10, 1 );
        add_filter( 'directorist_can_unfeature_listing', '__return_false', 10, 1 );
        add_filter( 'directorist_dashboard_listing_action_items_end', [ $this, 'add_listing_action_items' ], 10, 2 );
        add_action( 'directorist_dashboard_tabs', [ $this, 'directorist_dashboard_tabs' ] );
    }

    public function add_listing_action_items( array $items, int $post_id ) {
        $new_items = [];

        // Update Listing Status
        $is_listing_published = false || 'publish' === get_post_status( $post_id );
        $listing_status_task  = $is_listing_published ? 'private' : 'publish';

        if ( $is_listing_published || directorist_get_listing_package( $post_id ) ) {
            $new_items['update_listing_status'] = [
                'class'     => 'directorist-update_listing_status',
                'data_attr' => "data-task='$listing_status_task' data-post-id=$post_id",
                'link'      => '#',
                'icon'      =>  directorist_icon( $is_listing_published ? 'lar la-eye-slash' : 'lar la-eye', false ),
                'label'     => $is_listing_published ? __( 'Private the listing', 'directorist-pricing-plans' ) : __( 'Publish the listing', 'directorist-pricing-plans' ),
            ];
        }

        // Mark as Featured/Regular
        $is_featured  = '1' === strval( get_post_meta( $post_id, '_featured', true ) );
        $mark_as_task = $is_featured ? 'mark-as-regular' : 'mark-as-featured';

        $new_items['mark_as'] = [
            'class'     => 'directorist-mark-listing-as',
            'data_attr' => "data-task='$mark_as_task' data-post-id=$post_id",
            'link'      => '#',
            'icon'      =>  directorist_icon( 'lar la-star', false ),
            'label'     => $is_featured ? __( 'Mark as Regular', 'directorist-pricing-plans' ) : __( 'Mark as Featured', 'directorist-pricing-plans' ),
        ];

        return array_merge( $new_items, $items );
    }

    public function directorist_dashboard_tabs( $tabs ): array {
        $new_tabs = [
            'packages' => [
                'title'   => __( 'Packages', 'directorist-pricing-plans' ),
                'content' => $this->packages_tab_content(),
                'icon'    => 'las la-box',
            ],
        ];

        if ( empty( $tabs ) ) {
            return $new_tabs;
        }

        $length     = 1;
        $first_part = array_slice( $tabs, 0, $length, true );
        $last_part  = array_slice( $tabs, $length, null, true );

        return $first_part + $new_tabs + $last_part;
    }

    protected function packages_tab_content() {
        wp_enqueue_style( 'directorist-pricing-plans-frontend' );
        wp_enqueue_script( 'directorist-notification' );
        return View::get( 'listing-owner-subscriptions' );
    }
}