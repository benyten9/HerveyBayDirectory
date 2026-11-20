<?php

use DirectoristAnalytics\WpMVC\Enqueue\Enqueue;

defined( 'ABSPATH' ) || exit;

// Enqueue::script( 'wpmvc-app-script', 'build/js/app' );
// Enqueue::style( 'wpmvc-app-style', 'build/css/app' );

defined( 'ABSPATH' ) || exit;

// Get current screen
$screen = get_current_screen();

// Check if we're on the Analytics page
if ( isset( $_GET['page'] ) && 'directorist-analytics' === $_GET['page'] ) {
    
    // Enqueue your custom CSS
    Enqueue::style( 'directorist-analytics-admin', 'build/css/app' );
    
    // Enqueue your custom JS
    Enqueue::script( 'directorist-analytics-admin', 'build/js/app', [], true );
    
    // Pass API configuration to frontend
    // Note: After changing these values, you MUST rebuild JavaScript and clear browser cache
    wp_localize_script( 'directorist-analytics-admin', 'directoristAnalyticsConfig', [
        'apiBaseUrl' => rest_url( 'directorist-analytics' ), // Updated to match your API endpoint structure
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'useDemoData' => apply_filters( 'directorist_analytics_use_demo_data', false ), // false = use real API, true = use demo data
        // When useDemoData is false and real API fails, should we fallback to demo?
        'fallbackToDemoOnError' => apply_filters( 'directorist_analytics_fallback_to_demo_on_error', false ), // false = show actual errors instead of falling back to demo
    ] );
}