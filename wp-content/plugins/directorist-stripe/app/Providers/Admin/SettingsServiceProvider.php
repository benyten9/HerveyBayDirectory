<?php

namespace DirectoristStripe\App\Providers\Admin;

defined( "ABSPATH" ) || exit;

use DirectoristStripe\WpMVC\Contracts\Provider;

class SettingsServiceProvider implements Provider {
    public function boot() {
        add_filter( 'atbdp_monetization_settings_submenu', [$this, 'atbdp_monetization_settings_submenu'] );
        add_filter( 'atbdp_listing_type_settings_field_list', [$this, 'atbdp_listing_type_settings_field_list'] );
        add_action( 'admin_notices', [ $this, 'maybe_show_webhook_notice' ] );
    }

    public function atbdp_monetization_settings_submenu( $submenu ) {
            $sections = [
                'gateways' => [
                    'title'       => __( 'Stripe Gateway Settings', 'directorist-stripe' ),
                    'description' => __( 'You can customize all the settings related to your stripe gateway. After switching any option, Do not forget to save the changes.', 'directorist-stripe' ),
                    'fields'      =>  ['stripe_gateway_test_mode', 'stripe_gateway_title', 'stripe_gateway_description', 'stripe_live_pk', 'stripe_live_sk', 'stripe_live_webhook', 'stripe_test_pk', 'stripe_test_sk', 'stripe_test_webhook'],
                ],
            ];

            $submenu['stripe'] = [
                'label'    => __( 'Stripe Gateway', 'directorist-stripe' ),
                'icon'     => '<i class="fa fa-cc-stripe"></i>',
                'sections' => apply_filters( 'atbdp_stripe_settings_controls', $sections ),
            ];

            return $submenu;
    }

    public function atbdp_listing_type_settings_field_list( $stripe_fields ) {
        // $gsp        = sprintf( "<a target='_blank' href='%s'>%s</a>", esc_url( admin_url( 'edit.php?post_type=at_biz_dir&page=aazztech_settings#_gateway_general' ) ), __( 'Gateway Settings Page', 'directorist-stripe' ) );
        $stripe_url = sprintf( "<a target='_blank' href='%s'>%s</a>", esc_url( "https://dashboard.stripe.com/account/apikeys" ), __( 'Get your Stripe API keys', 'directorist-stripe' ) );

        $live_only = [
            'where'      => 'stripe_gateway_test_mode',
            'conditions' => [ [ 'key' => 'value', 'compare' => '=', 'value' => false ] ],
        ];
        $test_only = [
            'where'      => 'stripe_gateway_test_mode',
            'conditions' => [ [ 'key' => 'value', 'compare' => '=', 'value' => true ] ],
        ];

        $stripe_fields['stripe_gateway_test_mode']   = [
            'section-toggle' => false,
            'type'           => 'toggle',
            'label'          => __( 'Enable Sandbox Mode', 'directorist-stripe' ),
            'value'          => 1,
        ];
        $stripe_fields['stripe_gateway_title']       = [
            'type'        => 'text',
            'label'       => __( 'Gateway Title', 'directorist-stripe' ),
            'description' => __( 'Enter the title of this gateway that should be displayed to the user on the front end.', 'directorist-stripe' ),
            'value'       => esc_html__( 'Stripe', 'directorist-stripe' ),
        ];
        $stripe_fields['stripe_gateway_description'] = [
            'type'        => 'text',
            'label'       => __( 'Gateway Description', 'directorist-stripe' ),
            'description' => __( 'Enter some description for your user to make payment using stripe.', 'directorist-stripe' ),
            'value'       => __( 'You can make payment using your credit card using stripe if you choose this payment gateway.', 'directorist-stripe' )
        ];
        $stripe_fields['stripe_live_pk']             = [
            'type'                   => 'text',
            'show-if'                => $live_only,
            'search-respect-show-if' => true,
            'label'                  => __( 'Live Publishable Key', 'directorist-stripe' ),
            'description'            => sprintf( __( 'Enter your Stripe Live Publishable Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'                  => ''
        ];
        $stripe_fields['stripe_live_sk']             = [
            'type'                   => 'text',
            'show-if'                => $live_only,
            'search-respect-show-if' => true,
            'label'                  => __( 'Live Secret Key', 'directorist-stripe' ),
            'description'            => sprintf( __( 'Enter your Stripe Live Secret Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'                  => ''
        ];

        $live_webhook = get_directorist_option( 'stripe_live_webhook' );
        $live_id      = self::get_saved_webhook_id( $live_webhook );
        $live_button  = self::get_webhook_button_config( 'stripe_live_webhook', 'live', $live_id );

        $stripe_fields['stripe_live_webhook'] = [
            'async-action-config'        => self::get_webhook_action_config( 'live' ),
            'async-action-state'         => self::get_webhook_action_state( 'stripe_live_webhook', 'live', $live_id ),
            'type'                       => 'button',
            'show-if'                    => $live_only,
            'search-respect-show-if'     => true,
            'label'                      => __( 'Live Webhook', 'directorist-stripe' ),
            'button-label'               => $live_button['label'],
            'description'                => __( 'Register the webhook endpoint with your Stripe live account so Stripe can send payment events to this site.', 'directorist-stripe' ),
            'url'                        => esc_url_raw( $live_button['url'] ),
            'value'                      => $live_id,
            'async-action'               => true,
            'button-label-on-processing' => __( 'Updating Live Webhook…', 'directorist-stripe' ),
            'status-label-connected'     => __( 'Registered (saved)', 'directorist-stripe' ),
            'status-label-disconnected'  => __( 'Not registered', 'directorist-stripe' ),
        ];

        $stripe_fields['stripe_test_pk'] = [
            'type'                   => 'text',
            'show-if'                => $test_only,
            'search-respect-show-if' => true,
            'label'                  => __( 'Sandbox Publishable Key', 'directorist-stripe' ),
            'description'            => sprintf( __( 'Enter your Stripe Sandbox Publishable Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'                  => ''
        ];
        $stripe_fields['stripe_test_sk'] = [
            'type'                   => 'text',
            'show-if'                => $test_only,
            'search-respect-show-if' => true,
            'label'                  => __( 'Sandbox Secret Key', 'directorist-stripe' ),
            'description'            => sprintf( __( 'Enter your Stripe Sandbox Secret Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'                  => ''
        ];

        $test_webhook = get_directorist_option( 'stripe_test_webhook' );
        $test_id      = self::get_saved_webhook_id( $test_webhook );
        $test_button  = self::get_webhook_button_config( 'stripe_test_webhook', 'sandbox', $test_id );

        $stripe_fields['stripe_test_webhook'] = [
            'async-action-config'        => self::get_webhook_action_config( 'sandbox' ),
            'async-action-state'         => self::get_webhook_action_state( 'stripe_test_webhook', 'sandbox', $test_id ),
            'type'                       => 'button',
            'show-if'                    => $test_only,
            'search-respect-show-if'     => true,
            'label'                      => __( 'Sandbox Webhook', 'directorist-stripe' ),
            'button-label'               => $test_button['label'],
            'description'                => __( 'Register the webhook endpoint with your Stripe sandbox account so Stripe can send payment events to this site.', 'directorist-stripe' ),
            'url'                        => esc_url_raw( $test_button['url'] ),
            'value'                      => $test_id,
            'async-action'               => true,
            'button-label-on-processing' => __( 'Updating Sandbox Webhook…', 'directorist-stripe' ),
            'status-label-connected'     => __( 'Registered (saved)', 'directorist-stripe' ),
            'status-label-disconnected'  => __( 'Not registered', 'directorist-stripe' ),
        ];

        return $stripe_fields;
    }

    public function maybe_show_webhook_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $is_test_mode = directorist_stripe_is_test_mode();
        $webhook_key  = $is_test_mode ? 'stripe_test_webhook' : 'stripe_live_webhook';
        $webhook      = get_directorist_option( $webhook_key );
        $webhook_id   = self::get_saved_webhook_id( $webhook );

        if ( '' !== $webhook_id ) {
            return;
        }

        $webhook_type = $is_test_mode ? 'sandbox' : 'live';
        $button       = self::get_webhook_button_config( $webhook_key, $webhook_type, '' );
        $settings_url = admin_url( 'edit.php?post_type=at_biz_dir&page=atbdp-settings#monetization_settings__stripe' );
        $message      = $is_test_mode
            ? __( 'Directorist - Stripe sandbox webhook is not registered. Register it to receive test payment and subscription events.', 'directorist-stripe' )
            : __( 'Directorist - Stripe live webhook is not registered. Register it to receive live payment and subscription events.', 'directorist-stripe' );

        printf(
            '<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a> | <a href="%4$s">%5$s</a></p></div>',
            esc_html( $message ),
            esc_url( $button['url'] ),
            esc_html( $button['label'] ),
            esc_url( $settings_url ),
            esc_html__( 'Open Stripe settings', 'directorist-stripe' )
        );
    }

    public static function webhook_feedback_message( $operation, $status, $reason = '' ) {
        if ( 'success' === $status ) {
            return 'register' === $operation
                ? __( 'Stripe registered the webhook and Directorist saved its ID.', 'directorist-stripe' )
                : __( 'Stripe confirmed removal and Directorist cleared the saved webhook ID.', 'directorist-stripe' );
        }

        if ( 'credentials' === $reason ) {
            return __( 'The Stripe secret key is missing for this environment. Save the correct key before trying again. The webhook state was not changed.', 'directorist-stripe' );
        }

        if ( 'stale' === $reason ) {
            return __( 'The webhook details changed or the action link is no longer valid. Refresh the settings and try again. No webhook state was changed.', 'directorist-stripe' );
        }

        if ( 'not_found' === $reason ) {
            return __( 'This webhook ID was not found using the current Stripe account. It may belong to another account. Directorist kept the saved ID so you can check the account where it was created.', 'directorist-stripe' );
        }

        return 'register' === $operation
            ? __( 'Stripe did not confirm registration, so Directorist did not save a webhook ID. Check the site error log for details.', 'directorist-stripe' )
            : __( 'Stripe did not confirm removal, so Directorist kept the saved webhook ID. Check the site error log for details.', 'directorist-stripe' );
    }

    /** Read structured provider data and the scalar ID supported by older Core UI. */
    public static function get_saved_webhook_id( $value ): string {
        $id = is_array( $value ) ? ( $value['id'] ?? '' ) : $value;
        return is_string( $id ) || is_int( $id ) ? (string) $id : '';
    }

    /** UI metadata for Core's generic async settings action. Older Core ignores it. */
    public static function get_webhook_action_config( string $type ): array {
        $prefix = 'stripe_' . ( 'live' === $type ? 'live' : 'test' );
        $fields = [ $prefix . '_pk', $prefix . '_sk' ];

        return [
            'requiredFields'   => $fields,
            'lockFields'       => $fields,
            'saveBeforeAction' => true,
            'labels'           => [
                'save'            => 'live' === $type
                    ? __( 'Save & Register Live Webhook', 'directorist-stripe' )
                    : __( 'Save & Register Sandbox Webhook', 'directorist-stripe' ),
                'active'          => __( 'Webhook ID saved', 'directorist-stripe' ),
                'inactive'        => __( 'Not registered', 'directorist-stripe' ),
                'missing'         => __( 'Keys missing', 'directorist-stripe' ),
                'required'        => __( 'Add both API keys before registering.', 'directorist-stripe' ),
                'recovery'        => __( 'Add valid keys for the same gateway account and save before disconnecting.', 'directorist-stripe' ),
                'unsaved'         => __( 'Save changes first to use the updated keys.', 'directorist-stripe' ),
                'locked'          => __( 'Disconnect the webhook to edit these keys.', 'directorist-stripe' ),
                'pending'         => __( 'Wait for the webhook action to finish before editing these keys.', 'directorist-stripe' ),
                'details'         => __( 'Webhook ID saved on this site; provider status has not been rechecked. Disconnect to remove the gateway webhook.', 'directorist-stripe' ),
                'recoveryDetails' => __( 'Webhook ID is still saved. Add valid keys for the same gateway account, save changes, then disconnect. Removing keys does not remove the gateway webhook.', 'directorist-stripe' ),
            ],
        ];
    }

    /** Keep URLs and provider state normalization out of Core's button component. */
    public static function get_webhook_action_state( string $key, string $type, string $webhook_id ): array {
        $button = self::get_webhook_button_config( $key, $type, $webhook_id );
        $label  = $button['label'];

        if ( '' !== $webhook_id ) {
            $label = 'live' === $type
                ? __( 'Disconnect Live Webhook', 'directorist-stripe' )
                : __( 'Disconnect Sandbox Webhook', 'directorist-stripe' );
        }

        return [
            'active' => '' !== $webhook_id,
            'value'  => $webhook_id,
            'url'    => esc_url_raw( $button['url'] ),
            'label'  => $label,
        ];
    }

    public static function get_webhook_button_config( $key, $type, $webhook_id ) {
        if ( '' !== $webhook_id ) {
            return [
                'label' => 'live' === $type
                    ? __( 'Unregister Live Webhook', 'directorist-stripe' )
                    : __( 'Unregister Sandbox Webhook', 'directorist-stripe' ),
                'url'   => add_query_arg(
                    [ 'id' => $webhook_id, 'key' => $key, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ],
                    rest_url( 'directorist-stripe/webhook-unregister' )
                ),
            ];
        }

        return [
            'label' => 'live' === $type
                ? __( 'Register Live Webhook', 'directorist-stripe' )
                : __( 'Register Sandbox Webhook', 'directorist-stripe' ),
            'url'   => add_query_arg(
                [ 'type' => $type, 'key' => $key, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ],
                rest_url( 'directorist-stripe/webhook-register' )
            ),
        ];
    }
}
