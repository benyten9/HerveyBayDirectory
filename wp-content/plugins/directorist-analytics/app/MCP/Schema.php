<?php

/** Closed MCP contracts. Native daily measures cannot be rebucketed into another timezone. */
namespace DirectoristAnalytics\App\MCP;

defined( 'ABSPATH' ) || exit;
final class Schema
{
    public static function text( int $max = 256 ) : array {
        return ['type' => 'string', 'maxLength' => $max];
    }

    public static function integer( int $min = 0, int $max = PHP_INT_MAX ) : array {
        return [
            'type'    => 'integer',
            'minimum' => $min,
            'maximum' => $max,
        ];
    }

    public static function enumeration( array $values ) : array {
        return [
            'type'      => 'string',
            'enum'      => $values,
            'maxLength' => 80,
        ];
    }

    public static function object( array $properties, array $required = [] ) : array {
        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
        ];
    }

    public static function items( array $schema, int $max = 100 ) : array {
        return [
            'type'     => 'array',
            'items'    => $schema,
            'maxItems' => $max,
        ];
    }

    public static function metrics(): array {
        return array_map( static function ( $definition ) { return $definition['definition']; }, \DirectoristAnalytics\App\Services\MetricDefinitions::period() );
    }

    public static function metric_values() : array {
        return self::object( array_fill_keys( array_keys( self::metrics() ), self::integer() ), array_keys( self::metrics() ) );
    }

    public static function definitions() : array {
        return self::items( self::object( ['key' => self::text( 40 ), 'definition' => self::text( 300 )], ['key', 'definition'] ), 4 );
    }

    public static function input( string $action ) : array {
        if ( in_array( $action, DashboardSchema::ACTIONS, true ) ) { return DashboardSchema::input( $action ); }
        $scope = ['scope' => self::enumeration( ['site', 'own'] )];
        if ( 'get-schema' === $action ) {
            return self::object( $scope );
        }
        $fields   = $scope + [
            'start_date'   => self::text( 10 ),
            'end_date'     => self::text( 10 ),
            'timezone'     => self::text( 80 ),
            'directory_id' => self::integer( 1 ),
        ];
        $required = ['start_date', 'end_date'];
        if ( in_array( $action, ['get-listing-metrics', 'get-breakdown'], true ) ) {
            $fields['listing_id'] = self::integer( 1 );
        }
        if ( 'get-listing-metrics' === $action ) {
            $required[] = 'listing_id';
        }
        if ( in_array( $action, ['get-series', 'get-breakdown'], true ) ) {
            $fields['metric'] = self::enumeration( array_keys( self::metrics() ) );
            $required[]       = 'metric';
        }
        if ( 'get-series' === $action ) {
            $fields['granularity'] = self::enumeration( ['day'] );
        }
        if ( 'get-breakdown' === $action ) {
            $fields['dimension'] = self::enumeration(
                [
                    'channel',
                    'country',
                    'device',
                    'click_type',
                ]
            );
            $required[]          = 'dimension';
        }
        if ( in_array(
            $action, [
                'get-series',
                'get-breakdown',
                'list-top-listings',
                'get-report',
            ], true
        ) ) {
            $fields += [
                'page'     => self::integer( 1, 10000 ),
                'per_page' => self::integer( 1, 100 ),
                'snapshot' => self::text( 64 ),
            ];
        }
        if ( in_array( $action, ['list-top-listings', 'get-report'], true ) ) {
            $fields['sort_metric'] = self::enumeration( array_keys( self::metrics() ) );
        }
        if ( 'get-report' === $action ) {
            $fields    += [
                'report_type' => self::enumeration( ['listing-performance-v1', 'traffic-breakdown-v1'] ),
                'dimension'   => self::enumeration(
                    [
                        'channel',
                        'country',
                        'device',
                        'click_type',
                    ]
                ),
                'metric'      => self::enumeration( array_keys( self::metrics() ) ),
                'listing_id'  => self::integer( 1 ),
                'columns'     => self::items(
                    self::enumeration(
                        [
                            'listing_id',
                            'title',
                            'views',
                            'unique_views',
                            'clicks_total',
                            'search_appearances',
                            'key',
                            'value',
                        ]
                    ), 8
                ),
            ];
            $required[] = 'report_type';
        }
        return self::object( $fields, $required );
    }

    public static function metadata() : array {
        return self::object(
            [
                'scope'                => self::text( 10 ),
                'timezone'             => self::text( 80 ),
                'start_date'           => self::text( 10 ),
                'end_date'             => self::text( 10 ),
                'start'                => self::text( 40 ),
                'end_exclusive'        => self::text( 40 ),
                'observed_at'          => self::text( 40 ),
                'availability'         => self::enumeration( ['observed', 'empty'] ),
                'coverage'             => self::enumeration( ['unknown'] ),
                'collection_status'    => self::text( 80 ),
                'coverage_note'        => self::text( 600 ),
                'latest_observed_date' => self::text( 10 ),
                'source'               => self::text( 80 ),
                'retention_days'       => self::integer(),
            ]
        );
    }

    public static function page() : array {
        return [
            'page'     => self::integer( 1 ),
            'per_page' => self::integer( 1, 100 ),
            'total'    => self::integer(),
            'has_more' => ['type' => 'boolean'],
            'snapshot' => self::text( 64 ),
        ];
    }

    public static function listing_row() : array {
        return self::object( ['listing_id' => self::integer( 1 ), 'title' => self::text( 200 )] + array_fill_keys( array_keys( self::metrics() ), self::integer() ) );
    }

    public static function output( string $action ) : array {
        if ( in_array( $action, DashboardSchema::ACTIONS, true ) ) { return DashboardSchema::output( $action ); }
        $fields = ['metadata' => self::metadata(), 'definitions' => self::definitions()];
        if ( 'get-schema' === $action ) {
            return self::object(
                [
                    'scope'                => self::text( 10 ),
                    'timezone'             => self::text( 80 ),
                    'metrics'              => self::definitions(),
                    'dimensions'           => self::items( self::text( 20 ), 4 ),
                    'filters'              => self::items( self::text( 30 ), 8 ),
                    'granularities'        => self::items( self::text( 10 ), 1 ),
                    'max_days'             => self::integer(),
                    'max_buckets'          => self::integer(),
                    'default_per_page'     => self::integer(),
                    'max_per_page'         => self::integer(),
                    'minimum_group_events' => self::integer(),
                    'listing_scan_budget'  => self::integer(),
                    'source_row_budget'    => self::integer(),
                    'report_types'         => self::items( self::text( 40 ), 2 ),
                    'coverage'             => self::text( 600 ),
                    'source_freshness'     => self::text( 300 ),
                    'observed_at'          => self::text( 40 ),
                    'collection_status'    => self::text( 80 ),
                    'privacy'              => self::items( self::text( 300 ), 8 ),
                ]
            );
        }
        if ( 'get-overview' === $action ) {
            $fields += [
                'totals'     => self::metric_values(),
                'comparison' => self::object(
                    [
                        'start_date'      => self::text( 10 ),
                        'end_date'        => self::text( 10 ),
                        'availability'    => self::text( 30 ),
                        'reason'          => self::text( 300 ),
                        'observed_totals' => self::metric_values(),
                        'deltas'          => self::object( array_fill_keys( array_keys( self::metrics() ), ['type' => 'null'] ) ),
                    ]
                )
            ];
        } elseif ( 'get-series' === $action ) {
            $fields += [
                'metric'        => self::text( 40 ),
                'granularity'   => self::text( 10 ),
                'buckets'       => self::items(
                    self::object(
                        [
                            'date'          => self::text( 10 ),
                            'start'         => self::text( 40 ),
                            'end_exclusive' => self::text( 40 ),
                            'value'         => self::integer(),
                            'availability'  => self::text( 20 ),
                        ]
                    )
                ),
                'total_buckets' => self::integer( 1, 366 ),
            ] + self::page();
        } elseif ( 'get-listing-metrics' === $action ) {
            $fields += ['listing_id' => self::integer( 1 ), 'totals' => self::metric_values()];
        } elseif ( 'list-top-listings' === $action ) {
            $fields += ['items' => self::items( self::listing_row() ), 'totals' => self::metric_values()] + self::page();
        } elseif ( 'get-breakdown' === $action ) {
            $fields += [
                'dimension'  => self::text( 20 ),
                'metric'     => self::text( 40 ),
                'items'      => self::items( self::object( ['key' => self::text( 20 ), 'value' => self::integer( 5 )] ) ),
                'suppressed' => ['type' => 'boolean'],
                'totals'     => ['type' => 'null'],
            ] + self::page();
        } else {
            $fields += [
                'report_type'      => self::text( 40 ),
                'columns'          => self::items( self::object( ['key' => self::text( 40 ), 'definition' => self::text( 300 )] ), 8 ),
                'items'            => self::items(
                    self::object(
                        [
                            'listing_id' => self::integer( 1 ),
                            'title'      => self::text( 200 ),
                            'key'        => self::text( 20 ),
                            'value'      => self::integer( 5 ),
                        ] + array_fill_keys( array_keys( self::metrics() ), self::integer() )
                    )
                ),
                'totals'           => [
                    'type'                 => 'object',
                    'properties'           => array_fill_keys( array_keys( self::metrics() ), self::integer() ),
                    'additionalProperties' => false,
                ],
                'suppressed'       => ['type' => 'boolean'],
                'totals_available' => ['type' => 'boolean'],
            ] + self::page();
        }
        return self::object( $fields );
    }
}
