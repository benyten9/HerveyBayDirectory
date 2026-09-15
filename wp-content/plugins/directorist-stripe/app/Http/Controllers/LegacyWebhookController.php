<?php

namespace DirectoristStripe\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

use DirectoristPricingPlan\App\DTO\UserPackage\DTO as UserPackageDTO;

use DirectoristStripe\App\Stripe;
use DirectoristStripe\Stripe\Invoice;
use DirectoristStripe\Stripe\Stripe as StripeSDK;
use DirectoristStripe\Stripe\Subscription;

class LegacyWebhookController extends Controller {
    public function webhook( WP_REST_Request $request ): WP_REST_Response {
        $type = sanitize_text_field( (string) $request->get_param( 'type' ) );
        $data = $request->get_param( 'data' );

        do_action( 'directorist_stripe_webhook_received', $type, $data );

        if ( 'customer.subscription.updated' !== $type ) {
            return $this->acknowledge();
        }

        if ( ! directorist_stripe_is_pricing_plan_active() ) {
            $this->log( 'Pricing Plans is unavailable; legacy subscription event ignored.' );
            return $this->acknowledge();
        }

        if ( ! is_array( $data ) || empty( $data['object'] ) || ! is_array( $data['object'] ) ) {
            $this->log( 'Legacy subscription event has an invalid data object.' );
            return $this->acknowledge();
        }

        $subscription_id = sanitize_text_field( (string) ( $data['object']['id'] ?? '' ) );
        $legacy_order_id = $this->get_legacy_order_id( $data );

        if ( '' === $subscription_id || 0 === $legacy_order_id ) {
            $this->log(
                'Legacy subscription event is missing required identifiers (subscription: %s, order: %d).',
                '' !== $subscription_id ? $subscription_id : 'missing',
                $legacy_order_id
            );
            return $this->acknowledge();
        }

        $migrated_order = directorist_order_repository()
            ->get_query_builder()
            ->where( 'legacy_id', $legacy_order_id )
            ->first();

        if ( ! $migrated_order ) {
            $this->log( 'No migrated order found for legacy order %d.', $legacy_order_id );
            return $this->acknowledge();
        }

        if ( 'pricing_plan' !== ( $migrated_order->ref_type ?? '' ) || empty( $migrated_order->ref ) ) {
            $this->log(
                'Migrated order %d for legacy order %d is not a pricing plan order.',
                (int) $migrated_order->id,
                $legacy_order_id
            );
            return $this->acknowledge();
        }

        $secret_key = directorist_stripe_get_secret_key();

        if ( empty( $secret_key ) ) {
            $this->log( 'Stripe secret key is unavailable for legacy subscription %s.', $subscription_id );
            return $this->acknowledge();
        }

        StripeSDK::setApiKey( $secret_key );

        try {
            $subscription = Subscription::retrieve( $subscription_id );

            if ( 'active' !== ( $subscription->status ?? '' ) ) {
                $this->log(
                    'Stripe subscription %s is %s; legacy renewal skipped.',
                    $subscription_id,
                    (string) ( $subscription->status ?? 'unknown' )
                );
                return $this->acknowledge();
            }

            $invoice_id = $this->normalize_stripe_id( $subscription->latest_invoice ?? null );

            if ( '' === $invoice_id ) {
                $this->log( 'Stripe subscription %s has no latest invoice.', $subscription_id );
                return $this->acknowledge();
            }

            $invoice                 = Invoice::retrieve( $invoice_id );
            $invoice_subscription_id = $this->normalize_stripe_id( $invoice->subscription ?? null );

            if ( $subscription_id !== $invoice_subscription_id ) {
                $this->log(
                    'Stripe invoice %s does not belong to legacy subscription %s.',
                    $invoice_id,
                    $subscription_id
                );
                return $this->acknowledge();
            }

            if ( ! $this->ensure_package_subscription_link( $migrated_order, $subscription_id, $invoice ) ) {
                return $this->acknowledge();
            }

            ( new CheckoutController() )->handle_invoice_paid( $invoice_id, (int) $migrated_order->id );
        } catch ( \Exception $e ) {
            $this->log(
                'Failed to process legacy subscription %s for order %d - %s',
                $subscription_id,
                $legacy_order_id,
                $e->getMessage()
            );
        }

        return $this->acknowledge();
    }

    private function get_legacy_order_id( array $data ): int {
        $order_id = absint( $data['object']['plan']['metadata']['order_id'] ?? 0 );

        if ( $order_id ) {
            return $order_id;
        }

        return absint( $data['previous_attributes']['plan']['metadata']['order_id'] ?? 0 );
    }

    private function ensure_package_subscription_link( $migrated_order, string $subscription_id, $invoice ): bool {
        $package_repository = directorist_user_package_repository();
        $package            = $package_repository->get_by_subscription_id( $subscription_id );

        if ( ! $package ) {
            $package = $package_repository
                ->get_query_builder()
                ->where( 'user_id', (int) $migrated_order->user_id )
                ->where( 'plan_id', (int) $migrated_order->ref )
                ->where( 'is_recurring', 1 )
                ->where( 'is_legacy', 1 )
                ->order_by_desc( 'id' )
                ->first();
        }

        if ( ! $package ) {
            $this->log(
                'No recurring package found for migrated order %d and subscription %s.',
                (int) $migrated_order->id,
                $subscription_id
            );
            return false;
        }

        if ( (int) $package->user_id !== (int) $migrated_order->user_id || (int) $package->plan_id !== (int) $migrated_order->ref ) {
            $this->log(
                'Package %d does not match migrated order %d for subscription %s.',
                (int) $package->id,
                (int) $migrated_order->id,
                $subscription_id
            );
            return false;
        }

        if ( ! empty( $package->subscription_id ) && $subscription_id !== (string) $package->subscription_id ) {
            $this->log(
                'Package %d is linked to subscription %s instead of %s.',
                (int) $package->id,
                (string) $package->subscription_id,
                $subscription_id
            );
            return false;
        }

        if ( ! empty( $package->subscription_method ) && Stripe::get_key() !== (string) $package->subscription_method ) {
            $this->log(
                'Package %d uses payment method %s instead of %s.',
                (int) $package->id,
                (string) $package->subscription_method,
                Stripe::get_key()
            );
            return false;
        }

        $package_dto  = ( new UserPackageDTO )->set_id( (int) $package->id );
        $needs_update = false;

        if ( empty( $package->subscription_id ) ) {
            $package_dto->set_subscription_id( $subscription_id );
            $needs_update = true;
        }

        if ( empty( $package->subscription_method ) ) {
            $package_dto->set_subscription_method( Stripe::get_key() );
            $needs_update = true;
        }

        if ( empty( $package->subscription_currency ) && ! empty( $invoice->currency ) ) {
            $package_dto->set_subscription_currency( strtoupper( (string) $invoice->currency ) );
            $needs_update = true;
        }

        if ( null === $package->subscription_amount ) {
            $invoice_amount = 'paid' === ( $invoice->status ?? '' )
                ? (float) $invoice->amount_paid / 100
                : (float) $invoice->amount_due / 100;

            $package_dto->set_subscription_amount( $invoice_amount );
            $needs_update = true;
        }

        if ( $needs_update ) {
            $package_repository->update( $package_dto );
        }

        return true;
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

    private function acknowledge(): WP_REST_Response {
        return new WP_REST_Response(
            [
                'message' => 'Webhook received',
            ]
        );
    }

    private function log( string $message, ...$values ): void {
        error_log(
            'Directorist Stripe: ' . ( $values ? vsprintf( $message, $values ) : $message )
        );
    }
}
