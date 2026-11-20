<?php
/** Native admin-dashboard reads, separate from privacy-suppressed scoped V1 analytics. */
namespace DirectoristAnalytics\App\MCP;

use Directorist\MCP\Support\Validation;
use DirectoristAnalytics\App\Repositories\AdminRepository;
use DirectoristAnalytics\App\Repositories\OverviewRepository;
use DirectoristAnalytics\App\Repositories\AuthorRepository;
use DirectoristAnalytics\App\Repositories\ExportRepository;
use DirectoristAnalytics\App\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

final class DashboardService {
    const RESULT_BUDGET = 20000;
    const NOTE          = 'Native administrator dashboard values, including its zero-filled charts and unsuppressed aggregate shares. Historical collection coverage is unknown. Global country/technology/search-engine/referral tables have no listing attribution. No visitor identifiers, raw referring URLs, contact contents, file generation or deletion.';

    public static function permission(): bool {
        return Integration::permission() && current_user_can( 'manage_options' );
    }

    public static function validate( string $action, array $args ): void {
        Validation::check( self::permission(), 'Native Analytics dashboard access requires manage_options.', 'forbidden' );
        Validation::unwrap( rest_validate_value_from_schema( $args ?: (object) [], DashboardSchema::input( $action ) ) );
        if ( 'get-dashboard-schema' === $action ) {
            return;
        }
        if ( 'get-dashboard-section' === $action ) {
            $tab     = $args['tab'];
            $section = $args['section'];
            Validation::check( in_array( $section, DashboardSchema::SECTIONS[$tab], true ), 'This section does not belong to the selected dashboard tab.' );
            Validation::check( ! isset( $args['listing_ids'] ) || 'search-chart' === $section && count( $args['listing_ids'] ) > 0 && count( array_unique( $args['listing_ids'] ) ) === count( $args['listing_ids'] ), 'Unique listing_ids are supported only for search-chart.' );
            Validation::check( 'search-chart' !== $section || ( $args['limit'] ?? 5 ) <= 10, 'Search charts support at most ten listings.' );
            $sortable = 'authors' === $tab ? [ 'name', 'listingsCount', 'registrationDate', 'lastAccess', 'accessFrequency' ] : ( 'listings-table' === $section ? [ 'name', 'views', 'clicks', 'searches' ] : [] );
            Validation::check( ! isset( $args['orderby'] ) || in_array( $args['orderby'], $sortable, true ), 'Unsupported sort field for this section.' );
            Validation::check( ! isset( $args['order'] ) || $sortable, 'Sorting is not supported for this section.' );
            Validation::check( ! isset( $args['limit'] ) || in_array( $section, [ 'top-listings', 'search-chart', 'contact-form-usage', 'top-countries', 'top-search-engines', 'top-referral-sources' ], true ), 'Use pagination for this section, not a chart limit.' );
            if ( in_array( $tab, [ 'settings', 'reports' ], true ) ) {
                Validation::check( ! isset( $args['start_date'] ) && ! isset( $args['end_date'] ) && ! isset( $args['timezone'] ), 'This section is current configuration and does not accept dates.' );
            }
        } else {
            Validation::check( count( $args['metrics'] ) > 0 && count( array_unique( $args['metrics'] ) ) === count( $args['metrics'] ), 'Select at least one unique export metric.' );
            if ( 'site-wide' !== $args['export_scope'] ) {
                Validation::check( ! array_diff( $args['metrics'], array_slice( DashboardSchema::METRICS, 0, 5 ) ), 'Native per-listing and per-author exports support only the five listing engagement metrics; traffic and technology exports are site-wide.' );
            }
        }
        Validation::check( ! isset( $args['timezone'] ) || wp_timezone_string() === $args['timezone'], 'Only the native site timezone is supported.', 'unsupported_operation' );
        $dates_required = 'get-export-data' === $action || ! in_array( $args['tab'] ?? '', [ 'authors', 'settings', 'reports' ], true );
        if ( $dates_required || isset( $args['start_date'] ) || isset( $args['end_date'] ) ) {
            QueryService::range( $args );
        }
        Validation::check( ( $args['page'] ?? 1 ) === 1 || isset( $args['snapshot'] ), 'Continuation pages require the previous snapshot.' );
        Validation::check( ! isset( $args['snapshot'] ) || preg_match( '/^[a-f0-9]{64}$/D', $args['snapshot'] ), 'Use the snapshot returned by the first page.' );
    }

    public static function descriptor( string $tab, string $section ): array {
        $source  = 'native_daily_stats';
        $meaning = 'Inclusive site-local date window; native UI values and formatting.';
        if ( 'summary' === $section ) {
            $source  = 'native_overview_summary';
            $meaning = 'Published listings created and approved reviews submitted in the period; website clicks in the period. Active Authors is the lifetime count of listing owners, as in the UI.';
        } elseif ( 'activities' === $section ) {
            $source  = 'native_analytics_activities';
            $meaning = 'Activity creation timestamps within the inclusive site-local date window.';
        } elseif ( 'traffic' === $tab ) {
            $source  = 'native_global_' . ( false !== strpos( $section, 'countries' ) ? 'country' : ( false !== strpos( $section, 'search-engines' ) ? 'search_engine' : 'referral' ) ) . '_stats_daily';
            $meaning = 'Site-wide native daily dimension counters and native visitor/share labels, not distinct people across the requested period.';
        } elseif ( 'tech-stats' === $tab ) {
            $source  = 'native_global_tech_stats_daily';
            $meaning = 'Native top 15 technology groups and their shares for the requested period.';
        } elseif ( 'authors' === $tab ) {
            $source  = 'native_author_statistics';
            $meaning = 'Optional dates filter author registration dates. Listing counts and last access/access frequency are current/lifetime values, not activity in the date window.';
        } elseif ( in_array( $tab, [ 'settings', 'reports' ], true ) ) {
            $source  = 'native_analytics_configuration';
            $meaning = 'Current configuration; no date filter. Retention changes use the shared Directorist settings tools.';
        } elseif ( 'search-table' === $section ) {
            $meaning = 'Native monthly columns within the selected date window; the native UI combines the same month across years.';
        }
        return [ 'tab'        => $tab,
            'section'         => $section,
            'source'          => $source,
            'dates_required'  => ! in_array( $tab, [ 'authors', 'settings', 'reports' ], true ),
            'range_semantics' => $meaning
        ];
    }

    private static function schema(): array {
        $sections = [];
        foreach ( DashboardSchema::SECTIONS as $tab => $names ) {
            foreach ( $names as $section ) {
                $sections[] = self::descriptor( $tab, $section );
            }
        }
        return [
            'contract_version'  => 'analytics-dashboard-v1',
            'scope'             => 'site',
            'capability'        => 'manage_options',
            'timezone'          => wp_timezone_string(),
            'sections'          => $sections,
            'export_scopes'     => DashboardSchema::EXPORT_SCOPES,
            'export_metrics'    => DashboardSchema::METRICS,
            'intervals'         => DashboardSchema::INTERVALS,
            'max_days'          => 366,
            'max_per_page'      => 100,
            'source_row_budget' => QueryService::SOURCE_ROW_BUDGET,
            'result_row_budget' => self::RESULT_BUDGET,
            'notes'             => [ self::NOTE, 'Existing seven scoped tools keep their V1 privacy and ownership semantics. These additional tools mirror the administrator UI and reject own/directory filters.', 'Charts are flattened into dated series/value rows; all detailed tables use snapshot pagination.', 'Per-listing and per-author exports support the first five engagement metrics only, matching the native export repository. Export data is returned as rows for CSV/PDF consumers; files are not created.', 'Settings include configured and effective privacy values. Delete raw logs now is an action, not a data source, and is not exposed.' ],
        ];
    }

    /** Refuse oversized native sources before invoking unbounded native repository readers. */
    private static function budget( string $table, string $where = '1=1' ): void {
        global $wpdb;
        $limit = QueryService::SOURCE_ROW_BUDGET + 1;
        // Table names and predicates are internal; values are prepared by callers.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal tables, prepared predicates and integer budget; no user-selected identifiers.
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table} WHERE {$where} LIMIT {$limit}) native_budget" );
        Validation::check( ! $wpdb->last_error && null !== $count, 'The native dashboard source is unavailable.', 'source_unavailable' );
        Validation::check( (int) $count <= QueryService::SOURCE_ROW_BUDGET, 'Narrow the dates; the native dashboard source exceeds the 200000-record budget.', 'unsupported_operation' );
    }

    private static function preflight( array $args ): void {
        global $wpdb;
        $tab        = $args['tab'] ?? 'reports';
        $section    = $args['section'] ?? 'export';
        $date_where = isset( $args['start_date'] ) ? $wpdb->prepare( 'stat_date BETWEEN %s AND %s', $args['start_date'], $args['end_date'] ) : '1=1';
        $tables     = [];
        if ( 'overview' === $tab && 'activities' === $section ) {
            self::budget( $wpdb->prefix . 'directorist_analytics_activities', $wpdb->prepare( 'created_at BETWEEN %s AND %s', $args['start_date'] . ' 00:00:00', $args['end_date'] . ' 23:59:59' ) );
            return;
        }
        if ( 'traffic' === $tab ) {
            $tables[] = false !== strpos( $section, 'countries' ) ? 'country_stats_daily' : ( false !== strpos( $section, 'search-engines' ) ? 'search_engine_stats_daily' : 'referral_stats_daily' );
        } elseif ( 'tech-stats' === $tab ) {
            $tables[] = 'tech_stats_daily';
        } elseif ( 'reports' === $tab && 'export' === $section ) {
            $metrics = $args['metrics'];
            if ( array_intersect( array_slice( DashboardSchema::METRICS, 0, 5 ), $metrics ) ) {
                $tables[] = 'daily_stats'; }
            if ( in_array( 'topCountries', $metrics, true ) ) {
                $tables[] = 'country_stats_daily'; }
            if ( array_intersect( [ 'browserType', 'operatingSystems', 'screenResolutions' ], $metrics ) ) {
                $tables[] = 'tech_stats_daily'; }
            if ( in_array( 'searchEngines', $metrics, true ) ) {
                $tables[] = 'search_engine_stats_daily'; }
            if ( in_array( 'referrals', $metrics, true ) ) {
                $tables[] = 'referral_stats_daily'; }
        } elseif ( in_array( $tab, [ 'overview', 'listing-performance' ], true ) ) {
            $tables[] = 'daily_stats';
        }
        foreach ( $tables as $suffix ) {
            self::budget( $wpdb->prefix . 'directorist_analytics_' . $suffix, $date_where );
        }
        if ( in_array( $tab, [ 'overview', 'listing-performance', 'authors' ], true ) || isset( $args['export_scope'] ) && 'site-wide' !== $args['export_scope'] ) {
            self::budget( $wpdb->posts, $wpdb->prepare( 'post_type=%s', ATBDP_POST_TYPE ) );
        }
        if ( 'authors' === $tab ) {
            self::budget( $wpdb->users ); }
        if ( 'summary' === $section ) {
            self::budget( $wpdb->comments, $wpdb->prepare( 'comment_date BETWEEN %s AND %s', $args['start_date'] . ' 00:00:00', $args['end_date'] . ' 23:59:59' ) ); }
    }

    private static function settings(): array {
        $rows = [];
        foreach ( array_intersect_key( SettingsService::configured(), SettingsService::defaults() ) + Settings::policy() as $key => $value ) {
            $rows[] = [ 'key' => $key, is_bool( $value ) ? 'boolean_value' : ( is_int( $value ) ? 'integer_value' : 'text_value' ) => $value ];
        }
        return $rows;
    }

    private static function section( array $args ): array {
        $admin    = new AdminRepository();
        $overview = new OverviewRepository();
        $start    = $args['start_date'] ?? null;
        $end      = $args['end_date'] ?? null;
        $section  = $args['section'];
        $limit    = $args['limit'] ?? ( 'top-search-engines' === $section ? 4 : ( 'search-chart' === $section || 'contact-form-usage' === $section ? 5 : 10 ) );
        $all      = self::RESULT_BUDGET + 1;
        switch ( $section ) {
            case 'summary': return [ $overview->get_overview_summary( $start, $end ) ];
            case 'activities': return $overview->get_recent_activities( $all, $start, $end );
            case 'trends':
                $chart = $overview->get_global_trends_chart( $start, $end );
                return self::chart_rows( $chart['series'], $start );
            case 'top-listings': return $admin->get_top_listings( 'views', $limit, $start, $end );
            case 'listings-table': return $admin->get_listings_table( 1, $all, $args['orderby'] ?? 'views', $args['order'] ?? 'DESC', $start, $end )['data'];
            case 'contact-form-table': return $admin->get_contact_form_usage_table( 1, $all, $start, $end )['data'];
            case 'contact-form-usage':
                $chart = $admin->get_contact_form_usage_chart( $limit, $start, $end );
                $rows  = [];
                foreach ( $chart['categories'] ?? [] as $i => $name ) {
                    $rows[] = [ 'name' => $name,
                        'contactUses'  => $chart['series'][0]['data'][$i]
                    ]; }
                return $rows;
            case 'search-table': return $admin->get_search_appearances_table( 1, $all, $start, $end )['data'];
            case 'search-chart':
                $ids = $args['listing_ids'] ?? $admin->get_top_listing_ids_by_search( $limit, $start, $end );
                // Explicit IDs are checked even for administrators before entering the native chart reader.
                foreach ( $ids as $id ) {
                    QueryService::listings(
                        [ 'scope'        => 'site',
                            'listing_id' => $id
                        ]
                    ); }
                return self::chart_rows( $admin->get_search_appearances_chart( $ids, $start, $end ), $start, $ids );
            case 'top-countries': return $admin->get_top_countries_global( $limit, $start, $end );
            case 'countries-table': return $admin->get_countries_table( 1, $all, $start, $end )['data'];
            case 'top-search-engines': return $admin->get_top_search_engines( $limit, $start, $end );
            case 'top-referral-sources': return $admin->get_top_referral_sources( $limit, $start, $end );
            case 'referral-sources-table': return $admin->get_referral_sources_table( 1, $all, $start, $end )['data'];
            case 'browsers': case 'operating-systems': case 'screen-resolutions': case 'devices':
                            $type = [ 'browsers'     => 'browsers',
                                'operating-systems'  => 'os',
                                'screen-resolutions' => 'resolutions',
                                'devices'            => 'devices'
                            ][$section];
                return $admin->get_global_tech_stats( $type, $start, $end );
            case 'list': return ( new AuthorRepository() )->get_authors_list( 1, $all, $args['orderby'] ?? 'accessFrequency', $args['order'] ?? 'DESC', $start, $end );
            case 'privacy': return self::settings();
            case 'options':
                $rows = [];
                foreach ( [ 'export_scope' => DashboardSchema::EXPORT_SCOPES,
                    'metric'               => DashboardSchema::METRICS,
                    'interval'             => DashboardSchema::INTERVALS
                ] as $key => $values ) {
                    foreach ( $values as $value ) {
                        $rows[] = [ 'key' => $key,
                            'name'        => $value
                        ]; }
                }
                return $rows;
        }
        return [];
    }

    /** Flatten long charts so a 366-day chart never violates the 100-item contract. */
    private static function chart_rows( array $series, string $start, array $ids = [] ): array {
        $rows = [];
        foreach ( $series as $index => $line ) {
            foreach ( $line['data'] as $day => $value ) {
                $row = [ 'series' => $line['name'],
                    'date'        => ( new \DateTimeImmutable( $start, wp_timezone() ) )->modify( '+' . $day . ' days' )->format( 'Y-m-d' ),
                    'value'       => (int) $value
                ];
                if ( isset( $ids[$index] ) ) {
                    $row['listing_id'] = (int) $ids[$index]; }
                self::append( $rows, $row );
            }
        }
        return $rows;
    }

    private static function append( array &$rows, array $row ): void {
        Validation::check( count( $rows ) < self::RESULT_BUDGET, 'Narrow the dates or export metrics; the dashboard result exceeds 20000 rows.', 'unsupported_operation' );
        $rows[] = $row;
    }

    private static function export( array $args ): array {
        $repo   = new ExportRepository();
        $method = [ 'site-wide' => 'get_site_wide_data',
            'per-listing'       => 'get_per_listing_data',
            'per-author'        => 'get_per_author_data'
        ][$args['export_scope']];
        $data   = $repo->$method( $args['start_date'], $args['end_date'], $args['interval'] ?? 'daily', $args['metrics'] );
        $rows   = [];
        if ( 'site-wide' === $args['export_scope'] ) {
            $data = [ [ 'metrics' => $data ] ];
        }
        foreach ( $data as $entity ) {
            $identity = array_intersect_key( $entity, array_flip( [ 'id', 'name', 'author_id', 'author_name', 'email', 'listing_count' ] ) );
            foreach ( $entity['metrics'] as $metric => $points ) {
                foreach ( $points as $point ) {
                    self::append( $rows, [ 'metric' => $metric ] + $point + $identity ); }
            }
        }
        return $rows;
    }

    /** Project known fields and omit native null values. */
    private static function project( array $rows ): array {
        $fields = DashboardSchema::row_fields();
        $out    = [];
        foreach ( $rows as $row ) {
            $item = [];
            foreach ( array_intersect_key( $row, $fields ) as $key => $value ) {
                if ( null === $value ) {
                    continue; }
                $type       = $fields[$key]['type'];
                $item[$key] = 'integer' === $type ? (int) $value : ( 'number' === $type ? (float) $value : ( 'boolean' === $type ? (bool) $value : (string) $value ) );
            }
            // Relative activity text changes with the clock; derive it after snapshot validation.
            unset( $item['time'] );
            $out[] = $item;
        }
        return $out;
    }

    /** Stable secondary ordering without changing native metric or Other-group semantics. */
    private static function stable_rows( array $rows, array $args ): array {
        $section = $args['section'] ?? 'export';
        $metric  = [ 'activities'    => 'date',
            'top-listings'           => 'views',
            'listings-table'         => $args['orderby'] ?? 'views',
            'contact-form-table'     => 'contactUses',
            'contact-form-usage'     => 'contactUses',
            'top-countries'          => 'visitors',
            'countries-table'        => 'visitors',
            'top-referral-sources'   => 'visitors',
            'referral-sources-table' => 'visitors',
            'top-search-engines'     => 'visitors',
            'browsers'               => 'visits',
            'operating-systems'      => 'visits',
            'screen-resolutions'     => 'visits',
            'devices'                => 'visits',
            'list'                   => $args['orderby'] ?? 'accessFrequency'
        ][$section] ?? null;
        if ( null === $metric && 'export' !== $section ) {
            return $rows; }
        $tie = static function ( $a, $b ) use ( $section ) {
            if ( 'top-search-engines' === $section && ( 'Other' === ( $a['name'] ?? '' ) || 'Other' === ( $b['name'] ?? '' ) ) ) {
                return ( 'Other' === ( $a['name'] ?? '' ) ) <=> ( 'Other' === ( $b['name'] ?? '' ) );
            }
            return ( $a['listing_id'] ?? $a['id'] ?? 0 ) <=> ( $b['listing_id'] ?? $b['id'] ?? 0 ) ?: strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
        };
        if ( 'export' === $section ) {
            usort(
                $rows, static function ( $a, $b ) {
                    return ( $a['id'] ?? 0 ) <=> ( $b['id'] ?? 0 ) ?: strcmp( $a['metric'], $b['metric'] ) ?: strcmp( $a['period'], $b['period'] ) ?: strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
                }
            );
            return $rows;
        }
        // Keep native database collation and primary ordering. Sort only equal-value runs.
        $out      = [];
        $group    = [];
        $previous = null;
        foreach ( $rows as $row ) {
            $value = $row[$metric] ?? null;
            if ( $group && $value !== $previous ) {
                usort( $group, $tie );
                $out   = array_merge( $out, $group );
                $group = [];
            }
            $group[]  = $row;
            $previous = $value;
        }
        usort( $group, $tie );
        return array_merge( $out, $group );
    }

    /** Catch any native SELECT failure, including one followed by a successful query. */
    public static function read( string $action, array $args ): array {
        global $wpdb;
        self::validate( $action, $args );
        $old_errors       = $wpdb->suppress_errors( true );
        $wpdb->last_error = '';
        $check_error      = static function ( $sql ) use ( $wpdb ) {
            Validation::check( ! $wpdb->last_error, 'The native dashboard source is unavailable.', 'source_unavailable' );
            return $sql;
        };
        add_filter( 'query', $check_error, PHP_INT_MIN );
        try {
            return self::read_data( $action, $args );
        } finally {
            remove_filter( 'query', $check_error, PHP_INT_MIN );
            $wpdb->suppress_errors( $old_errors );
        }
    }

    private static function read_data( string $action, array $args ): array {
        global $wpdb;
        self::validate( $action, $args );
        if ( 'get-dashboard-schema' === $action ) {
            return self::schema(); }
        self::preflight( $args );
        $rows = self::stable_rows( self::project( 'get-export-data' === $action ? self::export( $args ) : self::section( $args ) ), $args );
        Validation::check( ! $wpdb->last_error, 'The native dashboard source is unavailable.', 'source_unavailable' );
        Validation::check( count( $rows ) <= self::RESULT_BUDGET, 'Narrow the dates or export metrics; the dashboard result exceeds 20000 rows.', 'unsupported_operation' );
        $descriptor = 'get-export-data' === $action ? [ 'source' => 'native_export_repository',
            'range_semantics'                                    => 'Native export interval grouping; site-wide traffic/technology counters have no listing attribution. Per-listing and per-author engagement rows use the native published-listing joins.'
        ] : self::descriptor( $args['tab'], $args['section'] );
        $columns    = [];
        foreach ( $rows as $row ) {
            $columns = array_merge( $columns, array_keys( $row ) ); }
        $result = QueryService::page( $rows, $args, 'native-dashboard-' . $action ) + [
            'columns'  => array_values( array_unique( $columns ) ),
            'metadata' => [ 'scope' => 'site',
                'source'            => $descriptor['source'],
                'range_semantics'   => $descriptor['range_semantics'],
                'timezone'          => wp_timezone_string(),
                'observed_at'       => gmdate( DATE_ATOM ),
                'coverage'          => 'unknown',
                'note'              => self::NOTE
            ],
        ];
        foreach ( [ 'start_date', 'end_date' ] as $key ) {
            if ( isset( $args[$key] ) ) {
                $result['metadata'][$key] = $args[$key]; } }
        if ( 'get-export-data' === $action ) {
            $result += [ 'export_scope' => $args['export_scope'],
                'interval'              => $args['interval'] ?? 'daily'
            ];
        } else {
            $result += [ 'tab' => $args['tab'],
                'section'      => $args['section']
            ];
            if ( 'activities' === $args['section'] ) {
                foreach ( $result['items'] as &$item ) {
                    $item['time'] = sprintf( __( '%s ago', 'directorist-analytics' ), human_time_diff( strtotime( $item['date'] ), current_time( 'timestamp' ) ) ); }
                unset( $item );
                if ( $rows ) {
                    $result['columns'][] = 'time'; }
            }
        }
        return $result;
    }
}
