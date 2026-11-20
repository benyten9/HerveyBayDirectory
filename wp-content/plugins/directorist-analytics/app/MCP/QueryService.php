<?php

/** Scoped native aggregate queries. No tracking, cleanup, exports or remote sources. */
namespace DirectoristAnalytics\App\MCP;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;
defined( 'ABSPATH' ) || exit;
final class QueryService
{
    const LISTING_BUDGET    = 20000;
    const SOURCE_ROW_BUDGET = 200000;
    const COVERAGE_NOTE     = 'Native tracking has no collection-history ledger. Historical coverage, disabled intervals and timezone changes are unknown; missing rows do not prove zero events. Raw breakdowns are additionally limited by retention and native privacy exclusions.';

    public static function admitted() : bool {
        $type = get_post_type_object( ATBDP_POST_TYPE );
        return get_current_user_id() > 0 && ( current_user_can( 'manage_options' ) || $type && current_user_can( $type->cap->edit_posts ) );
    }

    public static function scope( array $args ) : string {
        Validation::check( self::admitted(), 'Analytics access is required.', 'forbidden' );
        $scope = $args['scope'] ?? ( current_user_can( 'manage_options' ) ? 'site' : 'own' );
        Validation::check( in_array( $scope, ['site', 'own'], true ), 'Unsupported analytics scope.' );
        Validation::check( 'own' === $scope || current_user_can( 'manage_options' ), 'Site analytics requires configuration access.', 'forbidden' );
        return $scope;
    }

    public static function range( array $args ) : array {
        Validation::check( ! isset( $args['timezone'] ) || wp_timezone_string() === $args['timezone'], 'Only the native site timezone is supported; daily statistics cannot be rebucketed.', 'unsupported_operation' );
        $dates = [];
        foreach ( ['start_date', 'end_date'] as $key ) {
            $value = $args[$key] ?? '';
            Validation::check( is_string( $value ) && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ), 'Use inclusive YYYY-MM-DD dates.' );
            $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
            Validation::check( $date && $date->format( 'Y-m-d' ) === $value && $date->format( 'Y' ) >= '1970', 'Use a valid local calendar date.' );
            $dates[$key] = $date;
        }
        $days = (int) $dates['start_date']->diff( $dates['end_date'] )->days + 1;
        Validation::check( $dates['start_date'] <= $dates['end_date'] && $days <= 366 && $dates['end_date'] <= current_datetime()->setTime( 0, 0 ), 'Use an ordered window of at most 366 dates ending no later than today.' );
        return $dates + ['days' => $days];
    }

    /** Validate semantic combinations before any analytics query. */
    public static function validate( string $action, array $args ) : void {
        if ( in_array( $action, DashboardSchema::ACTIONS, true ) ) { DashboardService::validate( $action, $args ); return; }
        Validation::unwrap( rest_validate_value_from_schema( $args ?: (object) [], Schema::input( $action ) ) );
        self::scope( $args );
        if ( 'get-schema' === $action ) {
            return;
        }
        self::range( $args );
        $traffic = 'get-breakdown' === $action || 'get-report' === $action && 'traffic-breakdown-v1' === $args['report_type'];
        if ( $traffic ) {
            Validation::check( isset( $args['dimension'], $args['metric'] ), 'Select a supported dimension and metric.' );
            Validation::check( ( 'click_type' === $args['dimension'] ? 'clicks_total' : 'views' ) === $args['metric'], 'This dimension does not support the selected metric.', 'unsupported_operation' );
            Validation::check( ! isset( $args['sort_metric'] ), 'Traffic reports use count descending and dimension key ordering.' );
        } elseif ( 'get-report' === $action ) {
            Validation::check( ! isset( $args['dimension'] ) && ! isset( $args['metric'] ), 'Listing reports do not accept a traffic dimension or metric.' );
        }
        if ( 'get-report' === $action && isset( $args['columns'] ) ) {
            $allowed = $traffic ? ['key', 'value'] : array_merge( ['listing_id', 'title'], array_keys( Schema::metrics() ) );
            Validation::check( count( $args['columns'] ) > 0 && count( array_unique( $args['columns'] ) ) === count( $args['columns'] ) && ! array_diff( $args['columns'], $allowed ), 'Select unique columns supported by this report type.' );
        }
        Validation::check( ( $args['page'] ?? 1 ) === 1 || isset( $args['snapshot'] ) && preg_match( '/^[a-f0-9]{64}$/D', $args['snapshot'] ), 'Continuation pages require the previous snapshot.' );
    }

    private static function query( string $sql ) : array {
        global $wpdb;
        $old = $wpdb->suppress_errors( true );
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers construct fixed local SELECTs and prepare their values.
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            Validation::check( ! $wpdb->last_error && is_array( $rows ), 'The native analytics source is unavailable.', 'source_unavailable' );
            return $rows;
        } finally {
            $wpdb->suppress_errors( $old );
        }
    }

    /** Object predicates run before aggregates, ranking, pagination or totals. */
    public static function listings( array $args ) : array {
        global $wpdb;
        $scope = self::scope( $args );
        $where = $wpdb->prepare( "p.post_type=%s AND p.post_status IN ('publish','private','pending','draft','expired','rejected')", ATBDP_POST_TYPE );
        if ( 'own' === $scope ) {
            $where .= $wpdb->prepare( ' AND p.post_author=%d', get_current_user_id() );
        }
        if ( isset( $args['directory_id'] ) ) {
            Validation::directory( $args['directory_id'] );
            $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=p.ID AND t.taxonomy=%s AND t.term_id=%d)", ATBDP_DIRECTORY_TYPE, $args['directory_id'] );
        }
        if ( isset( $args['listing_id'] ) ) {
            $where .= $wpdb->prepare( ' AND p.ID=%d', $args['listing_id'] );
        }
        $out     = [];
        $cursor  = 0;
        $scanned = 0;
        do {
            $rows     = self::query( "SELECT p.* FROM {$wpdb->posts} p WHERE {$where} AND p.ID>" . (int) $cursor . ' ORDER BY p.ID ASC LIMIT 128' );
            $scanned += count( $rows );
            Validation::check( $scanned <= self::LISTING_BUDGET, 'Narrow the directory; the listing permission scan exceeds 20000 records.', 'unsupported_operation' );
            foreach ( $rows as $row ) {
                $cursor = (int) $row['ID'];
                if ( ! wp_cache_get( $cursor, 'posts' ) ) {
                    wp_cache_add( $cursor, new \WP_Post( (object) $row ), 'posts' );
                }
                try {
                    $post = Access_Control::listing( $cursor );
                    Validation::check( true === apply_filters( 'directorist_rest_check_permissions', true, 'read', $cursor, ATBDP_POST_TYPE ), 'Listing not found.', 'not_found' );
                    $out[$cursor] = mb_substr( wp_strip_all_tags( $post->post_title ), 0, 200 );
                } catch ( Failure $error ) {
                    // Use the same response for missing and inaccessible direct IDs.
                    if ( isset( $args['listing_id'] ) ) {
                        Validation::check( false, 'Listing not found.', 'not_found' );
                    }
                }
            }
        } while ( count( $rows ) === 128 );
        Validation::check( ! isset( $args['listing_id'] ) || count( $out ) === 1, 'Listing not found.', 'not_found' );
        return $out;
    }

    /** Native daily definitions are shared by overview, series, rankings and reports. */
    private static function daily( array $listings, array $range, string $group ) : array {
        global $wpdb;
        if ( ! $listings ) {
            return [];
        }
        $groups = [
            'day'     => 's.stat_date',
            'listing' => 's.listing_id',
            'total'   => "'total'",
        ];
        $column = $groups[$group];
        $select = [];
        foreach ( array_keys( Schema::metrics() ) as $metric ) {
            $select[] = "COALESCE(SUM(s.{$metric}),0) AS {$metric}";
        }
        $ids = implode( ',', array_map( 'intval', array_keys( $listings ) ) );
        // LIMIT bounds examined source records. An overflow fails the entire aggregate,
        // never a seemingly complete partial total. Native indexes cover listing/date.
        $source_columns = 'listing_id,stat_date,' . implode( ',', array_keys( Schema::metrics() ) );
        $limit          = self::SOURCE_ROW_BUDGET + 1;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal column/expression allowlists and integer-only IDs/limits; dates use placeholders.
        $source = $wpdb->prepare( "SELECT {$source_columns} FROM {$wpdb->prefix}directorist_analytics_daily_stats WHERE listing_id IN ({$ids}) AND stat_date BETWEEN %s AND %s LIMIT {$limit}", $range['start_date']->format( 'Y-m-d' ), $range['end_date']->format( 'Y-m-d' ) );
        $rows   = self::query( "SELECT {$column} AS group_key," . implode( ',', $select ) . ",MAX(s.stat_date) AS latest_date,COUNT(*) AS source_rows FROM ({$source}) s GROUP BY {$column} ORDER BY {$column} ASC" );
        self::check_budget( array_sum( array_column( $rows, 'source_rows' ) ) );
        foreach ( $rows as &$row ) {
            unset( $row['source_rows'] );
            foreach ( array_keys( Schema::metrics() ) as $metric ) {
                $row[$metric] = (int) $row[$metric];
            }
        }
        unset( $row );
        return $rows;
    }

    private static function check_budget( int $count ) : void {
        Validation::check( $count <= self::SOURCE_ROW_BUDGET, 'Narrow the dates, directory or listing; this source exceeds the 200000-record query budget.', 'unsupported_operation' );
    }

    private static function totals( array $rows ) : array {
        $totals = array_fill_keys( array_keys( Schema::metrics() ), 0 );
        foreach ( $rows as $row ) {
            foreach ( $totals as $key => $value ) {
                $totals[$key] += $row[$key];
            }
        }
        return $totals;
    }

    public static function definitions() : array {
        $out = [];
        foreach ( Schema::metrics() as $key => $definition ) {
            $out[] = compact( 'key', 'definition' );
        }
        return $out;
    }

    private static function metadata( array $args, array $range, array $rows, bool $raw = false ) : array {
        $latest = '';
        foreach ( $rows as $row ) {
            $latest = max( $latest, $row['latest_date'] ?? '' );
        }
        return [
            'scope'                => self::scope( $args ),
            'timezone'             => wp_timezone_string(),
            'start_date'           => $range['start_date']->format( 'Y-m-d' ),
            'end_date'             => $range['end_date']->format( 'Y-m-d' ),
            'start'                => $range['start_date']->format( DATE_ATOM ),
            'end_exclusive'        => $range['end_date']->modify( '+1 day' )->format( DATE_ATOM ),
            'observed_at'          => gmdate( DATE_ATOM ),
            'availability'         => $rows ? 'observed' : 'empty',
            'coverage'             => 'unknown',
            'collection_status'    => 'active_with_native_privacy_exclusions',
            'coverage_note'        => self::COVERAGE_NOTE,
            'latest_observed_date' => $raw ? '' : $latest,
            'source'               => $raw ? 'native_retained_view_events' : 'native_daily_stats',
            'retention_days'       => Settings::policy()['effective_retention_days'],
        ];
    }

    private static function schema( array $args ) : array {
        return [
            'scope'                => self::scope( $args ),
            'timezone'             => wp_timezone_string(),
            'metrics'              => self::definitions(),
            'dimensions'           => [
                'channel',
                'country',
                'device',
                'click_type',
            ],
            'filters'              => [
                'scope',
                'directory_id',
                'listing_id',
                'start_date',
                'end_date',
                'timezone',
            ],
            'granularities'        => ['day'],
            'max_days'             => 366,
            'max_buckets'          => 366,
            'default_per_page'     => 20,
            'max_per_page'         => 100,
            'minimum_group_events' => 5,
            'listing_scan_budget'  => self::LISTING_BUDGET,
            'source_row_budget'    => self::SOURCE_ROW_BUDGET,
            'report_types'         => ['listing-performance-v1', 'traffic-breakdown-v1'],
            'coverage'             => self::COVERAGE_NOTE,
            'source_freshness'     => 'Read directly on every call. Native daily statistics update with tracking events; no verified ingestion watermark exists. Reports expose the latest observed aggregate date, not complete collection coverage.',
            'observed_at'          => gmdate( DATE_ATOM ),
            'collection_status'    => 'active_with_native_privacy_exclusions',
            'privacy'              => [
                'Aggregate listing measures only; no visitor identifiers, contact contents, raw search phrases or referring URLs.',
                'Traffic dimensions support views; click_type supports clicks_total. Native global dimension tables are never substituted for a scoped source.',
                'Cells below five events and a complementary cell are withheld. Breakdown totals and hidden group counts are unavailable.',
                'Daily buckets are paginated at 100 maximum; continuation requires the returned snapshot.',
                'Comparisons are unavailable without verified historical collection coverage; observed prior totals are labelled separately.',
            ],
        ];
    }

    /** HMAC binds the entire authorized aggregate snapshot, actor, page size and filters. */
    public static function page( array $rows, array $args, string $kind ) : array {
        $binding = $args;
        unset( $binding['page'], $binding['snapshot'] );
        ksort( $binding );
        $snapshot = hash_hmac(
            'sha256', wp_json_encode(
                [
                    'analytics-v1',
                    get_current_blog_id(),
                    get_current_user_id(),
                    wp_timezone_string(),
                    $kind,
                    $binding,
                    $rows,
                    Settings::policy(),
                ]
            ), wp_salt( 'auth' )
        );
        Validation::check( ! isset( $args['snapshot'] ) || hash_equals( $snapshot, $args['snapshot'] ), 'Analytics changed during pagination. Restart at page 1 without a snapshot.', 'stale_snapshot' );
        $page     = $args['page'] ?? 1;
        $per_page = $args['per_page'] ?? 20;
        $offset   = ( $page - 1 ) * $per_page;
        return [
            'items'    => array_slice( $rows, $offset, $per_page ),
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => count( $rows ),
            'has_more' => $offset + $per_page < count( $rows ),
            'snapshot' => $snapshot,
        ];
    }

    /** Safe dimensions are projected in SQL, before grouping. Raw identifiers never leave SQL. */
    private static function breakdown( array $args, array $range, array $listings ) : array {
        global $wpdb;
        $dimension = $args['dimension'];
        $rows      = [];
        if ( $listings && 'click_type' === $dimension ) {
            $ids     = implode( ',', array_map( 'intval', array_keys( $listings ) ) );
            $columns = [
                'contact_form',
                'website',
                'phone',
                'email',
            ];
            $select  = implode(
                ',', array_map(
                    static function ( $key ) {
                        return "COALESCE(SUM(clicks_{$key}),0) AS {$key}";
                    }, $columns
                )
            );
            $limit   = self::SOURCE_ROW_BUDGET + 1;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal column/expression allowlists and integer-only IDs/limits; dates use placeholders.
            $source = $wpdb->prepare( "SELECT clicks_contact_form,clicks_website,clicks_phone,clicks_email FROM {$wpdb->prefix}directorist_analytics_daily_stats WHERE listing_id IN ({$ids}) AND stat_date BETWEEN %s AND %s LIMIT {$limit}", $range['start_date']->format( 'Y-m-d' ), $range['end_date']->format( 'Y-m-d' ) );
            $result = self::query( "SELECT {$select},COUNT(*) AS source_rows FROM ({$source}) s" );
            self::check_budget( (int) $result[0]['source_rows'] );
            foreach ( $columns as $key ) {
                if ( (int) $result[0][$key] > 0 ) {
                    $rows[] = ['key' => $key, 'value' => (int) $result[0][$key]];
                }
            }
        } elseif ( $listings ) {
            $ids         = implode( ',', array_map( 'intval', array_keys( $listings ) ) );
            $expressions = [
                'channel' => "CASE WHEN referrer_type IN ('direct','search_engine','social','referral') THEN referrer_type ELSE 'unknown' END",
                'country' => "CASE WHEN UPPER(country_code) REGEXP '^[A-Z]{2}\$' THEN UPPER(country_code) ELSE 'unknown' END",
                'device'  => "CASE WHEN LOWER(device_type) IN ('desktop','mobile','tablet') THEN LOWER(device_type) ELSE 'unknown' END",
            ];
            $expression  = $expressions[$dimension];
            // Native tracking writes site-local wall time, including its privacy rounding.
            // Compare in that same database session representation; never reinterpret as UTC.
            $limit = self::SOURCE_ROW_BUDGET + 1;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal column/expression allowlists and integer-only IDs/limits; dates use placeholders.
            $source = $wpdb->prepare( "SELECT {$expression} AS group_key FROM {$wpdb->prefix}directorist_analytics_views WHERE listing_id IN ({$ids}) AND created_at >= %s AND created_at < %s LIMIT {$limit}", $range['start_date']->format( 'Y-m-d H:i:s' ), $range['end_date']->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) );
            $rows   = self::query( "SELECT group_key AS `key`,COUNT(*) AS value FROM ({$source}) s GROUP BY group_key ORDER BY value DESC,`key` ASC" );
            self::check_budget( array_sum( array_column( $rows, 'value' ) ) );
            foreach ( $rows as &$row ) {
                $row['value'] = (int) $row['value'];
            }
            unset( $row );
        }
        usort(
            $rows, static function ( $a, $b ) {
                return $b['value'] <=> $a['value'] ?: strcmp( $a['key'], $b['key'] );
            }
        );
        $small = array_filter(
            $rows, static function ( $row ) {
                return $row['value'] < 5;
            }
        );
        $safe  = array_values(
            array_filter(
                $rows, static function ( $row ) {
                    return $row['value'] >= 5;
                }
            )
        );
        // Withhold a second cell so other tool totals cannot reveal a lone small cell.
        if ( $small && $safe ) {
            array_shift( $safe );
        }
        $page     = self::page( $rows, $args, 'breakdown-source' );
        $snapshot = $page['snapshot'];
        // Use the full source for snapshot invalidation, but only authorized released groups for paging/totals.
        $page_number = $args['page'] ?? 1;
        $size        = $args['per_page'] ?? 20;
        return [
            'items'       => array_slice( $safe, ( $page_number - 1 ) * $size, $size ),
            'page'        => $page_number,
            'per_page'    => $size,
            'total'       => count( $safe ),
            'has_more'    => $page_number * $size < count( $safe ),
            'snapshot'    => $snapshot,
            'dimension'   => $dimension,
            'metric'      => $args['metric'],
            'suppressed'  => (bool) $small,
            'totals'      => null,
            'metadata'    => self::metadata( $args, $range, $safe, 'click_type' !== $dimension ),
            'definitions' => self::definitions(),
        ];
    }

    public static function read( string $action, array $args ) : array {
        if ( in_array( $action, DashboardSchema::ACTIONS, true ) ) { return DashboardService::read( $action, $args ); }
        self::validate( $action, $args );
        if ( 'get-schema' === $action ) {
            return self::schema( $args );
        }
        // Normalize defaults for snapshot binding across all pages.
        $args['scope']    = self::scope( $args );
        $args['timezone'] = wp_timezone_string();
        $args['per_page'] = $args['per_page'] ?? 20;
        $range            = self::range( $args );
        $listings         = self::listings( $args );
        if ( 'get-breakdown' === $action || 'get-report' === $action && 'traffic-breakdown-v1' === $args['report_type'] ) {
            $result = self::breakdown( $args, $range, $listings );
        } else {
            $group  = 'get-series' === $action ? 'day' : ( in_array( $action, ['list-top-listings', 'get-report'], true ) ? 'listing' : 'total' );
            $rows   = self::daily( $listings, $range, $group );
            $result = [
                'metadata'    => self::metadata( $args, $range, $rows ),
                'definitions' => self::definitions(),
                'totals'      => self::totals( $rows ),
            ];
            if ( 'get-overview' === $action ) {
                $prior                = ['start_date' => $range['start_date']->modify( '-' . $range['days'] . ' days' ), 'end_date' => $range['start_date']->modify( '-1 day' )];
                $result['comparison'] = [
                    'start_date'      => $prior['start_date']->format( 'Y-m-d' ),
                    'end_date'        => $prior['end_date']->format( 'Y-m-d' ),
                    'availability'    => 'unavailable',
                    'reason'          => 'Collection coverage is unknown. Prior observed aggregates are not a complete, comparable baseline.',
                    'observed_totals' => self::totals( self::daily( $listings, $prior, 'total' ) ),
                    'deltas'          => array_fill_keys( array_keys( Schema::metrics() ), null ),
                ];
            } elseif ( 'get-series' === $action ) {
                $map     = array_column( $rows, null, 'group_key' );
                $buckets = [];
                for ( $day = $range['start_date']; $day <= $range['end_date']; $day = $day->modify( '+1 day' ) ) {
                    $date   = $day->format( 'Y-m-d' );
                    $bucket = [
                        'date'          => $date,
                        'start'         => $day->format( DATE_ATOM ),
                        'end_exclusive' => $day->modify( '+1 day' )->format( DATE_ATOM ),
                        'availability'  => isset( $map[$date] ) ? 'observed' : 'unknown',
                    ];
                    if ( isset( $map[$date] ) ) {
                        $bucket['value'] = $map[$date][$args['metric']];
                    }
                    $buckets[] = $bucket;
                }
                $page    = self::page( $buckets, $args, 'daily-series' );
                $result += [
                    'metric'        => $args['metric'],
                    'granularity'   => 'day',
                    'buckets'       => $page['items'],
                    'total_buckets' => count( $buckets ),
                ] + array_diff_key( $page, ['items' => true] );
                unset( $result['totals'] );
            } elseif ( 'get-listing-metrics' === $action ) {
                $result['listing_id'] = $args['listing_id'];
            } else {
                foreach ( $rows as &$row ) {
                    $row['listing_id'] = (int) $row['group_key'];
                    $row['title']      = $listings[$row['listing_id']];
                    unset( $row['group_key'], $row['latest_date'] );
                }
                unset( $row );
                $sort = $args['sort_metric'] ?? 'views';
                usort(
                    $rows, static function ( $a, $b ) use( $sort ) {
                        return $b[$sort] <=> $a[$sort] ?: $a['listing_id'] <=> $b['listing_id'];
                    }
                );
                $result += self::page( $rows, $args, 'listing-performance' );
            }
        }
        if ( 'get-report' === $action ) {
            $traffic                    = 'traffic-breakdown-v1' === $args['report_type'];
            $columns                    = $args['columns'] ?? ( $traffic ? ['key', 'value'] : array_merge( ['listing_id', 'title'], array_keys( Schema::metrics() ) ) );
            $descriptions               = Schema::metrics() + [
                'listing_id' => 'Permitted native listing ID.',
                'title'      => 'Current native listing title.',
                'key'        => 'Normalized safe group label.',
                'value'      => 'Released aggregate event count, after suppression.',
            ];
            $result['report_type']      = $args['report_type'];
            $result['columns']          = array_map(
                static function ( $key ) use( $descriptions ) {
                    return ['key' => $key, 'definition' => $descriptions[$key]];
                }, $columns
            );
            $result['items']            = array_map(
                static function ( $row ) use( $columns ) {
                    return array_intersect_key( $row, array_flip( $columns ) );
                }, $result['items']
            );
            $result['totals_available'] = ! $traffic;
            if ( $traffic ) {
                $result['totals'] = (object) [];
            }
            $result['suppressed'] = $result['suppressed'] ?? false;
            unset( $result['dimension'], $result['metric'] );
        }
        return $result;
    }
}
