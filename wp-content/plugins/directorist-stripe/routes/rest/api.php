<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\App\Http\Controllers\CheckoutController;
use DirectoristStripe\App\Http\Controllers\WebhookController;
use DirectoristStripe\WpMVC\Routing\Route;

Route::get( 'success', [CheckoutController::class, 'success'] );
Route::post( 'webhook', [CheckoutController::class, 'webhook'] );

Route::get( 'webhook-register',   [ WebhookController::class, 'register' ],   ['admin'] );
Route::get( 'webhook-unregister', [ WebhookController::class, 'unregister' ], ['admin'] );