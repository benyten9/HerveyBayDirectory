<?php
/** Request parsing at native boundaries; all policy and persistence live in the service. */
defined( 'ABSPATH' ) || exit;

final class DCL_Claim_Native {
    public static function lock_request(): void {
        if ( class_exists( '\Directorist\MCP\Safety\Write_Lock' ) && ! \Directorist\MCP\Safety\Write_Lock::is_locked() ) {
            DCL_Claim_Service::lock();
            register_shutdown_function( [ '\Directorist\MCP\Safety\Write_Lock', 'unlock' ] );
        }
    }

    public static function admin_write(): void {
        if ( 'editpost' === ( $_POST['action'] ?? '' ) && isset( $_POST['post_ID'] ) && in_array( get_post_type( absint( $_POST['post_ID'] ) ), [ ATBDP_POST_TYPE, 'dcl_claim_listing' ], true ) ) { self::lock_request(); }
    }

    public static function rest_write( $result, array $handler, WP_REST_Request $request ) {
        if ( ! in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true ) && preg_match( '#^/(?:directorist/v[12]/listings|wp/v2/at_biz_dir)(?:/|$)#', $request->get_route() ) ) { self::lock_request(); }
        return $result;
    }

    public static function before_post( array $data ): array {
        if ( in_array( $data['post_type'] ?? '', [ ATBDP_POST_TYPE, 'dcl_claim_listing' ], true ) && class_exists( '\Directorist\MCP\Safety\Write_Lock' ) ) {
            self::lock_request();
        }
        return $data;
    }

    public static function save_review( int $id ): int {
        if ( 'dcl_claim_listing' !== get_post_type( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! isset( $_POST['dcl_claim_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dcl_claim_details_nonce'] ) ), 'dcl_save_claim_details' ) ) { return $id; }
        try {
            DCL_Claim_Service::check( DCL_Claim_Service::admin() && current_user_can( 'edit_post', $id ), 'Administrator review capability is required.', 'forbidden' );
            DCL_Claim_Service::check( ! isset( $_POST['listing_claimer'] ) || absint( $_POST['listing_claimer'] ) === (int) get_post_meta( $id, '_listing_claimer', true ), 'Stored claimant cannot be replaced.' );
            DCL_Claim_Service::check( ! isset( $_POST['claimed_listing'] ) || absint( $_POST['claimed_listing'] ) === (int) get_post_meta( $id, '_claimed_listing', true ), 'Stored listing cannot be replaced.' );
            $decision = sanitize_text_field( wp_unslash( $_POST['claim_status'] ?? '' ) );
            if ( 'pending' === $decision && 'pending' === get_post_meta( $id, '_claim_status', true ) ) { return $id; }
            DCL_Claim_Service::review( [ 'claim_id' => $id, 'decision' => $decision, 'reason' => sanitize_textarea_field( wp_unslash( $_POST['dcl_review_reason'] ?? '' ) ) ] );
        } catch ( Throwable $error ) {
            set_transient( 'dcl_review_error_' . get_current_user_id(), 'Claim review was rejected. Refresh the record and check its owner, payment, entitlement and decision.', 60 );
        }
        return $id;
    }

    public static function notice(): void {
        $message = get_transient( 'dcl_review_error_' . get_current_user_id() );
        if ( $message ) { delete_transient( 'dcl_review_error_' . get_current_user_id() ); echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>'; }
    }

    public static function save_listing( int $id, $post ): int {
        if ( ! $post || ATBDP_POST_TYPE !== $post->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['dcl_listing_claim_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dcl_listing_claim_details_nonce'] ) ), 'dcl_save_listing_claim_details' ) || ! DCL_Claim_Service::admin() || ! current_user_can( 'edit_post', $id ) ) { return $id; }
        DCL_Claim_Service::atomic( static function () use ( $id ): array {
            DCL_Claim_Service::touch( $id );
            // Native listing edits cannot erase an ownership approval made through a claim.
            if ( get_post_meta( $id, '_dcl_approved_claim', true ) || 'claim_approved' === get_post_meta( $id, '_claim_fee', true ) ) { return []; }
            $mode = sanitize_text_field( wp_unslash( $_POST['claim_fee'] ?? '' ) );
            DCL_Claim_Service::check( in_array( $mode, [ '', 'free_claim', 'static_fee', 'pricing_plan' ], true ), 'Invalid native claim policy.' );
            DCL_Claim_Service::meta( $id, '_claim_fee', $mode );
            DCL_Claim_Service::meta( $id, '_claim_charge', absint( $_POST['claim_charge'] ?? 0 ) );
            DCL_Claim_Service::meta( $id, '_claimed_by_admin', empty( $_POST['claimed_by_admin'] ) ? '' : 1 );
            return [];
        } );
        return $id;
    }

    /** A gateway callback never supplies the winning claimant, plan or featured flag. */
    public static function paid_order( int $order_id ): void {
        try {
            DCL_Claim_Service::atomic( static function () use ( $order_id ): array {
                $order = directorist_order_repository()->get_by_id( $order_id );
                if ( ! $order || 'paid' !== $order->status || 'pricing_plan' !== $order->ref_type || empty( $order->listing_id ) || empty( $order->user_id ) ) { return []; }
                $id = (int) $order->listing_id;
                $claim_id = DCL_Claim_Service::pending( $id, (int) $order->user_id );
                if ( ! $claim_id ) { return []; }
                DCL_Claim_Service::touch( $id, $claim_id, (int) $order->user_id );
                DCL_Claim_Service::check( (int) get_post_meta( $claim_id, '_dcl_plan_id', true ) === (int) $order->ref, 'Paid order plan does not match the submitted claim.' );
                // Persist the server-resolved reference without changing an earlier supplied reference.
                $previous = (int) get_post_meta( $claim_id, '_dcl_order_id', true );
                DCL_Claim_Service::check( ! $previous || $previous === $order_id, 'Claim already has a different purchase reference.' );
                DCL_Claim_Service::meta( $claim_id, '_dcl_order_id', $order_id );
                // Join this existing native unit without opening another transaction.
                self::approve_in_transaction( $claim_id );
                return [];
            } );
        } catch ( Throwable $error ) {
            // Payment remains paid. The pending claim still requires a valid review; no grant is forced.
            do_action( 'directorist_claim_payment_review_required', $order_id );
        }
    }

    private static function approve_in_transaction( int $claim_id ): void {
        // Nested service calls join the existing native payment transaction.
        DCL_Claim_Service::review( [ 'claim_id' => $claim_id, 'decision' => 'approved', 'reason' => '' ], true );
    }
}

add_filter( 'wp_insert_post_data', [ DCL_Claim_Native::class, 'before_post' ], 0 );
add_action( 'admin_notices', [ DCL_Claim_Native::class, 'notice' ] );
add_action( 'admin_init', [ DCL_Claim_Native::class, 'admin_write' ], 0 );
add_filter( 'rest_request_before_callbacks', [ DCL_Claim_Native::class, 'rest_write' ], 0, 3 );
add_action( 'atbdp_before_processing_submitted_listing_frontend', [ DCL_Claim_Native::class, 'lock_request' ], 0 );
