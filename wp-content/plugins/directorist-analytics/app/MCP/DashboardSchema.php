<?php
/** Closed contracts for the native administrator Analytics dashboard. */
namespace DirectoristAnalytics\App\MCP;

defined( 'ABSPATH' ) || exit;

final class DashboardSchema {
    const ACTIONS       = [ 'get-dashboard-schema', 'get-dashboard-section', 'get-export-data' ];
    const METRICS       = [ 'listingViews', 'clicks', 'websiteClicks', 'searchAppearanceData', 'contactFormUsesData', 'topCountries', 'browserType', 'operatingSystems', 'screenResolutions', 'searchEngines', 'referrals' ];
    const INTERVALS     = [ 'daily', 'weekly', 'monthly', 'quarterly' ];
    const EXPORT_SCOPES = [ 'site-wide', 'per-listing', 'per-author' ];
    const SECTIONS      = [
        'overview'            => [ 'summary', 'trends', 'activities' ],
        'listing-performance' => [ 'top-listings', 'listings-table', 'search-chart', 'search-table', 'contact-form-usage', 'contact-form-table' ],
        'traffic'             => [ 'top-countries', 'countries-table', 'top-search-engines', 'top-referral-sources', 'referral-sources-table' ],
        'tech-stats'          => [ 'browsers', 'operating-systems', 'screen-resolutions', 'devices' ],
        'authors'             => [ 'list' ],
        'reports'             => [ 'options' ],
        'settings'            => [ 'privacy' ],
    ];

    public static function input( string $action ): array {
        if ( 'get-dashboard-schema' === $action ) {
            return Schema::object( [] );
        }
        $fields = [
            'start_date' => Schema::text( 10 ),
            'end_date'   => Schema::text( 10 ),
            'timezone'   => Schema::text( 80 ),
            'page'       => Schema::integer( 1, 10000 ),
            'per_page'   => Schema::integer( 1, 100 ),
            'snapshot'   => Schema::text( 64 ),
        ];
        if ( 'get-export-data' === $action ) {
            return Schema::object(
                $fields + [
                    'export_scope' => Schema::enumeration( self::EXPORT_SCOPES ),
                    'interval'     => Schema::enumeration( self::INTERVALS ),
                    'metrics'      => Schema::items( Schema::enumeration( self::METRICS ), 11 ),
                ], [ 'start_date', 'end_date', 'export_scope', 'metrics' ]
            );
        }
        return Schema::object(
            $fields + [
                'tab'         => Schema::enumeration( array_keys( self::SECTIONS ) ),
                'section'     => Schema::enumeration( array_values( array_unique( array_merge( ...array_values( self::SECTIONS ) ) ) ) ),
                'limit'       => Schema::integer( 1, 100 ),
                'listing_ids' => Schema::items( Schema::integer( 1 ), 10 ),
                'orderby'     => Schema::enumeration( [ 'name', 'views', 'clicks', 'searches', 'listingsCount', 'registrationDate', 'lastAccess', 'accessFrequency' ] ),
                'order'       => Schema::enumeration( [ 'ASC', 'DESC' ] ),
            ], [ 'tab', 'section' ]
        );
    }

    /** Explicit native UI/report fields only; never raw tracking records. */
    public static function row_fields(): array {
        $fields = [];
        foreach ( [ 'id', 'listing_id', 'author_id', 'listing_count', 'views', 'clicks', 'searches', 'contactUses', 'total', 'visitors', 'visits', 'count', 'value', 'listingsCount', 'accessFrequency', 'total_listings', 'total_reviews', 'total_authors', 'total_website_clicks', 'integer_value', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec' ] as $key ) {
            $fields[$key] = Schema::integer();
        }
        foreach ( [ 'name', 'title', 'type', 'key', 'metric', 'series', 'period', 'period_label', 'date', 'time', 'code', 'country', 'country_code', 'country_name', 'source', 'engine', 'domain', 'registrationDate', 'lastAccess', 'author_name', 'email', 'text_value' ] as $key ) {
            $fields[$key] = Schema::text( 1000 );
        }
        foreach ( [ 'url', 'image', 'avatar', 'link' ] as $key ) {
            $fields[$key] = Schema::text( 2048 );
        }
        $fields['percentage']    = [ 'type' => 'number',
            'minimum'                       => 0,
            'maximum'                       => 100
        ];
        $fields['share']         = [ 'type' => 'number',
            'minimum'                       => 0,
            'maximum'                       => 100
        ];
        $fields['boolean_value'] = [ 'type' => 'boolean' ];
        return $fields;
    }

    public static function output( string $action ): array {
        if ( 'get-dashboard-schema' === $action ) {
            return Schema::object(
                [
                    'contract_version'  => Schema::text( 40 ),
                    'scope'             => Schema::enumeration( [ 'site' ] ),
                    'capability'        => Schema::text( 40 ),
                    'timezone'          => Schema::text( 80 ),
                    'sections'          => Schema::items(
                        Schema::object(
                            [
                                'tab'             => Schema::text( 40 ),
                                'section'         => Schema::text( 40 ),
                                'source'          => Schema::text( 100 ),
                                'dates_required'  => [ 'type' => 'boolean' ],
                                'range_semantics' => Schema::text( 1000 ),
                            ], [ 'tab', 'section', 'source', 'dates_required', 'range_semantics' ]
                        ), 30
                    ),
                    'export_scopes'     => Schema::items( Schema::text( 40 ), 3 ),
                    'export_metrics'    => Schema::items( Schema::text( 40 ), 11 ),
                    'intervals'         => Schema::items( Schema::text( 40 ), 4 ),
                    'max_days'          => Schema::integer(),
                    'max_per_page'      => Schema::integer(),
                    'source_row_budget' => Schema::integer(),
                    'result_row_budget' => Schema::integer(),
                    'notes'             => Schema::items( Schema::text( 1000 ), 10 ),
                ]
            );
        }
        return Schema::object(
            Schema::page() + [
                'tab'          => Schema::text( 40 ),
                'section'      => Schema::text( 40 ),
                'export_scope' => Schema::text( 40 ),
                'interval'     => Schema::text( 20 ),
                'metadata'     => Schema::object(
                    [
                        'scope'           => Schema::enumeration( [ 'site' ] ),
                        'source'          => Schema::text( 100 ),
                        'timezone'        => Schema::text( 80 ),
                        'start_date'      => Schema::text( 10 ),
                        'end_date'        => Schema::text( 10 ),
                        'observed_at'     => Schema::text( 40 ),
                        'range_semantics' => Schema::text( 1000 ),
                        'coverage'        => Schema::enumeration( [ 'unknown' ] ),
                        'note'            => Schema::text( 1000 ),
                    ]
                ),
                'columns'      => Schema::items( Schema::text( 40 ), 100 ),
                'items'        => Schema::items( Schema::object( self::row_fields() ) ),
            ], [ 'metadata', 'items', 'columns', 'page', 'per_page', 'total', 'has_more', 'snapshot' ]
        );
    }
}
