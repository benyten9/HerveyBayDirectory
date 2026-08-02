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

        $stripe_fields['stripe_gateway_test_mode']   = [
            'type'  => 'toggle',
            'label' => __( 'Enable Test Mode', 'directorist-stripe' ),
            'value' => 1,
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
            'type'        => 'text',
            'label'       => __( 'Live Publishable Key', 'directorist-stripe' ),
            'description' => sprintf( __( 'Enter your Stripe Live Publishable Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'       => ''
        ];
        $stripe_fields['stripe_live_sk']             = [
            'type'        => 'text',
            'label'       => __( 'Live Secret Key', 'directorist-stripe' ),
            'description' => sprintf( __( 'Enter your Stripe Live Secret Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'       => ''
        ];

        $live_webhook = get_directorist_option( 'stripe_live_webhook' );
        $live_id      = ! empty( $live_webhook['id'] ) ? $live_webhook['id'] : '';
        $live_button  = $this->get_webhook_button_config( 'stripe_live_webhook', 'live', $live_id );

        $stripe_fields['stripe_live_webhook'] = [
            'type'         => 'button',
            'label'        => __( 'Live Webhook', 'directorist-stripe' ),
            'button-label' => $live_button['label'],
            'description'  => __( 'Register the webhook endpoint with your Stripe live account so Stripe can send payment events to this site.', 'directorist-stripe' ),
            'url'          => esc_url_raw( $live_button['url'] ),
            'value'        => $live_id,
        ];

        $stripe_fields['stripe_test_pk']             = [
            'type'        => 'text',
            'label'       => __( 'Test Publishable Key', 'directorist-stripe' ),
            'description' => sprintf( __( 'Enter your Stripe Test Publishable Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'       => ''
        ];
        $stripe_fields['stripe_test_sk']             = [
            'type'        => 'text',
            'label'       => __( 'Test Secret Key', 'directorist-stripe' ),
            'description' => sprintf( __( 'Enter your Stripe Test Secret Key Here. You can find your API key on your Stripe Dashboard Under Developers > API section. %s', 'directorist-stripe' ), $stripe_url ),
            'value'       => ''
        ];

        $test_webhook = get_directorist_option( 'stripe_test_webhook' );
        $test_id      = ! empty( $test_webhook['id'] ) ? $test_webhook['id'] : '';
        $test_button  = $this->get_webhook_button_config( 'stripe_test_webhook', 'sandbox', $test_id );

        $stripe_fields['stripe_test_webhook'] = [
            'type'         => 'button',
            'label'        => __( 'Sandbox Webhook', 'directorist-stripe' ),
            'button-label' => $test_button['label'],
            'description'  => __( 'Register the webhook endpoint with your Stripe sandbox (test) account so Stripe can send payment events to this site.', 'directorist-stripe' ),
            'url'          => esc_url_raw( $test_button['url'] ),
            'value'        => $test_id,
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
        $webhook_id   = ! empty( $webhook['id'] ) ? (string) $webhook['id'] : '';

        if ( '' !== $webhook_id ) {
            return;
        }

        $webhook_type = $is_test_mode ? 'sandbox' : 'live';
        $button       = $this->get_webhook_button_config( $webhook_key, $webhook_type, '' );
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

    private function get_webhook_button_config( $key, $type, $webhook_id ) {
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
