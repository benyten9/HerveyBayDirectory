<?php
namespace DirectoristCoupon\MCP;
use Directorist\MCP\Support\Validation;
use Directorist\MCP\Safety\Read_Guard;
use Directorist\MCP\Tool_Providers\Orders\Money;
use Directorist\DTO\Order\DTO;
use DirectoristPricingPlan\App\Providers\CheckoutServiceProvider;
defined( 'ABSPATH' ) || exit;

/** Explicit adapter for the inspected native one-time plan checkout. */
final class Checkout {
    public static function ready(): bool {
        return \SWBDPCCouponService::plans_active() && did_action( 'after_load_directorist_pricing_plans' ) > 0 && class_exists( CheckoutServiceProvider::class ) && function_exists( 'directorist_get_pricing_plan_by_id' );
    }
    public static function quote( array $context ): array {
        Validation::check( self::ready(), 'The native one-time plan quote adapter is unavailable.', 'dependency_unavailable' );
        Validation::check( directorist_is_monetization_enabled(), 'Monetization is unavailable.', 'dependency_unavailable' );
        $original_user = get_current_user_id();
        $buyer = $context['customer_id'] ?? $original_user;
        Validation::check( $buyer > 0 && ( $buyer === $original_user || ( current_user_can( 'manage_options' ) && current_user_can( 'edit_user', $buyer ) ) ), 'Checkout customer is unavailable.', 'forbidden' );
        Validation::check( (bool) get_userdata( $buyer ), 'Checkout customer is unavailable.', 'not_found' );
        unset( $context['customer_id'] );
        try {
            if ( $buyer !== $original_user ) { wp_set_current_user( $buyer ); }
            return Read_Guard::run( static function () use ( $context ): array {
                $plan = Validation::unwrap( \SWBDPCCouponService::plan( $context['plan_id'] ) );
                Validation::check( ! directorist_plan_has_subscription( $plan ) && empty( $plan->is_trial_enabled ), 'Recurring and trial coupons are outside this adapter.', 'unsupported_operation' );
                if ( ! empty( $context['listing_id'] ) ) {
                    $listing = get_post( $context['listing_id'] );
                    Validation::check( $listing && ATBDP_POST_TYPE === $listing->post_type && (int) $listing->post_author === get_current_user_id() && ! in_array( $listing->post_status, [ 'trash', 'auto-draft' ], true ), 'Checkout context is unavailable.', 'forbidden' );
                }
                $request = new \WP_REST_Request( 'POST' );
                $request->set_param( 'checkout_type', 'plan' );
                foreach ( $context as $key => $value ) { $request->set_param( $key, $value ); }
                // A fresh provider prevents request-local proration cache from crossing quotes.
                $native = new CheckoutServiceProvider();
                $native->validate_checkout( 'plan', $request );
                $subtotal = round( $native->handle_checkout_subtotal( 0.0, 'plan', $request ), 2 );
                Validation::check( is_finite( $subtotal ) && $subtotal > 0, 'Checkout has no positive native subtotal.', 'dependency_blocked' );
                $filtered = self::filtered_subtotal( $native, $request );
                Validation::check( $filtered === $subtotal, 'An additional pricing adapter is required for this checkout.', 'unsupported_operation' );
                $currency = atbdp_get_payment_currency();
                Money::native( Money::stored( $subtotal, $currency ), $currency );
                $dto = ( new DTO() )->set_user_id( get_current_user_id() )->set_currency( $currency )->set_status( 'pending' )->set_sub_total( $subtotal )->set_amount( $subtotal );
                if ( ! empty( $plan->is_taxable ) ) { $dto->set_tax_type( $plan->tax_type )->set_tax_rate( $plan->tax_rate ); }
                return [ 'subtotal' => $subtotal, 'currency' => $currency, 'plan_id' => (int) $plan->id, 'buyer_id' => get_current_user_id(), 'dto' => $dto ];
            } );
        } finally {
            if ( $buyer !== $original_user ) { wp_set_current_user( $original_user ); }
        }
    }
    /** Keep native hooks, while isolating the native provider's per-request proration cache. */
    private static function filtered_subtotal( CheckoutServiceProvider $native, \WP_REST_Request $request ): float {
        global $wp_filter;
        $hook = $wp_filter['directorist_checkout_subtotal'] ?? null;
        $replaced = [];
        if ( $hook ) {
            foreach ( $hook->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $key => $callback ) {
                    $fn = $callback['function'];
                    if ( is_array( $fn ) && $fn[0] instanceof CheckoutServiceProvider && 'handle_checkout_subtotal' === $fn[1] ) {
                        $replaced[] = [ $priority, $key, $fn ];
                        $hook->callbacks[$priority][$key]['function'] = [ $native, 'handle_checkout_subtotal' ];
                    }
                }
            }
        }
        try {
            return round( (float) apply_filters( 'directorist_checkout_subtotal', 0.0, 'plan', $request ), 2 );
        } finally {
            foreach ( $replaced as [ $priority, $key, $fn ] ) { $hook->callbacks[$priority][$key]['function'] = $fn; }
        }
    }
    public static function validate( string $code, array $context ): array {
        RateLimit::consume();
        $quote = self::quote( $context );
        $native = \SWBDPCHelperFunctions::validate_coupon( $code, $quote['subtotal'], $quote['plan_id'], $quote['buyer_id'] );
        $eligible = ! is_wp_error( $native );
        $dto = $quote['dto'];
        $discount = 0.0;
        if ( $eligible ) {
            $discount = (float) $native['discount_amount'];
            $dto->set_coupon_code( $native['coupon_code'] )->set_coupon_discount( (float) $native['discount_rate'] )->set_coupon_discount_type( $native['discount_type'] );
        }
        $payable = round( directorist_order_payable( $dto ), 2 );
        $tax = round( $payable - max( 0, $quote['subtotal'] - $discount ), 2 );
        $currency = $quote['currency'];
        return [
            'eligible' => $eligible, 'reason' => $eligible ? 'eligible' : 'invalid_or_unavailable_code',
            'subtotal' => Money::stored( $quote['subtotal'], $currency ), 'discount' => Money::stored( $discount, $currency ),
            'tax' => Money::stored( max( 0, $tax ), $currency ), 'payable' => Money::stored( $payable, $currency ), 'currency' => $currency,
            // Informational only: limits, publication and dates can change immediately.
            'valid_until' => gmdate( 'c', min( time() + 60, ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->getTimestamp() ) ),
            'requires_revalidation' => true, 'reserves_usage' => false, 'rate_limit' => RateLimit::policy(),
        ];
    }
}
