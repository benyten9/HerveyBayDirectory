<?php
/** Native typed coupon configuration shared by the editor and MCP. */
defined( 'ABSPATH' ) || exit;

final class SWBDPCCouponService {
    const META = [
        'code' => 'swbdpc_coupon_code', 'description' => 'swbdpc_coupon_descrip',
        'type' => 'swbdpc_coupon_type', 'amount' => 'swbdpc_coupon_amount',
        'eligible_plan_ids' => 'swbdpc_fixed_products', 'expiry' => 'swbdpc_coupon_expiry',
        'per_user_limit' => 'swbdpc_coupon_usage_limit',
    ];
    const TYPES = [ 'percentage_dis', 'fixed_cart_dis', 'fixed_product_dis' ];

    /** This is the editor's existing normalization; SQL collation defines equality. */
    public static function normalize_code( string $code ): string {
        return preg_replace( '/[^a-zA-Z0-9_.]/', '', str_replace( ' ', '_', $code ) );
    }

    public static function configuration( int $id ): array {
        $data = [];
        foreach ( self::META as $key => $meta ) {
            $data[$key] = get_post_meta( $id, $meta, true );
        }
        $data['eligible_plan_ids'] = SWBDPCHelperFunctions::normalize_plan_ids( $data['eligible_plan_ids'] );
        return $data;
    }

    public static function plans_active(): bool {
        $plugin = 'directorist-pricing-plans/directorist-pricing-plans.php';
        return in_array( $plugin, (array) get_option( 'active_plugins', [] ), true ) || array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', [] ) );
    }

    public static function plan( int $id ) {
        if ( ! self::plans_active() || ! function_exists( 'directorist_get_pricing_plan_by_id' ) ) {
            return new WP_Error( 'coupon_plan_unavailable', 'An active native pricing-plan service is required.' );
        }
        $plan = directorist_get_pricing_plan_by_id( $id );
        return $plan && ! empty( $plan->is_published ) ? $plan : new WP_Error( 'coupon_plan_invalid', 'Eligible plans must exist and be published.' );
    }

    public static function valid_date( string $date ): bool {
        if ( '' === $date ) { return true; }
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
        return $parsed && $parsed->format( 'Y-m-d' ) === $date;
    }

    public static function collision( string $code, int $except = 0 ): int {
        global $wpdb;
        // All records, including drafts and trash, reserve their code for recovery.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='swbdpc_coupon_code' WHERE p.post_type=%s AND p.ID<>%d AND m.meta_value=%s ORDER BY p.ID LIMIT 1",
            SWBDPC_POST_TYPE, $except, $code
        ) );
    }

    public static function usage( string $code, int $user_id = 0 ): int {
        global $wpdb;
        $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}directorist_orders WHERE coupon_code=%s", $code );
        if ( $user_id ) { $sql .= $wpdb->prepare( ' AND user_id=%d', $user_id ); }
        return (int) $wpdb->get_var( $sql );
    }

    /** Validate the whole effective record before writing any selected field. */
    public static function validate( array $data, int $id = 0 ) {
        if ( array_diff( array_keys( $data ), array_keys( self::META ) ) || array_diff( array_keys( self::META ), array_keys( $data ) ) ) {
            return new WP_Error( 'coupon_fields', 'Provide only the complete typed coupon configuration.' );
        }
        if ( ! is_string( $data['code'] ) || strlen( $data['code'] ) > 128 || ! is_string( $data['description'] ) || strlen( $data['description'] ) > 4000 ) {
            return new WP_Error( 'coupon_text', 'Coupon code or description is invalid or too long.' );
        }
        $data['code'] = self::normalize_code( $data['code'] );
        $data['description'] = sanitize_text_field( $data['description'] );
        if ( '' === $data['code'] || self::collision( $data['code'], $id ) ) {
            return new WP_Error( 'coupon_duplicate', 'This normalized coupon code is empty or already reserved, including in trash.' );
        }
        $old_code = $id ? (string) get_post_meta( $id, self::META['code'], true ) : '';
        if ( $old_code && $old_code !== $data['code'] && self::usage( $old_code ) > 0 ) {
            return new WP_Error( 'coupon_used_code', 'A used code cannot be renamed. Create a new coupon to retain order history.' );
        }
        if ( ! in_array( $data['type'], self::TYPES, true ) ) {
            return new WP_Error( 'coupon_type', 'Unsupported native coupon type.' );
        }
        $amount = $data['amount'];
        if ( ! self::integer_value( $amount ) || ! is_finite( (float) $amount ) || (float) $amount !== floor( (float) $amount ) || $amount <= 0 || $amount > 99999999 || ( 'percentage_dis' === $data['type'] && $amount > 100 ) ) {
            return new WP_Error( 'coupon_amount', 'Use a positive whole-number amount; percentages cannot exceed 100.' );
        }
        $data['amount'] = (int) $amount;
        if ( ! self::integer_value( $data['per_user_limit'] ) || (float) $data['per_user_limit'] !== floor( (float) $data['per_user_limit'] ) || $data['per_user_limit'] < 0 || $data['per_user_limit'] > 1000000 ) {
            return new WP_Error( 'coupon_limit', 'Use a nonnegative integer per-user limit. Zero means unlimited.' );
        }
        $data['per_user_limit'] = (int) $data['per_user_limit'];
        if ( ! is_string( $data['expiry'] ) || ! self::valid_date( $data['expiry'] ) ) {
            return new WP_Error( 'coupon_expiry', 'Use a valid site-local YYYY-MM-DD date or an empty string for no expiry.' );
        }
        if ( ! is_array( $data['eligible_plan_ids'] ) || count( $data['eligible_plan_ids'] ) > 100 ) {
            return new WP_Error( 'coupon_plans', 'Provide at most 100 eligible native plan IDs.' );
        }
        foreach ( $data['eligible_plan_ids'] as $plan_id ) {
            if ( ! is_int( $plan_id ) || $plan_id < 1 ) { return new WP_Error( 'coupon_plans', 'Plan IDs must be positive integers.' ); }
            $plan = self::plan( $plan_id );
            if ( is_wp_error( $plan ) ) { return $plan; }
        }
        $data['eligible_plan_ids'] = array_values( array_unique( $data['eligible_plan_ids'] ) );
        sort( $data['eligible_plan_ids'] );
        if ( 'fixed_product_dis' === $data['type'] && ! $data['eligible_plan_ids'] ) {
            return new WP_Error( 'coupon_plans', 'A fixed-plan coupon requires explicit eligible plan IDs.' );
        }
        // Native percentage and cart discounts apply to every plan; never imply otherwise.
        if ( 'fixed_product_dis' !== $data['type'] && $data['eligible_plan_ids'] ) {
            return new WP_Error( 'coupon_plans', 'Native plan restrictions apply only to fixed_product_dis. Use an empty plan list for other types.' );
        }
        return $data;
    }

    private static function integer_value( $value ): bool {
        return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) || ( is_string( $value ) && 1 === preg_match( '/^[0-9]+(?:\.0+)?$/D', $value ) );
    }

    public static function save( int $id, array $configuration ) {
        self::lock();
        $data = self::validate( $configuration, $id );
        if ( is_wp_error( $data ) ) { return $data; }
        foreach ( self::META as $key => $meta ) {
            update_post_meta( $id, $meta, wp_slash( $data[$key] ) );
            if ( get_post_meta( $id, $meta, true ) != $data[$key] ) {
                return new WP_Error( 'coupon_save_failed', 'Coupon configuration could not be saved.' );
            }
        }
        return $data;
    }

    public static function lock(): void {
        if ( class_exists( '\Directorist\MCP\Safety\Write_Lock' ) ) {
            // Lock even outside MCP, so native uniqueness checks also serialize.
            \Directorist\MCP\Safety\Write_Lock::lock();
            register_shutdown_function( [ '\Directorist\MCP\Safety\Write_Lock', 'unlock' ] );
        }
    }

    /** Unslashed editor fields, converted without truncating unsupported amounts. */
    public static function submitted(): array {
        $input = wp_unslash( $_POST );
        return [
            'code' => $input['swbdpc_coupon_code'] ?? '', 'description' => $input['swbdpc_coupon_descrip'] ?? '',
            'type' => $input['swbdpc_coupon_type'] ?? '', 'amount' => $input['swbdpc_coupon_amount'] ?? '',
            'eligible_plan_ids' => array_map( 'intval', (array) ( $input['swbdpc_fixed_products'] ?? [] ) ),
            'expiry' => $input['swbdpc_coupon_expiry'] ?? '', 'per_user_limit' => $input['swbdpc_coupon_usage_limit'] ?? 1,
        ];
    }

    /** Serialize native post/lifecycle changes before they alter publication state. */
    public static function native_post( array $data, array $postarr ): array {
        if ( SWBDPC_POST_TYPE !== ( $data['post_type'] ?? '' ) ) { return $data; }
        self::lock();
        $id = (int) ( $postarr['ID'] ?? 0 );
        if ( isset( $_POST['swbdpc_coupon_details_cmb_field'] ) && is_string( $_POST['swbdpc_coupon_details_cmb_field'] ) && wp_verify_nonce( $_POST['swbdpc_coupon_details_cmb_field'], 'swbdpc_coupon_details_cmb_action' ) && current_user_can( 'edit_post', $id ) ) {
            $valid = self::validate( self::submitted(), $id );
            if ( is_wp_error( $valid ) ) { wp_die( esc_html( $valid->get_error_message() ), 'Invalid coupon', [ 'response' => 400, 'back_link' => true ] ); }
        }
        return $data;
    }
}
