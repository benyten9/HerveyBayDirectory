<?php

namespace DirectoristStripe\App\Providers\Admin;

defined( "ABSPATH" ) || exit;

use DirectoristStripe\WpMVC\Contracts\Provider;
use DirectoristStripe\WpMVC\Exceptions\Exception;

class LicenseServiceProvider implements Provider {
    public function boot() {
        add_filter( 'atbdp_license_settings_controls', [$this, 'license_settings_controls'] );
    }

    public function license_settings_controls( $default ) {
        $status = get_option( 'directorist_stripe_license_status' );

        if ( ! empty( $status ) && ( $status !== false && $status == 'valid' ) ) {
            $action = [
                'type'       => 'toggle',
                'name'       => 'stripe_deactivated',
                'label'      => __( 'Action', 'directorist-stripe' ),
                'validation' => 'numeric',
            ];
        } else {
            $action = [
                'type'       => 'toggle',
                'name'       => 'stripe_activated',
                'label'      => __( 'Action', 'directorist-stripe' ),
                'validation' => 'numeric',
            ];
        }

        $new = apply_filters(
            'atbdp_stripe_license_controls', [
                'type'        => 'section',
                'title'       => __( 'Stripe', 'directorist-stripe' ),
                'description' => __( 'You can active your Stripe extension here.', 'directorist-stripe' ),
                'fields'      => apply_filters(
                    'atbdp_stripe_license_settings_field', [
                        [
                            'type'        => 'textbox',
                            'name'        => 'stripe_license',
                            'label'       => __( 'License', 'directorist-stripe' ),
                            'description' => __( 'Enter your Stripe extension license', 'directorist-stripe' ),
                            'default'     => '',
                        ],
                        $action,
                    ]
                ),
            ]
        );

        $settings = apply_filters( 'atbdp_licence_menu_for_stripe', true );

        if ( $settings ) {
            array_push( $default, $new );
        }

        return $default;
    }
}