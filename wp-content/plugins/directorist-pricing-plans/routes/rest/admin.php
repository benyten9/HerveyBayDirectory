<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Http\Controllers\Admin\PlanController;
use DirectoristPricingPlan\App\Http\Controllers\Admin\DirectoryController;
use DirectoristPricingPlan\App\Http\Controllers\Admin\PackageController;
use DirectoristPricingPlan\App\Http\Controllers\Admin\MigrationController;
use DirectoristPricingPlan\WpMVC\Routing\Route;

Route::group(
    'plans', function() {
        Route::get( 'fallback', [PlanController::class, 'get_fallback_plans'] );
        Route::post( '/{id}/directory-type', [PlanController::class, 'assign_directory_type'] );
        Route::resource( '/', PlanController::class );
    }
);

Route::group(
    'packages', function() {
        Route::get( '/assignment-options', [ PackageController::class, 'assignment_options' ] );
        Route::post( '/assign', [ PackageController::class, 'assign' ] );
        Route::post( '/{id}/cancel', [ PackageController::class, 'cancel' ] );
        Route::post( '/{id}/cancel-at-period-end', [ PackageController::class, 'cancel_at_period_end' ] );
        Route::get( '/', [ PackageController::class, 'index' ] );
        Route::get( '/{id}', [ PackageController::class, 'show' ] );
        Route::get( '/{id}/orders', [ PackageController::class, 'orders' ] );
        Route::get( '/{id}/logs', [ PackageController::class, 'logs' ] );
    }
);

Route::group(
    'directory', function() {
        Route::get( 'types', [DirectoryController::class, 'get_types'] );
        Route::get( 'categories', [DirectoryController::class, 'get_categories'] );
        Route::get( 'assign-plans', [DirectoryController::class, 'plan_assignment_index'] );
        Route::post( 'assign-plans', [DirectoryController::class, 'assign_plans'] );
        Route::get( 'assign-listing-directory-types', [DirectoryController::class, 'listing_directory_assignment_index'] );
        Route::post( 'assign-listing-directory-types', [DirectoryController::class, 'assign_listing_directory_types'] );
    }
);

Route::group(
    'migration', function() {
        Route::post( 'start', [ MigrationController::class, 'start' ] );
        Route::post( 'retry', [ MigrationController::class, 'retry' ] );
        Route::post( 'status', [ MigrationController::class, 'status' ] );
    }
);
