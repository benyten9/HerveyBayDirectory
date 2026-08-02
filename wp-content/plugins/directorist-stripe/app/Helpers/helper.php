<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\App;
use DirectoristStripe\DI\Container;

// function directorist_stripe():App {
//     return App::$instance;
// }

// function directorist_stripe_config( string $config_key ) {
//     return directorist_stripe()::$config->get( $config_key );
// }

// function directorist_stripe_app_config( string $config_key ) {
//     return directorist_stripe_config( "app.{$config_key}" );
// }

// function directorist_stripe_version() {
//     return directorist_stripe_app_config( 'version' );
// }

// function directorist_stripe_container():Container {
//     return directorist_stripe()::$container;
// }

// function directorist_stripe_singleton( string $class ) {
//     return directorist_stripe_container()->get( $class );
// }

// function directorist_stripe_url( string $url = '' ) {
//     return directorist_stripe()->get_url( $url );
// }

// function directorist_stripe_dir( string $dir = '' ) {
//     return directorist_stripe()->get_dir( $dir );
// }

// function directorist_stripe_render( string $content ) {
//     //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
//     echo $content;
// }

function directorist_stripe_is_pricing_plan_active(): bool {
    if ( ! function_exists( 'directorist_pricing_plans_singleton' ) ) {
        return false;
    }

    return version_compare( directorist_pricing_plans_version(), '3.9.9', '>' );
}

function directorist_stripe_is_test_mode() {
    return get_directorist_option( 'stripe_gateway_test_mode', true );
}

function directorist_stripe_get_publish_key() {
    $is_test_mode = directorist_stripe_is_test_mode();

    if ( $is_test_mode ) {
        return  get_directorist_option( 'stripe_test_pk' );
    }

    return get_directorist_option( 'stripe_live_pk' );
}

function directorist_stripe_get_secret_key() {
    $is_test_mode = directorist_stripe_is_test_mode();

    if ( $is_test_mode ) {
        return  get_directorist_option( 'stripe_test_sk' );
    }

    return get_directorist_option( 'stripe_live_sk' );
}