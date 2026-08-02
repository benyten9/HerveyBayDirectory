<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Routing\Route;
use DirectoristPricingPlan\App\Http\Controllers\PlanController;
use DirectoristPricingPlan\App\Http\Controllers\ListingsController;
use DirectoristPricingPlan\App\Http\Controllers\PackageController;

Route::group(
    'admin', function(){
        require_once __DIR__ . '/admin.php';
    }, ['admin']
);

Route::group(
    'plans', function() {
        Route::get( '/directory/{directory_type_id}', [ PlanController::class, 'get_by_directory_type' ] );
    }
);

Route::group(
    'listings', function() {
        Route::post( 'mark-listing-as', [ ListingsController::class, 'mark_listing_as' ] );
    }, ['user']
);

Route::group(
    'packages', function() {
        Route::get( '/', [ PackageController::class, 'user_packages' ] );
        Route::post( '/{id}/cancel', [ PackageController::class, 'cancel' ] );
        Route::post( '/{id}/cancel-at-period-end', [ PackageController::class, 'cancel_at_period_end' ] );
    }, ['user']
);