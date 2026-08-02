<?php

namespace DirectoristPricingPlan\App\Providers;

defined( "ABSPATH" ) || exit;

use stdClass;
use WP_Term;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;

class DirectCheckout implements Provider {
    public function boot() {
        add_filter( 'directorist_pricing_plans_continue_url', [ $this, 'get_plans_continue_url' ], 10, 4 );
        add_filter( 'atbdp_payment_receipt_button_link', [ $this, 'get_receipt_button_link' ], 10, 2 );
        add_filter( 'atbdp_payment_receipt_button_text', [ $this, 'get_receipt_button_text' ], 10, 2 );
    }

    public function get_receipt_button_link( string $url, int $order_id ): string {
        if ( ! directorist_direct_purchase() ) {
            return $url;
        }

        $plan = $this->get_plan_from_order( $order_id );

        if ( ! $plan ) {
            return $url;
        }

        $directory_term = get_term( $plan->directory_type_id, ATBDP_DIRECTORY_TYPE );

        if ( ! $directory_term instanceof WP_Term ) {
            return $url;
        }

        $add_listing_url = directorist_permalink()::get_add_listing_page_link();

        return add_query_arg( [
            'directory_type' => $directory_term->slug,
            'plan'           => $plan->id,
        ], $add_listing_url );
    }

    public function get_receipt_button_text( string $text, int $order_id ): string {
        if ( ! directorist_direct_purchase() ) {
            return $text;
        }

        return __( 'Submit Listing', 'directorist-pricing-plans' );
    }

    private function get_plan_from_order( int $order_id ): ?stdClass {
        $order = directorist_order_repository()->get_by_id( $order_id );

        if ( ! $order || empty( $order->ref ) || empty( $order->ref_type ) ) {
            return null;
        }

        if ( $order->ref_type !== OrderRefType::PRICING_PLAN ) {
            return null;
        }

        return directorist_get_pricing_plan_by_id( (int) $order->ref );
    }

    public function get_plans_continue_url( string $url, array $query_args, stdClass $plan, WP_Term $directory_term ): string {
        if ( ! directorist_direct_purchase() ) {
            return $url;
        }

        if ( directorist_is_guest_submission_enabled() && ! is_user_logged_in() ) {
            return $url;
        }

        $active_packages = directorist_user_package_repository()->get_active_packages_for_directory(
            get_current_user_id(),
            $directory_term->term_id
        );

        // If the selected plan is already among the user's active packages, no checkout needed.
        foreach ( $active_packages as $pkg ) {
            if ( (int) $pkg->plan_id === (int) $plan->id && directorist_is_package_active( $pkg ) ) {
                return $url;
            }
        }

        // Users with multiple active packages cannot add a new one — keep the original URL
        // so the listing form handles the block with a proper notice.
        if ( count( $active_packages ) > 1 ) {
            return $url;
        }

        return directorist_get_checkout_page_url( 'plan', $query_args );
    }
}