<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

/** Closed, typed filters over the permitted plan projection; never arbitrary SQL fields. */
final class PlanFilters {
    private const PRIVATE_FIELDS = [ 'sort_order', 'is_published', 'is_hidden_from_plans_list', 'is_fallback', 'fallback_plan_id', 'listing_display_priority', 'user_counts.total', 'user_counts.active', 'user_counts.inactive' ];
    private const NUMERIC_OPERATORS = [ 'eq', 'ne', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'exists' ];
    private const TEXT_OPERATORS = [ 'eq', 'ne', 'in', 'not_in', 'contains', 'not_contains', 'starts_with', 'ends_with', 'exists' ];

    public static function fields(): array {
        $fields = Schema::configuration()['properties'];
        unset( $fields['features'] );
        foreach ( Schema::plan()['properties'] as $key => $schema ) {
            if ( 'configuration' !== $key ) { $fields[ $key ] = $schema; }
        }
        foreach ( [ 'total', 'active', 'inactive' ] as $key ) { $fields[ 'user_counts.' . $key ] = Schema::integer(); }
        $fields['features.is_enabled'] = [ 'type' => 'boolean' ];
        $fields['features.is_show_in_pricing_table'] = [ 'type' => 'boolean' ];
        $fields['features.data.is_unlimited'] = [ 'type' => 'boolean' ];
        $fields['features.data.limit'] = Schema::integer();
        return $fields;
    }

    private static function operators( array $schema ): array {
        if ( 'array' === $schema['type'] ) { return [ 'contains', 'not_contains', 'exists' ]; }
        if ( 'boolean' === $schema['type'] || isset( $schema['enum'] ) ) { return [ 'eq', 'ne', 'in', 'not_in', 'exists' ]; }
        return in_array( $schema['type'], [ 'integer', 'number' ], true ) ? self::NUMERIC_OPERATORS : self::TEXT_OPERATORS;
    }

    private static function field_schema( array $schema ): array {
        $operators = self::operators( $schema );
        if ( 'array' === $schema['type'] ) { $schema = $schema['items']; }
        // Conditions may use boundaries outside the valid stored range.
        unset( $schema['minimum'], $schema['maximum'], $schema['minLength'] );
        $properties = [];
        foreach ( $operators as $operator ) {
            if ( 'exists' === $operator ) { $properties[ $operator ] = [ 'type' => 'boolean' ]; }
            elseif ( in_array( $operator, [ 'in', 'not_in' ], true ) ) { $properties[ $operator ] = Schema::list( $schema ) + [ 'minItems' => 1 ]; }
            else { $properties[ $operator ] = $schema; }
        }
        return Schema::object( $properties ) + [ 'minProperties' => 1 ];
    }

    public static function schema(): array {
        $properties = [];
        $counts = [];
        foreach ( self::fields() as $field => $schema ) {
            if ( 0 === strpos( $field, 'features.' ) ) { continue; }
            if ( 0 === strpos( $field, 'user_counts.' ) ) { $counts[ substr( $field, strlen( 'user_counts.' ) ) ] = self::field_schema( $schema ); }
            else { $properties[ $field ] = self::field_schema( $schema ); }
        }
        $properties['user_counts'] = Schema::object( $counts ) + [ 'minProperties' => 1, 'description' => 'Administrator-only counts of distinct existing users: total, active and inactive.' ];
        $properties['features'] = Schema::list( Schema::object( [
            'key' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 150 ],
            'is_enabled' => self::field_schema( [ 'type' => 'boolean' ] ),
            'is_show_in_pricing_table' => self::field_schema( [ 'type' => 'boolean' ] ),
            'data' => Schema::object( [
                'is_unlimited' => self::field_schema( [ 'type' => 'boolean' ] ),
                'limit' => self::field_schema( Schema::integer() ),
            ] ) + [ 'minProperties' => 1 ],
        ], [ 'key' ] ) );
        return Schema::object( $properties ) + [ 'description' => 'All conditions must match, with at most 50 conditions. Each field takes typed operators, e.g. price:{gte:10,lt:50}, type:{eq:package}. Features take a key and optional conditions on enabled, pricing-table visibility or data limits, using effective native defaults. A key alone requires the feature to exist. Numeric comparisons use stored finite limits; use the separate unlimited flag for unlimited allowances. Text searches are case-insensitive. exists takes a boolean. All filters run before pagination and totals. get-schema lists field-specific operators and permissions.' ];
    }

    public static function discovery(): array {
        $out = [];
        foreach ( self::fields() as $field => $schema ) {
            $private = in_array( $field, self::PRIVATE_FIELDS, true );
            if ( $private && ! Access::admin() ) { continue; }
            $out[] = [ 'field' => $field, 'type' => $schema['type'], 'operators' => self::operators( $schema ), 'admin_only' => $private, 'feature_key_required' => 0 === strpos( $field, 'features.' ) ];
        }
        return $out;
    }

    public static function prepare( $input ): array {
        Access::check( is_array( $input ) || $input instanceof \stdClass, 'Plan filters must be an object.' );
        $input = json_decode( wp_json_encode( $input ), true );
        Access::check( is_array( $input ), 'Plan filters must contain valid JSON values.' );
        $filters = [];
        foreach ( $input as $field => $conditions ) {
            if ( 'features' === $field ) {
                foreach ( $conditions as $feature ) {
                    $added = false;
                    foreach ( [ 'is_enabled', 'is_show_in_pricing_table' ] as $key ) {
                        if ( ! isset( $feature[ $key ] ) ) { continue; }
                        Access::check( ! empty( $feature[ $key ] ), 'Select a feature condition.' );
                        foreach ( $feature[ $key ] as $operator => $value ) { $filters[] = [ 'field' => 'features.' . $key, 'operator' => $operator, 'value' => $value, 'feature_key' => $feature['key'] ]; $added = true; }
                    }
                    if ( isset( $feature['data'] ) ) {
                        Access::check( ! empty( $feature['data'] ), 'Select a feature data condition.' );
                        foreach ( $feature['data'] as $key => $data_conditions ) {
                            Access::check( ! empty( $data_conditions ), 'Select a feature limit condition.' );
                            foreach ( $data_conditions as $operator => $value ) { $filters[] = [ 'field' => 'features.data.' . $key, 'operator' => $operator, 'value' => $value, 'feature_key' => $feature['key'] ]; $added = true; }
                        }
                    }
                    if ( ! $added ) { $filters[] = [ 'field' => 'features.is_enabled', 'operator' => 'exists', 'value' => true, 'feature_key' => $feature['key'] ]; }
                }
            } elseif ( 'user_counts' === $field ) {
                Access::check( ! empty( $conditions ), 'Select a user-count condition.' );
                foreach ( $conditions as $key => $count_conditions ) {
                    Access::check( ! empty( $count_conditions ), 'Select a user-count condition.' );
                    foreach ( $count_conditions as $operator => $value ) { $filters[] = [ 'field' => 'user_counts.' . $key, 'operator' => $operator, 'value' => $value ]; }
                }
            } else {
                Access::check( ! empty( $conditions ), 'Select a condition for ' . $field . '.' );
                foreach ( $conditions as $operator => $value ) { $filters[] = [ 'field' => $field, 'operator' => $operator, 'value' => $value ]; }
            }
        }
        Access::check( count( $filters ) <= 50, 'Use at most 50 plan filter conditions.' );
        $fields = self::fields();
        foreach ( $filters as &$filter ) {
            $field = $filter['field'];
            Access::check( isset( $fields[ $field ] ), 'Unsupported plan filter field.' );
            Access::check( Access::admin() || ! in_array( $field, self::PRIVATE_FIELDS, true ), 'This plan filter requires administrator access.', 'forbidden' );
            $schema = $fields[ $field ];
            $operator = $filter['operator'];
            Access::check( in_array( $operator, self::operators( $schema ), true ), 'Unsupported operator for plan filter ' . $field . '.' );
            $feature = 0 === strpos( $field, 'features.' );
            Access::check( $feature === isset( $filter['feature_key'] ), $feature ? 'Select a feature_key for this feature filter.' : 'feature_key is only supported for feature filters.' );
            if ( 'exists' === $operator ) {
                Access::check( is_bool( $filter['value'] ), 'exists requires a boolean value.' );
                continue;
            }
            $multiple = in_array( $operator, [ 'in', 'not_in' ], true );
            Access::check( $multiple === is_array( $filter['value'] ), $multiple ? 'Membership filters require a nonempty list of values.' : 'This operator requires one scalar value.' );
            if ( 'array' === $schema['type'] ) { $schema = $schema['items']; }
            // Filter boundaries need not be valid stored values (for example price < 0).
            unset( $schema['minimum'], $schema['maximum'], $schema['minLength'] );
            foreach ( $multiple ? $filter['value'] : [ $filter['value'] ] as $value ) {
                $valid = ! is_wp_error( rest_validate_value_from_schema( $value, $schema ) );
                // WordPress accepts numeric/boolean strings; keep filter comparisons genuinely typed.
                if ( 'boolean' === $schema['type'] ) { $valid = $valid && is_bool( $value ); }
                if ( 'integer' === $schema['type'] ) { $valid = $valid && is_int( $value ); }
                if ( 'number' === $schema['type'] ) { $valid = $valid && ( is_int( $value ) || is_float( $value ) ); }
                if ( 'string' === $schema['type'] ) { $valid = $valid && is_string( $value ); }
                Access::check( $valid, 'Invalid typed value for plan filter ' . $field . '.' );
            }
        }
        unset( $filter );
        return $filters;
    }

    /** Apply inexpensive configuration checks before fetching effective features and metadata. */
    public static function native_matches( \stdClass $plan, array $filters ): bool {
        $definitions = Schema::configuration()['properties'];
        foreach ( $filters as $filter ) {
            $field = $filter['field'];
            if ( 'id' === $field ) {
                if ( ! self::compare( true, (int) $plan->id, $filter ) ) { return false; }
                continue;
            }
            if ( ! isset( $definitions[ $field ] ) || 'features' === $field ) { continue; }
            $value = $plan->$field ?? PlanWriter::defaults()[ $field ];
            switch ( $definitions[ $field ]['type'] ) {
                case 'boolean': $value = (bool) $value; break;
                case 'integer': $value = (int) $value; break;
                case 'number': $value = (float) $value; break;
                case 'string': $value = (string) $value; break;
            }
            if ( ! self::compare( true, $value, $filter ) ) { return false; }
        }
        return true;
    }

    public static function matches( array $plan, array $filters ): bool {
        $features = array_column( $plan['configuration']['features'], null, 'key' );
        foreach ( $filters as $filter ) {
            $field = $filter['field'];
            if ( 0 === strpos( $field, 'features.' ) ) {
                $value = $features[ $filter['feature_key'] ] ?? [];
                $path = explode( '.', substr( $field, strlen( 'features.' ) ) );
            } elseif ( 0 === strpos( $field, 'user_counts.' ) ) {
                $value = $plan;
                $path = explode( '.', $field );
            } else {
                $value = array_key_exists( $field, $plan['configuration'] ) ? $plan['configuration'] : $plan;
                $path = [ $field ];
            }
            $exists = true;
            foreach ( $path as $key ) {
                if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) { $exists = false; break; }
                $value = $value[ $key ];
            }
            if ( ! self::compare( $exists, $value, $filter ) ) { return false; }
        }
        return true;
    }

    private static function text_match( string $actual, string $wanted, string $operator ): bool {
        $pattern = preg_quote( $wanted, '~' );
        if ( in_array( $operator, [ 'eq', 'starts_with' ], true ) ) { $pattern = '\\A' . $pattern; }
        if ( in_array( $operator, [ 'eq', 'ends_with' ], true ) ) { $pattern .= '\\z'; }
        return 1 === preg_match( '~' . $pattern . '~iu', $actual );
    }

    private static function equal( $actual, $wanted ): bool {
        if ( is_string( $actual ) ) { return self::text_match( $actual, $wanted, 'eq' ); }
        if ( is_bool( $actual ) ) { return $actual === $wanted; }
        return (float) $actual === (float) $wanted;
    }

    private static function compare( bool $exists, $actual, array $filter ): bool {
        $operator = $filter['operator'];
        $wanted = $filter['value'];
        if ( 'exists' === $operator ) { return $exists === $wanted; }
        // An absent feature/limit is not a disabled feature or a zero-valued limit.
        if ( ! $exists ) { return false; }
        if ( in_array( $operator, [ 'in', 'not_in' ], true ) ) {
            $found = false;
            foreach ( $wanted as $value ) { $found = $found || self::equal( $actual, $value ); }
            return 'in' === $operator ? $found : ! $found;
        }
        if ( in_array( $operator, [ 'contains', 'not_contains', 'starts_with', 'ends_with' ], true ) ) {
            $found = false;
            foreach ( is_array( $actual ) ? $actual : [ $actual ] as $text ) {
                $found = $found || self::text_match( $text, $wanted, $operator );
            }
            return 'not_contains' === $operator ? ! $found : $found;
        }
        switch ( $operator ) {
            case 'eq': return self::equal( $actual, $wanted );
            case 'ne': return ! self::equal( $actual, $wanted );
            case 'gt': return $actual > $wanted;
            case 'gte': return $actual >= $wanted;
            case 'lt': return $actual < $wanted;
            case 'lte': return $actual <= $wanted;
        }
        return false;
    }
}
