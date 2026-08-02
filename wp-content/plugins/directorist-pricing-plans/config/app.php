<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Providers\Admin\BuilderFormFields;
use DirectoristPricingPlan\App\Providers\Admin\UpdateServiceProvider;
use DirectoristPricingPlan\App\Providers\SettingsServiceProvider;
use DirectoristPricingPlan\App\Providers\DirectCheckout;
use DirectoristPricingPlan\App\Providers\FormFields;
use DirectoristPricingPlan\App\Providers\ListingFormManager;
use DirectoristPricingPlan\App\Http\Middleware\EnsureIsUserAdmin;
use DirectoristPricingPlan\App\Http\Middleware\Auth;
use DirectoristPricingPlan\App\Providers\Admin\MenuServiceProvider;
use DirectoristPricingPlan\App\Providers\Admin\NoticeServiceProvider;
use DirectoristPricingPlan\App\Providers\Admin\MigrationNoticeServiceProvider;
use DirectoristPricingPlan\App\Providers\Admin\ListingPlanColumnProvider;
use DirectoristPricingPlan\App\Providers\Admin\ListingPlanMetaboxProvider;
use DirectoristPricingPlan\App\Providers\ShortcodeServiceProvider;
use DirectoristPricingPlan\App\Providers\CheckoutServiceProvider;
use DirectoristPricingPlan\App\Providers\PaymentCheckoutServiceProvider;
use DirectoristPricingPlan\App\Providers\TrialServiceProvider;
use DirectoristPricingPlan\App\Providers\ListingDashboardServiceProvider;
use DirectoristPricingPlan\App\Providers\PlanServiceProvider;
use DirectoristPricingPlan\App\Providers\LegacyPlanApiProvider;
use DirectoristPricingPlan\App\Providers\QueueServiceProvider;
use DirectoristPricingPlan\App\Providers\PackageLogProvider;
use DirectoristPricingPlan\Database\Migrations\V4Migration;
use DirectoristPricingPlan\Database\Migrations\RepairV4PackageStatusMigration;
use DirectoristPricingPlan\Database\Migrations\RepairV4PackageTimestampColumnsMigration;
use DirectoristPricingPlan\Database\Migrations\RepairV4ActivePackageDatesMigration;
use DirectoristPricingPlan\Database\Migrations\RepairV4LifetimePackageDatesMigration;
use DirectoristPricingPlan\Database\Migrations\RepairV4PlanOrderMetaTimestampMigration;
use DirectoristPricingPlan\Database\Migrations\RepairV4PendingPlanOrderMetaMigration;
use DirectoristPricingPlan\App\Providers\ListingsQueryProvider;
use DirectoristPricingPlan\App\Providers\EmailService\PackageNotificationProvider;
// use DirectoristPricingPlan\Database\Migrations\TestMigration;

return [
    /**
     * The version of the plugin.
     */
    'version'                     => get_file_data( dirname( __DIR__ ) . '/directorist-pricing-plans.php', [ 'Version' => 'Version' ] )['Version'] ?? '',

    /**
     * Configuration for the REST API.
     */
    'rest_api'                    => [
        /**
         * The namespace for the REST API.
         */
        'namespace' => 'directorist-pricing-plans',
        
        /**
         * The versions of the REST API.
         */
        'versions'  => []
    ],

    /**
     * Configuration for the AJAX API.
     */
    'ajax_api'                    => [
        /**
         * The namespace for the AJAX API.
         */
        'namespace' => 'directorist-pricing-plans',
        
        /**
         * The versions of the AJAX API.
         */
        'versions'  => []
    ],

    /**
     * Service providers for the plugin.
     */
    'providers'                   => [
        SettingsServiceProvider::class,
        QueueServiceProvider::class,
        PackageLogProvider::class,
        FormFields::class,
        ShortcodeServiceProvider::class,
        PlanServiceProvider::class,
        LegacyPlanApiProvider::class,
        CheckoutServiceProvider::class,
        PaymentCheckoutServiceProvider::class,
        TrialServiceProvider::class,
        DirectCheckout::class,
        ListingDashboardServiceProvider::class,
        ListingFormManager::class,
        ListingsQueryProvider::class,
        PackageNotificationProvider::class,
    ],

    /**
     * Service providers for the admin area of the plugin.
     */
    'admin_providers'             => [
        MenuServiceProvider::class,
        ListingPlanColumnProvider::class,
        ListingPlanMetaboxProvider::class,
        MigrationNoticeServiceProvider::class,
        NoticeServiceProvider::class,
        BuilderFormFields::class,
        UpdateServiceProvider::class,
    ],

    /**
     * Middleware configuration for the plugin.
     */
    'middleware'                  => [
        /**
         * Middleware for admin routes.
         */
        'admin' => EnsureIsUserAdmin::class,
        'user'  => Auth::class,
    ],

    /**
     * The database option key for storing migration information.
     */
    'migration_db_option_key'     => 'directorist_pricing_plans_migrations',

    /**
     * List of migrations for the plugin.
     */
    'migrations'                  => [
        // 'test-migration' => TestMigration::class,
        'v4-migration'                         => V4Migration::class,
        'repair-v4-package-statuses'           => RepairV4PackageStatusMigration::class,
        'repair-v4-package-timestamps'         => RepairV4PackageTimestampColumnsMigration::class,
        'repair-v4-active-package-dates'       => RepairV4ActivePackageDatesMigration::class,
        'repair-v4-lifetime-package-dates'     => RepairV4LifetimePackageDatesMigration::class,
        'repair-v4-plan-order-meta-timestamps' => RepairV4PlanOrderMetaTimestampMigration::class,
        'repair-v4-pending-plan-order-meta'    => RepairV4PendingPlanOrderMetaMigration::class,
    ],

    /**
     * This configuration option defines a hook that will fire before executing the route callback,
     * such as before a controller action. It provides two parameters:
     * 
     * @param WP_REST_Request $wp_rest_request The current REST request object.
     * @param string $full_route The full route being accessed.
     */
    'rest_response_action_hook'   => 'directorist-pricing-plans_rest_response_action',

    /**
     * Configuration for the REST API response filter hook.
     *
     * This filter hook allows overriding the entire REST API response.
     * 
     * @param $response The response object from the controller.
     * @param WP_REST_Request  $wp_rest_request The request object.
     * @param string           $full_route The full route of the request.
     */
    'rest_response_filter_hook'   => 'directorist-pricing-plans_rest_response_filter',

    /**
     * This filter hook that can override all REST API permissions.
     * 
     * @param mixed $permission The current permission setting.
     * @param mixed $middleware The middleware being applied.
     * @param string $full_route The full route of the API endpoint.
     */
    'rest_permission_filter_hook' => 'directorist-pricing-plans_rest_permission_filter',
];
