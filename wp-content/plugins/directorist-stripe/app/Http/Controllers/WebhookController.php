<?php

namespace DirectoristStripe\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;

use DirectoristStripe\Stripe\Stripe as StripeSDK;
use DirectoristStripe\Stripe\WebhookEndpoint;
use DirectoristStripe\WpMVC\RequestValidator\Validator;

class WebhookController extends Controller {

    public function register( Validator $validator, WP_REST_Request $request ) {
        $validator->validate(
            [
                'type' => 'required|string',
                'key'  => 'required|string',
            ]
        );

        $type       = sanitize_text_field( (string) $request->get_param( 'type' ) );
        $key        = sanitize_key( (string) $request->get_param( 'key' ) );
        $secret_key = ( 'live' === $type )
            ? get_directorist_option( 'stripe_live_sk' )
            : get_directorist_option( 'stripe_test_sk' );

        if ( empty( $secret_key ) ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Secret key not set for %s environment.',
                    $type
                )
            );
            $this->redirect( $this->settings_url() );
        }

        StripeSDK::setApiKey( $secret_key );

        try {
            $webhook = WebhookEndpoint::create(
                [
                    'url'            => rest_url( 'directorist-stripe/webhook' ),
                    'enabled_events' => [
                        'checkout.session.completed',
                        'customer.subscription.created',
                        'customer.subscription.deleted',
                        'customer.subscription.updated',
                        'invoice.paid',
                        'charge.updated',
                    ],
                ]
            );

            update_directorist_option(
                $key,
                [
                    'id'     => $webhook->id,
                    'secret' => $webhook->secret,
                ]
            );
        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to register webhook - %s',
                    $e->getMessage()
                )
            );
        }

        $this->redirect( $this->settings_url() );
    }

    public function unregister( Validator $validator, WP_REST_Request $request ) {
        $validator->validate(
            [
                'id'  => 'required|string',
                'key' => 'required|string',
            ]
        );

        $id         = sanitize_text_field( (string) $request->get_param( 'id' ) );
        $key        = sanitize_key( (string) $request->get_param( 'key' ) );
        $secret_key = ( false !== strpos( $key, 'live' ) )
            ? get_directorist_option( 'stripe_live_sk' )
            : get_directorist_option( 'stripe_test_sk' );


        if ( empty( $secret_key ) ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Secret key not set for webhook with key %s.',
                    $key
                )            );
            $this->redirect( $this->settings_url() );
        }

        StripeSDK::setApiKey( $secret_key );

        try {
            $webhook = WebhookEndpoint::retrieve( $id );
            $webhook->delete();

            update_directorist_option( $key, [] );
        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Directorist Stripe: Failed to unregister webhook %s - %s',
                    $id,
                    $e->getMessage()
                )
            );
        }

        $this->redirect( $this->settings_url() );
    }

    private function settings_url(): string {
        return admin_url( 'edit.php?post_type=at_biz_dir&page=atbdp-settings#monetization_settings__stripe' );
    }

    private function redirect( string $url ) {
        wp_safe_redirect( $url );
        exit;
    }
}
