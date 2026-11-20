<?php
/** Register before the core seals the inventory at plugins_loaded. */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__ ) . '/Services/PrivacyService.php';
require_once dirname( __DIR__ ) . '/Services/SettingsService.php';
require_once dirname( __DIR__ ) . '/Services/MetricDefinitions.php';
foreach ( [ 'Schema', 'Settings', 'QueryService', 'DashboardSchema', 'DashboardService', 'Integration' ] as $directorist_analytics_mcp_class ) {
    require_once __DIR__ . '/' . $directorist_analytics_mcp_class . '.php';
}
unset( $directorist_analytics_mcp_class );
\DirectoristAnalytics\App\MCP\Integration::boot();
