<?php

namespace DirectoristStripe\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\App\Providers\Admin\SettingsServiceProvider;
use DirectoristStripe\Stripe\Stripe as StripeSDK;
use DirectoristStripe\Stripe\WebhookEndpoint;
use DirectoristStripe\WpMVC\RequestValidator\Validator;
use WP_REST_Request;

class WebhookController extends Controller {
    public function register( Validator $validator, WP_REST_Request $request ) {
        $type = sanitize_key( (string) $request->get_param( 'type' ) );
        $key  = sanitize_key( (string) $request->get_param( 'key' ) );

        if ( ! $this->valid_key_and_type( $key, $type ) ) {
            return $this->complete_action( $request, 'register', 'sandbox', 'stripe_test_webhook', 'error', 'stale' );
        }

        $saved_webhook = get_directorist_option( $key );

        if ( '' !== SettingsServiceProvider::get_saved_webhook_id( $saved_webhook ) ) {
            return $this->complete_action( $request, 'register', $type, $key, 'error', 'stale' );
        }

        $secret_key = get_directorist_option( 'live' === $type ? 'stripe_live_sk' : 'stripe_test_sk' );

        if ( empty( $secret_key ) ) {
            error_log( sprintf( 'Directorist Stripe: Secret key not set for %s environment.', $type ) );
            return $this->complete_action( $request, 'register', $type, $key, 'error', 'credentials' );
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

            if ( empty( $webhook->id ) || empty( $webhook->secret ) ) {
                throw new \Exception( 'Stripe did not return a webhook ID and secret.' );
            }

            update_directorist_option(
                $key,
                [
                    'id'     => $webhook->id,
                    'secret' => $webhook->secret,
                ]
            );

            return $this->complete_action( $request, 'register', $type, $key, 'success' );
        } catch ( \Exception $e ) {
            error_log( sprintf( 'Directorist Stripe: Failed to register webhook - %s', $e->getMessage() ) );
            return $this->complete_action( $request, 'register', $type, $key, 'error', 'request' );
        }
    }

    public function unregister( Validator $validator, WP_REST_Request $request ) {
        $id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
        $key  = sanitize_key( (string) $request->get_param( 'key' ) );
        $type = 'stripe_live_webhook' === $key ? 'live' : 'sandbox';

        if ( ! $this->valid_key_and_type( $key, $type ) ) {
            return $this->complete_action( $request, 'unregister', 'sandbox', 'stripe_test_webhook', 'error', 'stale' );
        }

        $saved_webhook = get_directorist_option( $key );
        $saved_id      = SettingsServiceProvider::get_saved_webhook_id( $saved_webhook );

        if ( '' === $id || '' === $saved_id || ! hash_equals( $saved_id, $id ) ) {
            return $this->complete_action( $request, 'unregister', $type, $key, 'error', 'stale' );
        }

        $secret_key = get_directorist_option( 'live' === $type ? 'stripe_live_sk' : 'stripe_test_sk' );

        if ( empty( $secret_key ) ) {
            error_log( sprintf( 'Directorist Stripe: Secret key not set for webhook with key %s.', $key ) );
            return $this->complete_action( $request, 'unregister', $type, $key, 'error', 'credentials' );
        }

        StripeSDK::setApiKey( $secret_key );

        try {
            $webhook = WebhookEndpoint::retrieve( $id );
            $webhook->delete();
            update_directorist_option( $key, [] );

            return $this->complete_action( $request, 'unregister', $type, $key, 'success' );
        } catch ( \Exception $e ) {
            if ( $this->is_missing_webhook_exception( $e ) ) {
                error_log(
                    sprintf(
                        'Directorist Stripe: Stored webhook %s for %s was not found in this Stripe account. Local webhook setting retained.',
                        $id,
                        $key
                    )
                );

                return $this->complete_action( $request, 'unregister', $type, $key, 'error', 'not_found' );
            }

            error_log( sprintf( 'Directorist Stripe: Failed to unregister webhook %s - %s', $id, $e->getMessage() ) );
            return $this->complete_action( $request, 'unregister', $type, $key, 'error', 'request' );
        }
    }

    private function valid_key_and_type( string $key, string $type ): bool {
        return ( 'stripe_live_webhook' === $key && 'live' === $type )
            || ( 'stripe_test_webhook' === $key && 'sandbox' === $type );
    }

    private function complete_action( WP_REST_Request $request, string $operation, string $type, string $key, string $status, string $reason = '' ) {
        if ( '1' === $request->get_header( 'x-directorist-async' ) ) {
            $saved_webhook = get_directorist_option( $key );
            $webhook_id    = SettingsServiceProvider::get_saved_webhook_id( $saved_webhook );
            $action        = SettingsServiceProvider::get_webhook_button_config( $key, $type, $webhook_id );

            return [
                'status_code' => 200,
                'data'        => [
                    'success'      => 'success' === $status,
                    'connected'    => '' !== $webhook_id,
                    'webhook_id'   => $webhook_id,
                    // Additive response: older Core continues using the fields above/below.
                    'action_state' => SettingsServiceProvider::get_webhook_action_state( $key, $type, $webhook_id ),
                    'message'      => SettingsServiceProvider::webhook_feedback_message( $operation, $status, $reason ),
                    'action_url'   => $action['url'],
                    'action_label' => $action['label'],
                ],
            ];
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

    private function is_missing_webhook_exception( \Exception $exception ): bool {
        if ( method_exists( $exception, 'getHttpStatus' ) && 404 === (int) $exception->getHttpStatus() ) {
            return true;
        }

        $stripe_code = method_exists( $exception, 'getStripeCode' ) ? (string) $exception->getStripeCode() : '';

        return 'resource_missing' === $stripe_code
            && false !== stripos( $exception->getMessage(), 'webhook' );
    }
}
