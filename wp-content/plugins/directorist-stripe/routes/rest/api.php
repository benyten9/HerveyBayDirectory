<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\App\Http\Controllers\CheckoutController;
use DirectoristStripe\App\Http\Controllers\LegacyWebhookController;
use DirectoristStripe\App\Http\Controllers\WebhookController;
use DirectoristStripe\WpMVC\Routing\Route;

Route::get( 'success', [CheckoutController::class, 'success'] );
Route::post( 'webhook', [CheckoutController::class, 'webhook'] );

Route::get( 'webhook-register',   [ WebhookController::class, 'register' ],   ['admin'] );
Route::get( 'webhook-unregister', [ WebhookController::class, 'unregister' ], ['admin'] );

register_rest_route(
    'directorist/subscription',
    '/updated/',
    [
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => [ new LegacyWebhookController(), 'webhook' ],
        'permission_callback' => '__return_true',
    ]
);
