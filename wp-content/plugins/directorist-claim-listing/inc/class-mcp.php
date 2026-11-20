<?php
/** Claim Listing extension API 1 contribution. No alternate MCP server or bulk writer. */
defined( 'ABSPATH' ) || exit;

final class DCL_MCP {
    const OWNER = 'claim-listing';
    const CONTRACT = '1.0.0';

    public static function object( array $properties = [], array $required = [] ): array {
        return [ 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ];
    }
    public static function text( int $max = 200, int $min = 0 ): array { return [ 'type' => 'string', 'minLength' => $min, 'maxLength' => $max ]; }
    public static function id( int $min = 0 ): array { return [ 'type' => 'integer', 'minimum' => $min ]; }
    public static function strings(): array { return [ 'type' => 'array', 'items' => self::text(), 'maxItems' => 20 ]; }
    public static function available(): bool { return defined( 'ATBDP_VERSION' ) && class_exists( '\Directorist\MCP\Safety\Transaction' ); }
    public static function admission( int $user_id ): bool { return $user_id > 0 && get_current_user_id() === $user_id && current_user_can( 'read' ); }

    public static function permitted( string $action, array $args, int $user_id ): bool {
        if ( ! self::admission( $user_id ) || ! self::available() ) { return false; }
        foreach ( self::input( $action )['properties'] as $key => $schema ) {
            if ( ! array_key_exists( $key, $args ) ) { continue; }
            if ( 'integer' === $schema['type'] && ! is_int( $args[ $key ] ) ) { return false; }
            if ( 'string' === $schema['type'] && ! is_string( $args[ $key ] ) ) { return false; }
        }
        if ( 'review-claim' === $action && ! DCL_Claim_Service::admin() ) { return false; }
        if ( 'admin' === ( $args['scope'] ?? '' ) && ! DCL_Claim_Service::admin() ) { return false; }
        if ( isset( $args['claim_id'] ) ) {
            try {
                DCL_Claim_Service::claim( (int) $args['claim_id'] );
                if ( 'review-claim' === $action ) {
                    $listing_id = (int) get_post_meta( (int) $args['claim_id'], '_claimed_listing', true );
                    if ( ! current_user_can( 'edit_post', $listing_id ) ) { return false; }
                }
            } catch ( Throwable $error ) { return false; }
        }
        return true;
    }

    public static function register( $registry ): void {
        global $wpdb;
        $registered = $registry->register_extension( [ 'owner' => self::OWNER, 'plugin' => 'directorist-claim-listing/directorist-claim-listing.php', 'label' => 'Directorist Claim Listing', 'version' => DCL_VERSION, 'contract_version' => self::CONTRACT, 'api_version' => 1, 'capability' => 'read', 'requires' => [ 'directorist/directorist-base.php' ], 'availability' => [ self::class, 'available' ], 'admission' => [ self::class, 'admission' ] ] );
        if ( is_wp_error( $registered ) ) { return; }
        foreach ( [ 'get-schema', 'get-listing-eligibility', 'submit-claim', 'list-claims', 'get-claim', 'review-claim' ] as $action ) {
            $write = in_array( $action, [ 'submit-claim', 'review-claim' ], true );
            $definition = [ 'owner' => self::OWNER, 'action' => $action, 'domain' => 'extension', 'group' => 'Claim Listing', 'description' => self::description( $action ), 'contract_version' => self::CONTRACT, 'write' => $write, 'schema' => self::input( $action ), 'output_schema' => self::output( $action ),
                'discover' => static function ( array $args, int $user_id ) use ( $action ): bool { return self::admission( $user_id ) && ( 'review-claim' !== $action || DCL_Claim_Service::admin() ); },
                'permission' => static function ( array $args, int $user_id ) use ( $action ): bool { return self::permitted( $action, $args, $user_id ); },
                'project' => static function ( array $result, array $args, int $user_id ) use ( $action ): array {
                    DCL_Claim_Service::check( self::permitted( $action, $args, $user_id ), 'Claim access changed.', 'forbidden' );
                    if ( isset( $result['claim_id'] ) ) { DCL_Claim_Service::claim( (int) $result['claim_id'] ); }
                    return array_intersect_key( $result, self::output( $action )['properties'] );
                },
            ];
            if ( $write ) {
                $definition += [ 'preview_schema' => self::preview( $action ), 'tables' => DCL_Claim_Service::tables(), 'invalidate' => [ DCL_Claim_Service::class, 'invalidate' ], 'prepare' => static function ( array $args ) use ( $action ): array {
                    return 'submit-claim' === $action ? DCL_Claim_Service::prepare_submit( $args ) : DCL_Claim_Service::prepare_review( $args );
                } ];
            } else { $definition['read'] = static function ( array $args ) use ( $action ): array { return self::read( $action, $args ); }; }
            $registry->register_tool( $definition );
        }
        self::fields( $registry );
    }

    private static function description( string $action ): string {
        $descriptions = [ 'get-schema' => 'Read effective claim policy, required contact fields, native statuses and permitted operations.', 'get-listing-eligibility' => 'Check safe listing claimability and the current account\'s pending claim.', 'submit-claim' => 'Preview and confirm one pending claim for the current account, or return native checkout required.', 'list-claims' => 'List own claims or an authorized administrator review scope with filtered totals.', 'get-claim' => 'Read an authorized claim, its submitted details and review outcome.', 'review-claim' => 'Preview and confirm approval or decline using the stored claimant and verified native entitlement.' ];
        return $descriptions[ $action ];
    }

    public static function input( string $action ): array {
        $id = self::id( 1 );
        if ( 'get-schema' === $action ) { return self::object( [ 'listing_id' => $id, 'directory_id' => $id ] ); }
        if ( 'get-listing-eligibility' === $action ) { return self::object( [ 'listing_id' => $id ], [ 'listing_id' ] ); }
        if ( 'submit-claim' === $action ) { return self::object( [ 'listing_id' => $id, 'claimer_name' => self::text( 200, 1 ), 'claimer_phone' => self::text( 50, 1 ), 'claimer_details' => self::text( 5000, 1 ), 'plan_id' => $id, 'order_id' => $id ], [ 'listing_id', 'claimer_name', 'claimer_phone', 'claimer_details' ] ); }
        if ( 'get-claim' === $action ) { return self::object( [ 'claim_id' => $id ], [ 'claim_id' ] ); }
        $status = [ 'type' => 'string', 'enum' => [ 'pending', 'approved', 'declined' ] ];
        if ( 'review-claim' === $action ) { return self::object( [ 'claim_id' => $id, 'decision' => [ 'type' => 'string', 'enum' => [ 'approved', 'declined' ] ], 'reason' => self::text( 2000 ) ], [ 'claim_id', 'decision' ] ); }
        return self::object( [ 'scope' => [ 'type' => 'string', 'enum' => [ 'own', 'admin' ] ], 'directory_id' => $id, 'listing_id' => $id, 'status' => $status, 'page' => $id, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ] ] );
    }

    public static function record_schema(): array {
        return self::object( [ 'claim_id' => self::id( 1 ), 'listing_id' => self::id(), 'listing_title' => self::text( 1000 ), 'listing_url' => self::text( 2000 ), 'status' => self::text( 20 ), 'claimer_name' => self::text( 200 ), 'claimer_phone' => self::text( 50 ), 'claimer_details' => self::text( 5000 ), 'review_outcome' => self::text( 2000 ), 'version' => self::text( 64 ), 'allowed_operations' => self::strings() ] );
    }
    public static function output( string $action ): array {
        $bool = [ 'type' => 'boolean' ];
        if ( 'get-schema' === $action ) { return self::object( [ 'enabled' => $bool, 'claimable' => $bool, 'payment_required' => $bool, 'policy_reason' => self::text(), 'listing_id' => self::id(), 'directory_id' => self::id(), 'payment_policy' => self::text( 30 ), 'required_fields' => [ 'type' => 'array', 'maxItems' => 3, 'items' => self::object( [ 'name' => self::text(), 'max_length' => self::id( 1 ), 'required' => $bool ] ) ], 'statuses' => self::strings(), 'allowed_operations' => self::strings(), 'review_decisions' => self::strings() ] ); }
        if ( 'get-listing-eligibility' === $action ) { return self::object( [ 'listing_id' => self::id( 1 ), 'eligible' => $bool, 'reason' => self::text(), 'own_claim_id' => self::id(), 'payment_required' => $bool, 'next_step' => self::text() ] ); }
        if ( 'get-claim' === $action ) { return self::record_schema(); }
        if ( 'list-claims' === $action ) { return self::object( [ 'claims' => [ 'type' => 'array', 'items' => self::record_schema(), 'maxItems' => 100 ], 'total' => self::id(), 'page' => self::id( 1 ), 'per_page' => self::id( 1 ), 'total_pages' => self::id() ] ); }
        if ( 'submit-claim' === $action ) { return self::object( [ 'claim_id' => self::id( 1 ), 'status' => self::text( 20 ), 'checkout_required' => $bool, 'checkout_url' => self::text( 2000 ) ], [ 'claim_id', 'status' ] ); }
        return self::object( [ 'claim_id' => self::id( 1 ), 'listing_id' => self::id( 1 ), 'status' => self::text( 20 ), 'owner_id' => self::id( 1 ), 'no_op' => $bool ], [ 'claim_id', 'status' ] );
    }

    public static function preview( string $action ): array {
        $props = [ 'listing_id' => self::id( 1 ), 'notifications' => self::strings(), 'profile_effect' => self::text() ];
        if ( 'submit-claim' === $action ) { return self::object( $props + [ 'claimant_id' => self::id( 1 ), 'status' => self::text( 20 ), 'checkout_required' => [ 'type' => 'boolean' ], 'existing_claim_id' => self::id() ] ); }
        return self::object( $props + [ 'claim_id' => self::id( 1 ), 'decision' => self::text( 20 ), 'old_owner_id' => self::id( 1 ), 'new_owner_id' => self::id( 1 ), 'plan_id' => self::id(), 'featured' => [ 'type' => 'boolean' ], 'listing_status' => self::text( 30 ), 'expiration_effect' => self::text(), 'no_op' => [ 'type' => 'boolean' ] ] );
    }

    public static function record( int $id ): array {
        DCL_Claim_Service::claim( $id );
        $listing_id = (int) get_post_meta( $id, '_claimed_listing', true );
        $listing = get_post( $listing_id );
        $safe = $listing && ATBDP_POST_TYPE === $listing->post_type && ( DCL_Claim_Service::admin() || \Directorist\MCP\Runtime\Access_Control::public_listing( $listing ) );
        $status = (string) get_post_meta( $id, '_claim_status', true );
        $record = [ 'claim_id' => $id, 'listing_id' => $safe ? $listing_id : 0, 'listing_title' => $safe ? (string) get_the_title( $listing_id ) : '', 'listing_url' => $safe ? (string) get_permalink( $listing_id ) : '', 'status' => $status,
            'allowed_operations' => DCL_Claim_Service::admin() && 'pending' === $status && current_user_can( 'edit_post', $listing_id ) ? [ 'review-claim' ] : [],
        ];
        foreach ( [ 'claimer_name' => 200, 'claimer_phone' => 50, 'claimer_details' => 5000, 'review_outcome' => 2000 ] as $key => $max ) {
            $value = (string) get_post_meta( $id, 'review_outcome' === $key ? '_dcl_review_reason' : '_' . $key, true );
            $record[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
        }
        $record['version'] = hash( 'sha256', wp_json_encode( [ get_post_meta( $id ), $listing ? (array) $listing : [] ] ) );
        return $record;
    }

    public static function read( string $action, array $args ): array {
        if ( 'get-claim' === $action ) { return self::record( (int) $args['claim_id'] ); }
        if ( 'get-listing-eligibility' === $action ) { return DCL_Claim_Service::eligibility( (int) $args['listing_id'] ); }
        if ( 'get-schema' === $action ) {
            $id = (int) ( $args['listing_id'] ?? 0 );
            if ( $id ) { DCL_Claim_Service::listing( $id ); }
            $directory_id = $id ? (int) directorist_get_listings_directory_type( $id ) : (int) ( $args['directory_id'] ?? 0 );
            if ( $directory_id ) { $term = get_term( $directory_id, ATBDP_DIRECTORY_TYPE ); DCL_Claim_Service::check( $term && ! is_wp_error( $term ), 'Directory unavailable.', 'not_found' ); }
            $fields = [];
            foreach ( [ 'claimer_name' => 200, 'claimer_phone' => 50, 'claimer_details' => 5000 ] as $key => $max ) { $fields[] = [ 'name' => $key, 'required' => true, 'max_length' => $max ]; }
            $enabled = (bool) get_directorist_option( 'enable_claim_listing', 1 );
            $eligibility = $id ? DCL_Claim_Service::eligibility( $id ) : null;
            $claimable = $eligibility ? $eligibility['eligible'] : $enabled;
            $policy = $id ? DCL_Claim_Service::mode( $id ) : (string) get_directorist_option( 'claim_charge_by', 'free_claim' );
            $result = [
                'enabled' => $enabled, 'claimable' => $claimable, 'payment_required' => in_array( $policy, [ 'static_fee', 'pricing_plan' ], true ), 'policy_reason' => $eligibility ? $eligibility['reason'] : ( $enabled ? '' : 'disabled' ),
                'listing_id' => $id, 'directory_id' => $directory_id, 'payment_policy' => $policy, 'required_fields' => $fields, 'statuses' => array_keys( dcl_claim_status() ),
                'allowed_operations' => array_merge( [ 'get-listing-eligibility', 'list-claims', 'get-claim' ], $claimable ? [ 'submit-claim' ] : [], DCL_Claim_Service::admin() && $enabled ? [ 'review-claim' ] : [] ),
            ];
            if ( DCL_Claim_Service::admin() ) { $result['review_decisions'] = [ 'approved', 'declined' ]; }
            return $result;
        }
        $meta = [];
        if ( 'admin' !== ( $args['scope'] ?? 'own' ) ) { $meta[] = [ 'key' => '_listing_claimer', 'value' => get_current_user_id() ]; }
        foreach ( [ 'listing_id' => '_claimed_listing', 'status' => '_claim_status' ] as $key => $field ) { if ( isset( $args[ $key ] ) ) { $meta[] = [ 'key' => $field, 'value' => $args[ $key ] ]; } }
        $where = static function ( $sql ) use ( $args ) {
            if ( isset( $args['directory_id'] ) ) {
                global $wpdb;
                $sql .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} cl JOIN {$wpdb->term_relationships} dl ON dl.object_id = CAST(cl.meta_value AS UNSIGNED) JOIN {$wpdb->term_taxonomy} dt ON dt.term_taxonomy_id = dl.term_taxonomy_id WHERE cl.post_id = {$wpdb->posts}.ID AND cl.meta_key = '_claimed_listing' AND dt.taxonomy = %s AND dt.term_id = %d)", ATBDP_DIRECTORY_TYPE, (int) $args['directory_id'] );
            }
            return $sql;
        };
        add_filter( 'posts_where', $where );
        try {
            $ids = get_posts( [ 'post_type' => 'dcl_claim_listing', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => $meta, 'orderby' => 'ID', 'order' => 'DESC', 'suppress_filters' => false ] );
        } finally { remove_filter( 'posts_where', $where ); }
        $ids = array_values( array_filter( $ids, static function ( $id ): bool { try { DCL_Claim_Service::claim( (int) $id ); return true; } catch ( Throwable $error ) { return false; } } ) );
        $page = (int) ( $args['page'] ?? 1 ); $per_page = (int) ( $args['per_page'] ?? 20 );
        return [ 'claims' => array_map( [ self::class, 'record' ], array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ) ), 'total' => count( $ids ), 'page' => $page, 'per_page' => $per_page, 'total_pages' => (int) ceil( count( $ids ) / $per_page ) ];
    }

    private static function fields( $registry ): void {
        global $wpdb;
        // Use the native definitions for labels, defaults and available charging choices.
        $native = DCL_Base::instance()->atbdp_listing_type_settings_field_list( [] );
        $keys = [
            'enable_claim_listing', 'claim_widget_title', 'claim_widget_description',
            'claim_charge_by', 'claim_listing_price', 'claim_now', 'admin_only_claimable',
            'verified_badge', 'verified_text',
            'email_sub_new_claim', 'email_tmpl_new_claim',
            'email_sub_approved_claim', 'email_tmpl_approved_claim',
            'email_sub_declined_claim', 'email_tmpl_declined_claim',
        ];
        foreach ( $keys as $key ) {
            $field = $native[ $key ];
            $switch = 'toggle' === $field['type'];
            $body = 0 === strpos( $key, 'email_tmpl_' );
            $subject = 0 === strpos( $key, 'email_sub_' );
            $schema = $switch ? [ 'type' => 'boolean' ] : self::text( $body ? 20000 : ( 'claim_widget_description' === $key ? 5000 : 200 ) );
            $default = $field['value'];
            if ( 'claim_charge_by' === $key ) {
                $schema = [ 'type' => 'string', 'enum' => array_column( $field['options'], 'value' ) ];
            } elseif ( 'claim_listing_price' === $key ) {
                $schema = [ 'type' => 'string', 'maxLength' => 16, 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]+)?$' ];
                $default = \Directorist\MCP\Tool_Providers\Orders\Money::stored( $default, atbdp_get_payment_currency() );
            }
            $label = $field['label'];
            $group = 'enable_claim_listing' === $key ? 'extension_settings/extensions_general' : 'extension_settings/claim_submenu/claim_section';
            if ( $body || $subject ) {
                $event = str_replace( [ 'email_sub_', 'email_tmpl_' ], '', $key );
                $events = [ 'new_claim' => __( 'Claim Submitted', 'directorist-claim-listing' ), 'approved_claim' => __( 'Claim Approved', 'directorist-claim-listing' ), 'declined_claim' => __( 'Claim Declined', 'directorist-claim-listing' ) ];
                $label = $events[ $event ] . ': ' . $label;
                $section = 'new_claim' === $event ? 'new_claim' : $event . '_confirmation';
                $group = 'email_settings/email_templates/' . $section;
            }
            $registry->register_field_adapter( [
                'owner' => self::OWNER, 'domain' => 'settings', 'key' => $key,
                'contract_version' => self::CONTRACT, 'schema' => $schema, 'default' => $default,
                'label' => $label,
                'group' => $group,
                'dependencies' => $field['show-if'] ?? [],
                'sensitive' => false, 'writable' => true, 'resettable' => true,
                'storage_adapter' => 'directorist', 'tables' => [ $wpdb->options ],
                'invalidate' => [ DCL_Claim_Service::class, 'invalidate' ],
                'permission' => [ DCL_Claim_Service::class, 'admin' ],
                'read' => static function () use ( $key, $default, $switch ) {
                    $saved = (array) get_option( 'atbdp_option', [] );
                    $value = $saved[ $key ] ?? $default;
                    if ( 'claim_listing_price' === $key ) {
                        return \Directorist\MCP\Tool_Providers\Orders\Money::stored( $value, atbdp_get_payment_currency() );
                    }
                    return $switch ? (bool) $value : (string) $value;
                },
                'validate' => static function ( $value ) use ( $switch, $key, $body ) {
                    DCL_Claim_Service::check( DCL_Claim_Service::admin(), 'Administrator settings access is required.', 'forbidden' );
                    DCL_Claim_Service::check( $switch ? is_bool( $value ) : is_string( $value ), 'Invalid claim setting.' );
                    if ( $switch ) { return $value; }
                    if ( 'claim_charge_by' === $key ) {
                        $fields = DCL_Base::instance()->atbdp_listing_type_settings_field_list( [] );
                        DCL_Claim_Service::check( in_array( $value, array_column( $fields[ $key ]['options'], 'value' ), true ), 'Select an available native claim charging method.' );
                        return $value;
                    }
                    if ( 'claim_listing_price' === $key ) {
                        $currency = atbdp_get_payment_currency();
                        // Match the native ledger's amount limits without accepting float input.
                        \Directorist\MCP\Tool_Providers\Orders\Money::native( $value, $currency );
                        return \Directorist\MCP\Tool_Providers\Orders\Money::decimal( \Directorist\MCP\Tool_Providers\Orders\Money::minor( $value, $currency ), $currency );
                    }
                    if ( $body ) { return wp_kses_post( $value ); }
                    return 'claim_widget_description' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
                },
                'write' => static function () { return new WP_Error( 'native_settings_required', 'Use the core settings writer.' ); },
            ] );
        }
        foreach ( [ 'claim_listing', 'verified_badge' ] as $key ) {
            $options = 'claim_listing' === $key ? [ 'custom_block_id' => self::text( 200 ), 'custom_block_classes' => self::text( 500 ) ] : [];
            $native_options = [];
            foreach ( $options as $name => $schema ) { $native_options[ $name ] = [ 'type' => 'text', 'value' => '' ]; }
            $registry->register_field_adapter( [
                'owner' => self::OWNER, 'domain' => 'builder', 'key' => $key, 'contract_version' => self::CONTRACT,
                'schema' => self::object( $options + [ 'label' => self::text( 200 ) ] ), 'options' => $native_options,
                'default' => (object) [], 'sensitive' => false, 'writable' => true, 'storage_adapter' => 'directorist',
                'tables' => [ $wpdb->termmeta, $wpdb->options ], 'invalidate' => [ DCL_Claim_Service::class, 'invalidate' ],
                'permission' => [ DCL_Claim_Service::class, 'admin' ], 'read' => static function () { return (object) []; },
                'validate' => static function ( $value ) {
                    DCL_Claim_Service::check( DCL_Claim_Service::admin(), 'Administrator builder access is required.', 'forbidden' );
                    $value = (array) $value;
                    foreach ( $value as $key => $text ) {
                        if ( 'custom_block_id' === $key ) { $value[ $key ] = sanitize_html_class( $text ); }
                        elseif ( 'custom_block_classes' === $key ) { $value[ $key ] = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\\s+/', trim( $text ) ) ) ) ); }
                        else { $value[ $key ] = sanitize_text_field( $text ); }
                    }
                    return $value ?: (object) [];
                },
                'write' => static function () { return new WP_Error( 'native_builder_required', 'Use the core native builder writer.' ); },
            ] );
        }
    }
}

add_action( 'directorist_mcp_register', [ DCL_MCP::class, 'register' ] );
