<?php

namespace DirectoristStripe\App\Providers;

defined( "ABSPATH" ) || exit;

use Exception;
use DirectoristStripe\App\Stripe;
use DirectoristStripe\App\Http\Controllers\CheckoutController;
use DirectoristStripe\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\DTO\UserPackage\DTO as UserPackageDTO;

use DirectoristStripe\Stripe\Subscription;
use DirectoristStripe\Stripe\Stripe as StripeSDK;

class CheckoutServiceProvider implements Provider {
    public function boot() {
        add_shortcode(
            'stripe_checkout_button', function () {
                return '<button id="stripe-pay-btn">Pay with Stripe</button>';
            } 
        );

        if ( directorist_stripe_is_pricing_plan_active() ) {
            add_filter( 'directorist_' . Stripe::get_key() . '_renewal_payment', [ $this, 'sync_renewal_payment' ], 10, 2 );
            add_filter( 'directorist_pricing_plan_subscription_canceled_at_period_end', [ $this, 'handle_subscription_canceled_at_period_end' ], 10, 3 );
            add_filter( 'directorist_pricing_plan_subscription_canceled', [ $this, 'handle_subscription_canceled' ], 10, 3 );
        }
    }

    public function sync_renewal_payment( $renewal_order, string $subscription_id ) {
        if ( $renewal_order || '' === $subscription_id ) {
            return $renewal_order;
        }

        StripeSDK::setApiKey( directorist_stripe_get_secret_key() );

        try {
            $subscription = Subscription::retrieve( $subscription_id );
            $invoice_id   = $this->normalize_stripe_id( $subscription->latest_invoice ?? null );

            if ( '' === $invoice_id ) {
                return $renewal_order;
            }

            // Reuse the webhook's idempotent invoice handler. It creates the paid
            // renewal order and advances the package before expiration continues.
            ( new CheckoutController() )->handle_invoice_paid( $invoice_id );

            $payment = directorist_payment_repository()
                ->get_query_builder()
                ->where( 'transaction_id', $invoice_id )
                ->first();
            $package = directorist_user_package_repository()->get_by_subscription_id( $subscription_id );

            if ( $payment && $package && (int) $payment->order_id !== (int) $package->last_order_id ) {
                return directorist_order_repository()->get_by_id( (int) $payment->order_id );
            }
        } catch ( Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to sync renewal for subscription %s - %s',
                    $subscription_id,
                    $e->getMessage()
                )
            );
        }

        return $renewal_order;
    }

    public function handle_subscription_canceled_at_period_end( bool $is_cancelled, UserPackageDTO $subscription, string $triggered_by = 'system' ) {
        if ( 
            ! $subscription->is_initialized( 'subscription_id' ) ||
            ! $subscription->is_initialized( 'subscription_method' ) ||
            $subscription->get_subscription_method() !== Stripe::get_key()
        ) {
            return $is_cancelled;
        }

        StripeSDK::setApiKey( directorist_stripe_get_secret_key() );

        try {
            Subscription::update(
                $subscription->get_subscription_id(), [
                    'cancel_at_period_end' => true,
                ] 
            );
            return true;
        } catch ( Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to set subscription %s to cancel at period end - %s',
                    $subscription->get_subscription_id(),
                    $e->getMessage()
                )
            );
            return false;
        }
    }

    public function handle_subscription_canceled( bool $is_cancelled, UserPackageDTO $subscription, string $triggered_by = 'system' ) {    
        if ( 
            ! $subscription->is_initialized( 'subscription_id' ) ||
            ! $subscription->is_initialized( 'subscription_method' ) ||
            $subscription->get_subscription_method() !== Stripe::get_key()
        ) {
            return $is_cancelled;
        }

        StripeSDK::setApiKey( directorist_stripe_get_secret_key() );

        $stripe_subscription = Subscription::retrieve( $subscription->get_subscription_id() );

        try {
            $stripe_subscription->cancel();
            return true;
        } catch ( Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to cancel subscription %s - %s',
                    $subscription->get_subscription_id(),
                    $e->getMessage()
                )
            );
            return false;
        }
    }

    private function normalize_stripe_id( $value ): string {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_object( $value ) && ! empty( $value->id ) ) {
            return (string) $value->id;
        }

        return '';
    }
}
