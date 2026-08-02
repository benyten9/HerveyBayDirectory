<?php

namespace DirectoristPricingPlan\App\Http\Controllers\Legacy;

defined( 'ABSPATH' ) || exit;

use stdClass;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\TaxType;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Repositories\Admin\PlanAppConfigurationRepository;
use DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository;

class PlanController {
    private const NAMESPACE = 'directorist/v1';
    private const REST_BASE = 'plans';

    private PlanFeatureRepository $feature_repository;

    private PlanAppConfigurationRepository $app_configuration_repository;

    private array $schema_fields = [
        'id',
        'name',
        'slug',
        'date_created',
        'date_created_gmt',
        'date_modified',
        'date_modified_gmt',
        'description',
        'hide_description_from_plan',
        'directory',
        'status',
        'is_recommended',
        'is_hidden',
        'type',
        'type_label',
        'currency',
        'currency_symbol',
        'is_free',
        'price',
        'is_taxable',
        'tax_type',
        'tax',
        'validity_period',
        'validity_period_unit',
        'validity_period_label',
        'is_non_expiring',
        'playstore_product_id',
        'playstore_product_price',
        'appstore_product_id',
        'appstore_product_price',
        'features',
        'fields',
    ];

    public function __construct( PlanFeatureRepository $feature_repository, PlanAppConfigurationRepository $app_configuration_repository ) {
        $this->feature_repository           = $feature_repository;
        $this->app_configuration_repository = $app_configuration_repository;
    }

    public function get_items_permissions_check( WP_REST_Request $request ) {
        if ( function_exists( 'get_directorist_option' ) && ! get_directorist_option( 'fee_manager_enable', 1 ) ) {
            return new WP_Error( 'extension_inactive', __( 'Pricing plan extension disabled.', 'directorist-pricing-plans' ), [ 'status' => 400 ] );
        }

        return true;
    }

    public function get_item_permissions_check( WP_REST_Request $request ) {
        return $this->get_items_permissions_check( $request );
    }

    public function get_items( WP_REST_Request $request ) {
        $query_args = $this->get_query_args( $request );

        do_action( 'directorist_rest_before_query', 'get_plan_items', $request, $query_args );

        $query_results = $this->query_plans( $query_args );
        $objects       = [];

        foreach ( $query_results['objects'] as $plan ) {
            $data      = $this->prepare_item_for_response( $plan, $request );
            $objects[] = $data->get_data();
        }

        $page      = (int) $query_args['page'];
        $max_pages = (int) $query_results['pages'];
        $response  = rest_ensure_response( $objects );

        $response->header( 'X-WP-Total', (int) $query_results['total'] );
        $response->header( 'X-WP-TotalPages', $max_pages );

        $base = add_query_arg(
            $request->get_query_params(),
            rest_url( sprintf( '/%s/%s', self::NAMESPACE, self::REST_BASE ) )
        );

        if ( $page > 1 ) {
            $prev_page = min( $page - 1, $max_pages );
            $response->link_header( 'prev', add_query_arg( 'page', $prev_page, $base ) );
        }

        if ( $max_pages > $page ) {
            $response->link_header( 'next', add_query_arg( 'page', $page + 1, $base ) );
        }

        do_action( 'directorist_rest_after_query', 'get_plan_items', $request, $query_args );

        return apply_filters( 'directorist_rest_response', $response, 'get_plan_items', $request, $query_args );
    }

    public function get_item( WP_REST_Request $request ) {
        $id = directorist_pricing_plans_legacy_plan_id( (int) $request['id'] );

        do_action( 'directorist_rest_before_query', 'get_plan_item', $request, $id );

        $plan = $this->get_plan( $id );

        if ( ! $plan ) {
            return new WP_Error( 'directorist_rest_invalid_atbdp_pricing_plans_id', __( 'Invalid ID.', 'directorist-pricing-plans' ), [ 'status' => 404 ] );
        }

        $response = $this->prepare_item_for_response( $plan, $request );

        do_action( 'directorist_rest_after_query', 'get_plan_item', $request, $id );

        return apply_filters( 'directorist_rest_response', $response, 'get_plan_item', $request, $id );
    }

    public function get_collection_params(): array {
        $params = [
            'context'  => [
                'default' => 'view',
                'type'    => 'string',
            ],
            'page'     => [
                'default'           => 1,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'default'           => 10,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'order'    => [
                'default'           => 'desc',
                'enum'              => [ 'asc', 'desc' ],
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
            'orderby'  => [
                'default'           => 'title',
                'enum'              => [ 'title', 'date' ],
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
        ];

        if ( ! function_exists( 'directorist_is_multi_directory_enabled' ) || directorist_is_multi_directory_enabled() ) {
            $params['directory'] = [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ];
        }

        return $params;
    }

    public function get_public_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'atbdp_pricing_plans',
            'type'       => 'object',
            'properties' => array_fill_keys(
                $this->schema_fields,
                [
                    'type'    => [ 'boolean', 'integer', 'number', 'string', 'array', 'object', 'null' ],
                    'context' => [ 'view', 'edit' ],
                ]
            ),
        ];
    }

    private function get_query_args( WP_REST_Request $request ): array {
        $page     = max( 1, (int) ( $request['page'] ?: 1 ) );
        $per_page = max( 1, min( 100, (int) ( $request['per_page'] ?: 10 ) ) );
        $order    = 'asc' === strtolower( (string) $request['order'] ) ? 'ASC' : 'DESC';
        $orderby  = 'date' === $request['orderby'] ? 'created_at' : 'title';

        return [
            'directory' => $this->get_directory_filter( $request ),
            'page'      => $page,
            'per_page'  => $per_page,
            'offset'    => ( $page - 1 ) * $per_page,
            'order'     => $order,
            'orderby'   => $orderby,
        ];
    }

    private function get_directory_filter( WP_REST_Request $request ): int {
        if ( function_exists( 'directorist_is_multi_directory_enabled' ) && directorist_is_multi_directory_enabled() ) {
            return absint( $request['directory'] );
        }

        if ( function_exists( 'directorist_get_default_directory' ) ) {
            return (int) directorist_get_default_directory();
        }

        return absint( $request['directory'] );
    }

    private function query_plans( array $query_args ): array {
        global $wpdb;

        $table      = $wpdb->prefix . Plan::get_table_name();
        $where      = [ 'is_published = 1' ];
        $parameters = [];

        if ( ! empty( $query_args['directory'] ) ) {
            $where[]      = 'directory_type_id = %d';
            $parameters[] = (int) $query_args['directory'];
        }

        $where_sql = implode( ' AND ', $where );
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        if ( ! empty( $parameters ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $parameters ) );
        } else {
            $total = (int) $wpdb->get_var( $count_sql );
        }

        $orderby = 'created_at' === $query_args['orderby'] ? 'created_at' : 'title';
        $order   = 'ASC' === $query_args['order'] ? 'ASC' : 'DESC';
        $sql     = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
        $params  = array_merge( $parameters, [ (int) $query_args['per_page'], (int) $query_args['offset'] ] );
        $objects = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        return [
            'objects' => $objects ?: [],
            'total'   => $total,
            'pages'   => (int) ceil( $total / (int) $query_args['per_page'] ),
        ];
    }

    private function get_plan( int $id ): ?stdClass {
        global $wpdb;

        if ( ! $id ) {
            return null;
        }

        $table = $wpdb->prefix . Plan::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $plan = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND is_published = 1",
                $id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $plan ?: null;
    }

    private function prepare_item_for_response( stdClass $plan, WP_REST_Request $request ): WP_REST_Response {
        $fields = $this->get_fields_for_response( $request );
        $data   = $this->get_plan_data( $plan, $fields );

        $response = rest_ensure_response( $data );
        $response->add_links( $this->prepare_links( $plan ) );

        return apply_filters( 'directorist_rest_prepare_atbdp_pricing_plans_object', $response, $plan, $request );
    }

    private function get_fields_for_response( WP_REST_Request $request ): array {
        $fields = $this->schema_fields;
        $param  = $request->get_param( '_fields' );

        if ( ! empty( $param ) ) {
            $requested = is_array( $param ) ? $param : explode( ',', (string) $param );
            $requested = array_map( 'trim', $requested );
            $requested = array_filter( $requested );
            $fields    = array_values( array_intersect( $fields, $requested ) );
        }

        return $fields;
    }

    private function get_plan_data( stdClass $plan, array $fields ): array {
        $data = [];

        foreach ( $fields as $field ) {
            switch ( $field ) {
                case 'id':
                    $data['id'] = (int) $plan->id;
                    break;
                case 'name':
                    $data['name'] = (string) $plan->title;
                    break;
                case 'slug':
                    $data['slug'] = sanitize_title( $plan->title );
                    break;
                case 'date_created':
                    $data['date_created'] = $this->format_date( $plan->created_at ?? '', false );
                    break;
                case 'date_created_gmt':
                    $data['date_created_gmt'] = $this->format_date( $plan->created_at ?? '', true );
                    break;
                case 'date_modified':
                    $data['date_modified'] = $this->format_date( $plan->updated_at ?? '', false );
                    break;
                case 'date_modified_gmt':
                    $data['date_modified_gmt'] = $this->format_date( $plan->updated_at ?? '', true );
                    break;
                case 'description':
                    $data['description'] = (string) ( $plan->description ?? '' );
                    break;
                case 'hide_description_from_plan':
                    $data['hide_description_from_plan'] = false;
                    break;
                case 'directory':
                    $data['directory'] = (int) $plan->directory_type_id;
                    break;
                case 'status':
                    $data['status'] = ! empty( $plan->is_published ) ? 'publish' : 'draft';
                    break;
                case 'is_recommended':
                    $data['is_recommended'] = (bool) $plan->is_marked_as_recommended;
                    break;
                case 'is_hidden':
                    $data['is_hidden'] = (bool) $plan->is_hidden_from_plans_list;
                    break;
                case 'type':
                    $data['type'] = $this->get_plan_type( $plan );
                    break;
                case 'type_label':
                    $data['type_label'] = PlanType::PACKAGE === $this->get_plan_type( $plan ) ? esc_html__( 'Per Package', 'directorist-pricing-plans' ) : esc_html__( 'Per Listing', 'directorist-pricing-plans' );
                    break;
                case 'currency':
                    $data['currency'] = function_exists( 'atbdp_get_payment_currency' ) ? atbdp_get_payment_currency() : '';
                    break;
                case 'currency_symbol':
                    $currency                = function_exists( 'atbdp_get_payment_currency' ) ? atbdp_get_payment_currency() : '';
                    $data['currency_symbol'] = function_exists( 'atbdp_currency_symbol' ) ? html_entity_decode( atbdp_currency_symbol( $currency ) ) : '';
                    break;
                case 'is_free':
                    $data['is_free'] = FeeType::FREE === ( $plan->fee_type ?? '' );
                    break;
                case 'price':
                    $data['price'] = FeeType::FREE === ( $plan->fee_type ?? '' ) ? 0 : (float) $plan->price;
                    break;
                case 'is_taxable':
                    $data['is_taxable'] = (bool) $plan->is_taxable;
                    break;
                case 'tax_type':
                    $data['tax_type'] = TaxType::PERCENT === ( $plan->tax_type ?? '' ) ? 'percentage' : 'fixed';
                    break;
                case 'tax':
                    $data['tax'] = (float) $plan->tax_rate;
                    break;
                case 'validity_period':
                    $data['validity_period'] = PlanInterval::LIFETIME === ( $plan->interval_type ?? '' ) ? 0 : (int) $plan->interval_count;
                    break;
                case 'validity_period_unit':
                    $data['validity_period_unit'] = $this->get_validity_period_unit( $plan );
                    break;
                case 'validity_period_label':
                    $data['validity_period_label'] = $this->get_validity_period_label( $plan );
                    break;
                case 'is_non_expiring':
                    $data['is_non_expiring'] = PlanInterval::LIFETIME === ( $plan->interval_type ?? '' );
                    break;
                case 'playstore_product_id':
                case 'playstore_product_price':
                case 'appstore_product_id':
                case 'appstore_product_price':
                    $data[ $field ] = $this->get_app_configuration_value( (int) $plan->id, $field );
                    break;
                case 'features':
                    $data['features'] = $this->get_features_data( $plan );
                    break;
                case 'fields':
                    $data['fields'] = $this->get_fields_data( $plan );
                    break;
            }
        }

        return $data;
    }

    private function format_date( string $date, bool $gmt ): ?string {
        if ( '' === $date || '0000-00-00 00:00:00' === $date ) {
            return null;
        }

        if ( function_exists( 'directorist_rest_prepare_date_response' ) ) {
            return directorist_rest_prepare_date_response( $date, $gmt );
        }

        $timestamp = strtotime( $gmt ? get_gmt_from_date( $date ) : $date );

        return $timestamp ? gmdate( DATE_RFC3339, $timestamp ) : null;
    }

    private function get_plan_type( stdClass $plan ): string {
        return PlanType::PAY_PER_LISTING === ( $plan->type ?? '' ) ? PlanType::PAY_PER_LISTING : PlanType::PACKAGE;
    }

    private function get_validity_period_unit( stdClass $plan ): string {
        $unit = (string) ( $plan->interval_type ?? PlanInterval::DAY );

        return in_array( $unit, [ PlanInterval::DAY, PlanInterval::WEEK, PlanInterval::MONTH, PlanInterval::YEAR ], true ) ? $unit : PlanInterval::DAY;
    }

    private function get_validity_period_label( stdClass $plan ): string {
        if ( PlanInterval::LIFETIME === ( $plan->interval_type ?? '' ) ) {
            return esc_html__( 'Lifetime', 'directorist-pricing-plans' );
        }

        $count = max( 0, (int) $plan->interval_count );
        $unit  = $this->get_validity_period_unit( $plan );

        $translations = [
            PlanInterval::DAY   => _n( '%d day', '%d days', $count, 'directorist-pricing-plans' ),
            PlanInterval::WEEK  => _n( '%d week', '%d weeks', $count, 'directorist-pricing-plans' ),
            PlanInterval::MONTH => _n( '%d month', '%d months', $count, 'directorist-pricing-plans' ),
            PlanInterval::YEAR  => _n( '%d year', '%d years', $count, 'directorist-pricing-plans' ),
        ];

        return sprintf( $translations[ $unit ], $count );
    }

    private function get_app_configuration_value( int $plan_id, string $field ) {
        static $cache = [];

        if ( ! isset( $cache[ $plan_id ] ) ) {
            $cache[ $plan_id ] = $this->app_configuration_repository->get_by_plan_id( $plan_id );
        }

        $field_map = [
            'playstore_product_id'    => [ 'playstore', 'product_id' ],
            'playstore_product_price' => [ 'playstore', 'product_price' ],
            'appstore_product_id'     => [ 'appstore', 'product_id' ],
            'appstore_product_price'  => [ 'appstore', 'product_price' ],
        ];

        if ( ! isset( $field_map[ $field ] ) ) {
            return '';
        }

        [ $type, $property ] = $field_map[ $field ];

        foreach ( $cache[ $plan_id ] as $configuration ) {
            if ( $type === ( $configuration->type ?? '' ) ) {
                return $configuration->{$property} ?? '';
            }
        }

        return '';
    }

    private function get_features_data( stdClass $plan ): array {
        $features = [
            [
                'key'            => 'auto_renewal',
                'label'          => esc_html__( 'Auto renewing', 'directorist-pricing-plans' ),
                'is_active'      => (bool) $plan->is_subscription_enabled,
                'hide_from_plan' => false,
            ],
        ];

        if ( PlanType::PACKAGE === $this->get_plan_type( $plan ) ) {
            $features[] = [
                'key'            => 'regular_listings',
                'label'          => $this->get_listing_limit_label( 'regular', (int) $plan->allowed_listings, (bool) $plan->is_allowed_unlimited_listings ),
                'is_active'      => true,
                'hide_from_plan' => false,
                'limit'          => (bool) $plan->is_allowed_unlimited_listings ? -1 : (int) $plan->allowed_listings,
            ];
            $features[] = [
                'key'            => 'featured_listings',
                'label'          => $this->get_listing_limit_label( 'featured', (int) $plan->allowed_featured_listings, (bool) $plan->is_allowed_unlimited_featured_listings ),
                'is_active'      => true,
                'hide_from_plan' => false,
                'limit'          => (bool) $plan->is_allowed_unlimited_featured_listings ? -1 : (int) $plan->allowed_featured_listings,
            ];
        } else {
            $features[] = [
                'key'            => 'featured_listing',
                'label'          => esc_html__( 'Listing as featured', 'directorist-pricing-plans' ),
                'is_active'      => (bool) $plan->is_featured,
                'hide_from_plan' => false,
            ];
        }

        return array_merge( $features, $this->get_extra_features_data( $plan ) );
    }

    private function get_extra_features_data( stdClass $plan ): array {
        $features = [];
        $map      = [
            'contact_listings_owner'  => [ 'contact_listing_owner', esc_html__( 'Contact Owner', 'directorist-pricing-plans' ) ],
            'review'                  => [ 'reviews_allowed', esc_html__( 'Allow Customer Review', 'directorist-pricing-plans' ) ],
            'claim_listing'           => [ 'claim_badge_included', esc_html__( 'Claim Badge Included', 'directorist-pricing-plans' ) ],
            'bdb'                     => [ 'booking_included', esc_html__( 'Booking Included', 'directorist-pricing-plans' ) ],
            'live_chat'               => [ 'live_chat_included', esc_html__( 'Live Chat Included', 'directorist-pricing-plans' ) ],
            'sold_badge'              => [ 'mark_as_sold_included', esc_html__( 'Mark as Sold Included', 'directorist-pricing-plans' ) ],
            'admin_category_select[]' => [ 'categories_included', esc_html__( 'All Categories', 'directorist-pricing-plans' ) ],
        ];

        foreach ( $this->feature_repository->get( $plan ) as $feature ) {
            $key = (string) ( $feature->key ?? '' );

            if ( ! isset( $map[ $key ] ) ) {
                continue;
            }

            $features[] = [
                'key'            => $map[ $key ][0],
                'label'          => $map[ $key ][1],
                'is_active'      => (bool) ( $feature->is_enabled ?? false ),
                'hide_from_plan' => ! (bool) ( $feature->is_show_in_pricing_table ?? false ),
            ];
        }

        return $features;
    }

    private function get_listing_limit_label( string $type, int $count, bool $unlimited ): string {
        if ( 'regular' === $type ) {
            return $unlimited
                ? __( 'Unlimited Regular Listings', 'directorist-pricing-plans' )
                : sprintf( _n( '%s Regular Listing', '%s Regular Listings', $count, 'directorist-pricing-plans' ), $count );
        }

        return $unlimited
            ? __( 'Unlimited Featured Listings', 'directorist-pricing-plans' )
            : sprintf( _n( '%s Featured Listing', '%s Featured Listings', $count, 'directorist-pricing-plans' ), $count );
    }

    private function get_fields_data( stdClass $plan ): array {
        $field_data = [];

        foreach ( $this->feature_repository->get( $plan ) as $feature ) {
            $key = (string) ( $feature->key ?? '' );

            if ( in_array( $key, [ 'review', 'contact_listings_owner', 'claim_listing', 'bdb', 'live_chat', 'sold_badge' ], true ) ) {
                continue;
            }

            $data = [
                'key'            => $key,
                'label'          => (string) ( $feature->name ?? $key ),
                'is_preset'      => $this->is_preset_field( $key ),
                'is_active'      => (bool) ( $feature->is_enabled ?? false ),
                'hide_from_plan' => ! (bool) ( $feature->is_show_in_pricing_table ?? false ),
            ];

            $feature_data = isset( $feature->data ) && is_array( $feature->data ) ? $feature->data : [];

            if ( ! empty( $feature_data['is_unlimited'] ) ) {
                $data['label'] = sprintf( __( '%s (unlimited)', 'directorist-pricing-plans' ), $data['label'] );
                $data['limit'] = -1;
            } else if ( isset( $feature_data['limit'] ) && '' !== $feature_data['limit'] ) {
                $data['limit'] = (int) $feature_data['limit'];
            }

            $field_data[] = $data;
        }

        return $field_data;
    }

    private function is_preset_field( string $key ): bool {
        return in_array(
            $key,
            [
                'tax_input[at_biz_dir-location][]',
                'admin_category_select[]',
                'tax_input[at_biz_dir-tags][]',
                'listing_content',
                'excerpt',
                'listing_img',
                'price',
                'price_range',
            ],
            true
        );
    }

    private function prepare_links( stdClass $plan ): array {
        return [
            'self'       => [
                'href' => rest_url( sprintf( '/%s/%s/%d', self::NAMESPACE, self::REST_BASE, (int) $plan->id ) ),
            ],
            'collection' => [
                'href' => rest_url( sprintf( '/%s/%s', self::NAMESPACE, self::REST_BASE ) ),
            ],
        ];
    }
}
