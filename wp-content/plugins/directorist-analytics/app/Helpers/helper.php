<?php

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\WpMVC\App;
use DirectoristAnalytics\DI\Container;

function directorist_analytics():App {
    return App::$instance;
}

function directorist_analytics_config( string $config_key ) {
    return directorist_analytics()::$config->get( $config_key );
}

function directorist_analytics_app_config( string $config_key ) {
    return directorist_analytics_config( "app.{$config_key}" );
}

function directorist_analytics_version() {
    return directorist_analytics_app_config( 'version' );
}

function directorist_analytics_container():Container {
    return directorist_analytics()::$container;
}

function directorist_analytics_singleton( string $class ) {
    return directorist_analytics_container()->get( $class );
}

function directorist_analytics_url( string $url = '' ) {
    return directorist_analytics()->get_url( $url );
}

function directorist_analytics_dir( string $dir = '' ) {
    return directorist_analytics()->get_dir( $dir );
}
