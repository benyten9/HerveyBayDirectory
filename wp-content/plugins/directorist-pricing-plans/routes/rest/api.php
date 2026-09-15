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
        Route::get( '/{id}/compatible-plans', [ ListingsController::class, 'compatible_plans' ] );
        Route::post( '/{id}/plan', [ ListingsController::class, 'change_plan' ] );
        Route::delete( '/{id}/plan', [ ListingsController::class, 'remove_plan' ] );
        Route::post( '/{id}/renew', [ ListingsController::class, 'renew' ] );
    }, ['user']
);

Route::group(
    'packages', function() {
        Route::get( '/renewable-listings', [ PackageController::class, 'renewable_listings' ] );
        Route::get( '/', [ PackageController::class, 'user_packages' ] );
        Route::get( '/{id}/usage', [ PackageController::class, 'usage' ] );
        Route::post( '/{id}/recheck-payment', [ PackageController::class, 'recheck_payment' ] );
        Route::post( '/{id}/renew-expired-listings', [ PackageController::class, 'renew_expired_listings' ] );
        Route::post( '/{id}/cancel', [ PackageController::class, 'cancel' ] );
        Route::post( '/{id}/cancel-at-period-end', [ PackageController::class, 'cancel_at_period_end' ] );
    }, ['user']
);
