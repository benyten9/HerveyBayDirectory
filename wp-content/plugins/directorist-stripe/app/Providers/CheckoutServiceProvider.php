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
        if ( ! $subscription->is_initialized( 'subscription_method' ) || $subscription->get_subscription_method() !== Stripe::get_key() ) {
            return $is_cancelled;
        }

        $subscription_id = $subscription->is_initialized( 'subscription_id' ) ? trim( (string) $subscription->get_subscription_id() ) : '';

        if ( '' === $subscription_id ) {
            $this->log_cancellation_error( 'set subscription to cancel at period end', $subscription, $subscription_id, 'No Stripe subscription ID is stored for this package.' );
            return false;
        }

        try {
            $stripe_subscription = $this->retrieve_subscription( $subscription_id );

            if ( $this->is_terminal_subscription( $stripe_subscription ) ) {
                return true;
            }

            Subscription::update(
                $subscription_id, [
                    'cancel_at_period_end' => true,
                ] 
            );
            return true;
        } catch ( \Throwable $e ) {
            $this->log_cancellation_error( 'set subscription to cancel at period end', $subscription, $subscription_id, get_class( $e ) . ': ' . $e->getMessage() );
            return false;
        }
    }

    public function handle_subscription_canceled( bool $is_cancelled, UserPackageDTO $subscription, string $triggered_by = 'system' ) {    
        if ( ! $subscription->is_initialized( 'subscription_method' ) || $subscription->get_subscription_method() !== Stripe::get_key() ) {
            return $is_cancelled;
        }

        $subscription_id = $subscription->is_initialized( 'subscription_id' ) ? trim( (string) $subscription->get_subscription_id() ) : '';

        if ( '' === $subscription_id ) {
            $this->log_cancellation_error( 'cancel subscription', $subscription, $subscription_id, 'No Stripe subscription ID is stored for this package.' );
            return false;
        }

        try {
            $stripe_subscription = $this->retrieve_subscription( $subscription_id );

            if ( $this->is_terminal_subscription( $stripe_subscription ) ) {
                return true;
            }

            $stripe_subscription->cancel();
            return true;
        } catch ( \Throwable $e ) {
            $this->log_cancellation_error( 'cancel subscription', $subscription, $subscription_id, get_class( $e ) . ': ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Retrieve a subscription from the active Stripe mode, then try the other
     * configured mode for packages created before their Stripe mode was stored.
     *
     * @param string $subscription_id Stripe subscription ID.
     * @return Subscription
     * @throws \Throwable When the subscription cannot be retrieved.
     */
    private function retrieve_subscription( string $subscription_id ) {
        $last_exception = null;

        foreach ( $this->get_secret_keys() as $secret_key ) {
            try {
                StripeSDK::setApiKey( $secret_key );
                return Subscription::retrieve( $subscription_id );
            } catch ( \Throwable $e ) {
                $last_exception = $e;

                if ( ! $this->is_missing_subscription_exception( $e ) ) {
                    throw $e;
                }
            }
        }

        if ( $last_exception ) {
            throw $last_exception;
        }

        throw new \RuntimeException( 'No Stripe secret key is configured.' );
    }

    /**
     * Get the active key first and the other configured mode second.
     *
     * @return string[]
     */
    private function get_secret_keys(): array {
        $secret_keys = [ directorist_stripe_get_secret_key() ];
        $other_key   = get_directorist_option( directorist_stripe_is_test_mode() ? 'stripe_live_sk' : 'stripe_test_sk' );

        if ( is_string( $other_key ) && '' !== trim( $other_key ) ) {
            $secret_keys[] = trim( $other_key );
        }

        return array_values( array_unique( array_filter( $secret_keys ) ) );
    }

    /**
     * Stripe terminal subscriptions need no remote cancellation.
     *
     * @param Subscription $subscription Stripe subscription.
     */
    private function is_terminal_subscription( $subscription ): bool {
        return in_array( $subscription->status ?? '', [ 'canceled', 'incomplete_expired' ], true );
    }

    private function is_missing_subscription_exception( \Throwable $exception ): bool {
        $stripe_code = method_exists( $exception, 'getStripeCode' ) ? (string) $exception->getStripeCode() : '';
        $http_status = method_exists( $exception, 'getHttpStatus' ) ? (int) $exception->getHttpStatus() : 0;

        return 'resource_missing' === $stripe_code
            || 404 === $http_status
            || false !== stripos( $exception->getMessage(), 'No such subscription' );
    }

    private function log_cancellation_error( string $action, UserPackageDTO $subscription, string $subscription_id, string $message ): void {
        error_log(
            sprintf(
                'Directorist Stripe: Failed to %s (subscription %s, package %d, %s mode) - %s',
                $action,
                $subscription_id ?: '(missing)',
                $subscription->get_id(),
                directorist_stripe_is_test_mode() ? 'test' : 'live',
                $message
            )
        );
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
