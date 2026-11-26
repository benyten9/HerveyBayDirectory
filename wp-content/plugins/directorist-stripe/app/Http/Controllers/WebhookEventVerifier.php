<?php

namespace DirectoristStripe\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\Stripe\Webhook;
use WP_Error;
use WP_REST_Request;

class WebhookEventVerifier {
    /**
     * Verify the raw Stripe payload before any event data is used.
     *
     * @return array|WP_Error
     */
    public static function verify( WP_REST_Request $request, $test_secret, $live_secret ) {
        $secrets = [
            0 => $test_secret,
            1 => $live_secret,
        ];

        $configured = false;

        foreach ( $secrets as $secret ) {
            if ( is_string( $secret ) && '' !== $secret ) {
                $configured = true;
                break;
            }
        }

        if ( ! $configured ) {
            return new WP_Error( 'stripe_webhook_unconfigured', 'Webhook signing secret is not configured.', [ 'status' => 503 ] );
        }

        $payload   = $request->get_body();
        $signature = $request->get_header( 'stripe-signature' );

        if ( '' === $payload || ! is_string( $signature ) || '' === $signature ) {
            return new WP_Error( 'stripe_webhook_invalid_signature', 'Invalid webhook signature.', [ 'status' => 400 ] );
        }

        foreach ( $secrets as $is_live => $secret ) {
            if ( ! is_string( $secret ) || '' === $secret ) {
                continue;
            }

            try {
                $event = Webhook::constructEvent( $payload, $signature, $secret )->toArray();
            } catch ( \Exception $e ) {
                continue;
            }

            if ( ! is_array( $event ) || ! isset( $event['livemode'] ) || ! is_bool( $event['livemode'] ) || $event['livemode'] !== (bool) $is_live ) {
                continue;
            }

            return $event;
        }

        return new WP_Error( 'stripe_webhook_invalid_signature', 'Invalid webhook signature.', [ 'status' => 400 ] );
    }
}
