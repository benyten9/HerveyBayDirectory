<?php

/** Analytics contributes to the existing Directorist MCP registry and dashboard. */
namespace DirectoristAnalytics\App\MCP;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Safety\Read_Guard;
use Directorist\MCP\Support\Validation;
defined( 'ABSPATH' ) || exit;
final class Integration
{
    const CONTRACT = 'analytics-v1.0';
    const ACTIONS  = [
        'get-schema',
        'get-overview',
        'get-series',
        'get-listing-metrics',
        'get-breakdown',
        'list-top-listings',
        'get-report',
        'get-dashboard-schema',
        'get-dashboard-section',
        'get-export-data',
    ];

    public static function boot() : void {
        add_action( 'directorist_mcp_register', [self::class, 'register'] );
    }

    public static function available() : bool {
        return defined( 'ATBDP_VERSION' ) && class_exists( '\\DirectoristAnalytics\\App\\Services\\PrivacyService' );
    }

    public static function permission() : bool {
        return self::available() && QueryService::admitted();
    }

    public static function register( $registry ) : void {
        $registered = $registry->register_extension(
            [
                'owner'            => 'analytics',
                'plugin'           => 'directorist-analytics/directorist-analytics.php',
                'label'            => 'Directorist Analytics',
                'version'          => '1.0.0',
                'contract_version' => self::CONTRACT,
                'api_version'      => 1,
                'requires_core'    => '8.8.0',
                'capability'       => 'read',
                'availability'     => [self::class, 'available'],
                'admission'        => static function ( $user ) {
                    return $user === get_current_user_id() && QueryService::admitted();
                },
            ]
        );
        if ( is_wp_error( $registered ) ) {
            return;
        }
        $descriptions = [
            'get-dashboard-schema' => 'Discover all native Analytics administrator dashboard tabs, sections, export metrics, intervals and source semantics. Requires manage_options.',
            'get-dashboard-section' => 'Read a native Analytics administrator dashboard section: Overview summary/trends/activities, Listing Performance charts and full tables, Traffic country shares/search engines/referral domains, Tech Stats browsers/OS/resolutions/devices, Authors access statistics, report options or privacy settings. Site-wide native UI sources; requires manage_options. Paginate with snapshot.',
            'get-export-data' => 'Read all native Analytics export metrics as structured rows, with site-wide/per-listing/per-author scopes and daily/weekly/monthly/quarterly intervals. Requires manage_options. Does not generate files or delete logs. Paginate with snapshot.',
            'get-schema'          => 'Discover scoped analytics definitions, supported metric/dimension combinations, site timezone, pagination, coverage and privacy limits.',
            'get-overview'        => 'Read native period aggregates for permitted listings and honestly labelled prior-window availability.',
            'get-series'          => 'Read daily native metric buckets, at most 100 per page and 366 inclusive site-local dates. Unknown buckets omit value; continue with snapshot.',
            'get-listing-metrics' => 'Read a permitted listing’s period aggregates and source coverage without lifetime-counter substitution.',
            'get-breakdown'       => 'Read safe channel, coarse country, device or click-type groups. Small and complementary cells are withheld; totals describe only released groups.',
            'list-top-listings'   => 'Rank permitted listings by a native period metric, with stable ID ties and snapshot continuation.',
            'get-report'          => 'Read versioned structured listing-performance or traffic-breakdown aggregate rows, allowed columns and snapshot continuation; never creates a file.',
        ];
        foreach ( self::ACTIONS as $action ) {
            $native_dashboard = in_array( $action, DashboardSchema::ACTIONS, true );
            $registry->register_tool(
                [
                    'owner'            => 'analytics',
                    'action'           => $action,
                    'domain'           => 'extension',
                    'group'            => 'analytics',
                    'description'      => $descriptions[$action],
                    'contract_version' => $native_dashboard ? 'analytics-dashboard-v1' : self::CONTRACT,
                    'write'            => false,
                    'schema'           => Schema::input( $action ),
                    'output_schema'    => Schema::output( $action ),
                    'discover'         => $native_dashboard ? [DashboardService::class, 'permission'] : [self::class, 'permission'],
                    'permission'       => static function ( $args ) use( $action ) {
                        QueryService::validate( $action, $args );
                        return self::permission();
                    },
                    'read'             => static function ( $args ) use( $action ) {
                        return self::read( $action, $args );
                    },
                    'project'          => static function ( $value, $args ) use( $action, $native_dashboard ) {
                        Access_Control::connected();
                        Validation::check( ! $native_dashboard || DashboardService::permission(), 'Native Analytics dashboard access requires manage_options.', 'forbidden' );
                        Validation::check( self::permission() && Tool_Registry::instance()->admitted( 'analytics' ), 'Analytics is unavailable.', 'extension_unavailable' );
                        Validation::unwrap( rest_validate_value_from_schema( $value, Schema::output( $action ) ) );
                        return $value;
                    },
                ]
            );
        }
        Settings::register( $registry );
        $registry->register_read_adapter(
            [
                'owner'            => 'analytics',
                'key'              => 'engagement',
                'domain'           => 'dashboard',
                'api_version'      => 1,
                'label'            => 'Native analytics engagement',
                'contract_version' => self::CONTRACT,
                'source_version'   => 'daily-stats-v1',
                'units'            => 'events',
                'range_semantics'  => 'period',
                'sensitivity'      => 'public',
                'cache'            => 'none',
                'kind'             => 'overview',
                'scopes'           => ['site', 'own'],
                'filters'          => [
                    'directory_id',
                    'period',
                    'start_date',
                    'end_date',
                    'compare_previous',
                ],
                'output_schema'    => self::dashboard_schema(),
                'permission'       => [self::class, 'permission'],
                'availability'     => [self::class, 'available'],
                'read'             => [self::class, 'dashboard'],
                'project'          => static function ( $value ) {
                    Validation::unwrap( rest_validate_value_from_schema( $value, self::dashboard_schema() ) );
                    return $value;
                },
            ]
        );
    }

    public static function read( string $action, array $args ) : array {
        Access_Control::connected();
        Validation::check( self::permission() && Tool_Registry::instance()->admitted( 'analytics' ), 'Analytics is unavailable.', 'extension_unavailable' );
        // Apply the stronger no-HTTP guard also to direct callback invocation.
        return Read_Guard::run(
            static function () use( $action, $args ) {
                return QueryService::read( $action, $args );
            }, false, true
        );
    }

    public static function dashboard_schema() : array {
        return Schema::object(
            [
                'views'                   => Schema::integer(),
                'unique_views'            => Schema::integer(),
                'clicks_total'            => Schema::integer(),
                'search_appearances'      => Schema::integer(),
                'scope'                   => Schema::text( 10 ),
                'timezone'                => Schema::text( 80 ),
                'start_date'              => Schema::text( 10 ),
                'end_date'                => Schema::text( 10 ),
                'coverage'                => Schema::text( 30 ),
                'availability'            => Schema::text( 20 ),
                'comparison_availability' => Schema::text( 30 ),
                'definition'              => Schema::text( 500 ),
            ]
        );
    }

    public static function dashboard( array $args ) : array {
        Access_Control::connected();
        Validation::check( self::permission() && Tool_Registry::instance()->admitted( 'analytics' ), 'Analytics is unavailable.', 'extension_unavailable' );
        $range = \Directorist\MCP\Tool_Providers\Dashboard\Read_Callbacks::range( $args );
        $input = [
            'scope'      => $args['scope'],
            'start_date' => $range['start_date'],
            'end_date'   => $range['end_date'],
        ];
        if ( isset( $args['directory_id'] ) ) {
            $input['directory_id'] = $args['directory_id'];
        }
        $value = self::read( 'get-overview', $input );
        return $value['totals'] + array_intersect_key(
            $value['metadata'], array_flip(
                [
                    'scope',
                    'timezone',
                    'start_date',
                    'end_date',
                    'coverage',
                    'availability',
                ]
            )
        ) + ['comparison_availability' => 'unavailable', 'definition' => 'Native daily listing event sums. Unique views are summed daily increments, not distinct people over the period. Collection history is unknown. No visitor dimensions or identifying cells are contributed.'];
    }
}
