<?php
/** Shared claim policy and transactional native writer. */
defined( 'ABSPATH' ) || exit;

final class DCL_Claim_Service {
    private static $posts = [];
    private static $users = [];
    private static $depth = 0;

    public static function check( $condition, string $message, string $code = 'invalid_claim' ): void {
        if ( $condition ) { return; }
        if ( class_exists( '\Directorist\MCP\Support\Failure' ) ) {
            throw new \Directorist\MCP\Support\Failure( $code, $message );
        }
        throw new RuntimeException( $message );
    }

    public static function admin(): bool {
        return is_user_logged_in() && current_user_can( 'manage_options' );
    }

    public static function tables(): array {
        global $wpdb;
        $tables = [ $wpdb->posts, $wpdb->postmeta, $wpdb->users, $wpdb->usermeta, $wpdb->options ];
        foreach ( [ 'orders', 'payments', 'refunds' ] as $table ) { $tables[] = $wpdb->prefix . 'directorist_' . $table; }
        if ( function_exists( 'is_pricing_plans_active' ) && is_pricing_plans_active() ) {
            self::check( class_exists( '\DirectoristPricingPlan\App\MCP\Integration' ), 'The native pricing entitlement adapter is unavailable.', 'adapter_unavailable' );
            $tables = array_merge( $tables, \DirectoristPricingPlan\App\MCP\Integration::tables() );
        }
        return array_values( array_unique( $tables ) );
    }

    /** Native routes and MCP use the same site-wide lock, even with MCP disabled. */
    public static function lock(): void {
        self::check( class_exists( '\Directorist\MCP\Safety\Write_Lock' ), 'Update Directorist before changing claims.', 'adapter_unavailable' );
        \Directorist\MCP\Safety\Write_Lock::lock();
    }

    /** Join the MCP transaction, or create one for a native request. */
    public static function atomic( callable $writer ): array {
        self::check( class_exists( '\Directorist\MCP\Safety\Write_Lock' ), 'Update Directorist before changing claims.', 'adapter_unavailable' );
        $owned = ! \Directorist\MCP\Safety\Write_Lock::is_locked();
        self::lock();
        if ( self::$depth || \Directorist\MCP\Safety\Write_Guard::is_mutating() ) {
            return $writer();
        }
        ++self::$depth;
        try {
            return \Directorist\MCP\Safety\Transaction::atomic( $writer, self::tables(), [ self::class, 'invalidate' ] );
        } finally {
            --self::$depth;
            if ( $owned ) { \Directorist\MCP\Safety\Write_Lock::unlock(); }
        }
    }

    public static function in_transaction(): bool {
        return self::$depth > 0 || ( class_exists( '\Directorist\MCP\Safety\Write_Guard' ) && \Directorist\MCP\Safety\Write_Guard::is_mutating() );
    }

    public static function invalidate(): void {
        foreach ( array_keys( self::$posts ) as $id ) { clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' ); }
        foreach ( array_keys( self::$users ) as $id ) { clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); }
        if ( class_exists( '\DirectoristPricingPlan\App\MCP\Integration' ) ) { \DirectoristPricingPlan\App\MCP\Integration::invalidate(); }
    }

    public static function touch( int $listing_id, int $claim_id = 0, int $user_id = 0 ): void {
        foreach ( [ $listing_id, $claim_id ] as $id ) {
            if ( $id ) { self::$posts[ $id ] = true; clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' ); }
        }
        if ( $user_id ) { self::$users[ $user_id ] = true; clean_user_cache( $user_id ); }
    }

    public static function listing( int $id ): WP_Post {
        $post = get_post( $id );
        self::check( $post && ATBDP_POST_TYPE === $post->post_type && ( self::admin() || \Directorist\MCP\Runtime\Access_Control::public_listing( $post ) ), 'Listing unavailable.', 'not_found' );
        return $post;
    }

    /** Claim privacy is independent of listing ownership. */
    public static function claim( int $id ): WP_Post {
        $claim = get_post( $id );
        self::check( $claim && 'dcl_claim_listing' === $claim->post_type && 'publish' === $claim->post_status && ( self::admin() ? current_user_can( 'edit_post', $id ) : get_current_user_id() > 0 && get_current_user_id() === (int) get_post_meta( $id, '_listing_claimer', true ) ), 'Claim unavailable.', 'not_found' );
        return $claim;
    }

    public static function mode( int $listing_id ): string {
        $mode = get_post_meta( $listing_id, '_claim_fee', true );
        return (string) ( '' !== $mode ? $mode : get_directorist_option( 'claim_charge_by', 'free_claim' ) );
    }

    public static function pending( int $listing_id, int $user_id ): int {
        $ids = get_posts( [ 'post_type' => 'dcl_claim_listing', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => [
            [ 'key' => '_claimed_listing', 'value' => $listing_id ], [ 'key' => '_listing_claimer', 'value' => $user_id ], [ 'key' => '_claim_status', 'value' => 'pending' ],
        ] ] );
        return $ids ? (int) $ids[0] : 0;
    }

    public static function eligibility( int $id ): array {
        try { $post = self::listing( $id ); } catch ( Throwable $error ) {
            return [ 'listing_id' => $id, 'eligible' => false, 'reason' => 'unavailable', 'own_claim_id' => 0, 'payment_required' => false, 'next_step' => 'unavailable' ];
        }
        $reason = '';
        if ( ! get_directorist_option( 'enable_claim_listing', 1 ) ) { $reason = 'disabled'; }
        elseif ( ! \Directorist\MCP\Runtime\Access_Control::public_listing( $post ) ) { $reason = 'listing_visibility'; }
        elseif ( get_post_meta( $id, '_claimed_by_admin', true ) || 'claim_approved' === self::mode( $id ) ) { $reason = 'already_claimed'; }
        elseif ( (int) $post->post_author === get_current_user_id() ) { $reason = 'already_owner'; }
        elseif ( get_directorist_option( 'admin_only_claimable', 0 ) && ! user_can( (int) $post->post_author, 'manage_options' ) ) { $reason = 'listing_policy'; }
        elseif ( function_exists( 'directorist_is_listing_feature_available' ) && ! directorist_is_listing_feature_available( 'claim_listing', $id ) ) { $reason = 'listing_policy'; }
        elseif ( ! in_array( self::mode( $id ), [ 'free_claim', 'static_fee', 'pricing_plan' ], true ) ) { $reason = 'unsupported_policy'; }
        $existing = self::pending( $id, get_current_user_id() );
        return [ 'listing_id' => $id, 'eligible' => '' === $reason && ! $existing, 'reason' => $reason ?: ( $existing ? 'own_pending_claim' : '' ), 'own_claim_id' => $existing, 'payment_required' => 'free_claim' !== self::mode( $id ), 'next_step' => $existing ? 'get-claim' : ( '' === $reason ? 'submit-claim' : 'unavailable' ) ];
    }

    public static function fields( array $args ): array {
        $out = [];
        foreach ( [ 'claimer_name' => 200, 'claimer_phone' => 50, 'claimer_details' => 5000 ] as $key => $max ) {
            self::check( isset( $args[ $key ] ) && is_string( $args[ $key ] ), 'Name, phone and claim details are required.' );
            $out[ $key ] = 'claimer_details' === $key ? sanitize_textarea_field( $args[ $key ] ) : sanitize_text_field( $args[ $key ] );
            $length = function_exists( 'mb_strlen' ) ? mb_strlen( $out[ $key ] ) : strlen( $out[ $key ] );
            self::check( $length > 0 && $length <= $max, 'A required claim field is empty or too long.' );
        }
        return $out;
    }

    /** Pure entitlement resolution: client order/plan IDs never confer payment. */
    public static function payment( int $listing_id, int $user_id, int $plan_id, int $order_id ): array {
        $mode = self::mode( $listing_id );
        // Native checkout occurs after submission. Find only a paid order belonging to this claim.
        if ( ! $order_id && in_array( $mode, [ 'static_fee', 'pricing_plan' ], true ) && function_exists( 'directorist_order_repository' ) ) {
            $query = directorist_order_repository()->get_query_builder()->where( 'user_id', $user_id )->where( 'listing_id', $listing_id )->where( 'status', 'paid' )->where( 'ref_type', 'static_fee' === $mode ? 'claim_listing' : 'pricing_plan' );
            if ( $plan_id ) { $query->where( 'ref', $plan_id ); }
            $candidate = $query->order_by_desc( 'id' )->first();
            if ( $candidate ) { $order_id = (int) $candidate->id; }
        }
        $result = [ 'mode' => $mode, 'plan_id' => $plan_id, 'order_id' => $order_id, 'verified' => 'free_claim' === $mode, 'checkout_url' => '', 'featured' => (bool) get_post_meta( $listing_id, '_featured', true ), 'package' => null, 'plan' => null ];
        $order = null;
        if ( $order_id ) {
            self::check( function_exists( 'directorist_order_repository' ), 'Native order adapter unavailable.' );
            $order = directorist_order_repository()->get_by_id( $order_id );
            self::check( $order && (int) $order->user_id === $user_id && (int) $order->listing_id === $listing_id && 'paid' === $order->status, 'Purchase reference is not a paid purchase for this claimant and listing.', 'entitlement_blocked' );
            $result['order'] = (array) $order;
        }
        if ( 'static_fee' === $mode ) {
            $fee = get_post_meta( $listing_id, '_claim_charge', true );
            $currency = (string) atbdp_get_payment_currency();
            $fee = '' !== $fee && self::minor( $fee, $currency ) > 0 ? $fee : get_directorist_option( 'claim_listing_price', 0 );
            if ( $order ) {
                self::check( 'claim_listing' === $order->ref_type && self::minor( $order->amount, $currency ) >= self::minor( $fee, $currency ) && self::minor( $order->amount, $currency ) > 0 && (string) $order->currency === $currency && self::paid( $order ), 'Claim payment does not match the native fee.', 'entitlement_blocked' );
                $result['verified'] = true;
            }
            $result['fee'] = (string) $fee;
            $result['checkout_url'] = directorist_get_checkout_page_url( 'claim_listing', [ 'listing_id' => $listing_id ] );
            self::check( ! $plan_id, 'Static-fee claims do not accept a plan reference.' );
        } elseif ( 'pricing_plan' === $mode ) {
            self::check( is_pricing_plans_active() && $plan_id && function_exists( 'directorist_get_pricing_plan_by_id' ), 'Select an available native pricing plan.', 'entitlement_blocked' );
            \DirectoristPricingPlan\App\MCP\Entitlements::payment_adapter();
            $plan = directorist_get_pricing_plan_by_id( $plan_id );
            self::check( $plan && ! empty( $plan->is_published ) && (int) $plan->directory_type_id === (int) directorist_get_listings_directory_type( $listing_id ), 'The plan does not belong to this listing directory.', 'entitlement_blocked' );
            $package = directorist_user_package_repository()->get_package_by_plan( $user_id, $plan_id );
            if ( $order ) { self::check( 'pricing_plan' === $order->ref_type && (int) $order->ref === $plan_id && self::paid( $order ), 'Order and plan do not match a settled purchase.', 'entitlement_blocked' ); }
            $result['plan'] = (array) $plan;
            $features = directorist_pricing_plans_singleton( \DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository::class )->get( $plan );
            foreach ( $features as $feature ) { self::check( 'claim_listing' !== $feature->key || ! empty( $feature->is_enabled ), 'The selected plan does not permit claim listing.', 'entitlement_blocked' ); }
            $result['features'] = $features;
            $result['package'] = $package ? (array) $package : null;
            $result['checkout_url'] = directorist_get_checkout_page_url( 'plan', [ 'listing_id' => $listing_id, 'plan_id' => $plan_id ] );
            if ( $package && \DirectoristPricingPlan\App\MCP\Reader::active( $package ) && empty( $package->is_legacy ) ) {
                $proof = directorist_order_repository()->get_by_id( (int) $package->last_order_id );
                $valid = $proof && 'paid' === $proof->status && (int) $proof->user_id === $user_id && 'pricing_plan' === $proof->ref_type && (int) $proof->ref === $plan_id && (int) $package->directory_type_id === (int) $plan->directory_type_id && self::paid( $proof );
                if ( $valid ) {
                    $currency = (string) atbdp_get_payment_currency();
                    $minimum = self::minor( $plan->price, $currency );
                    if ( ! empty( $plan->is_taxable ) ) { $minimum += self::minor( directorist_compute_fixed_or_percent_amount( $plan->tax_type, (float) $plan->tax_rate, (float) $plan->price ), $currency ); }
                    $valid = (string) $proof->currency === $currency && self::minor( $proof->amount, $currency ) >= $minimum;
                }
                if ( 'pay_per_listing' === $plan->type ) { $valid = $valid && $order && (int) $proof->id === $order_id; }
                $total = directorist_package_usage()->get_total_uses( $user_id, $plan );
                $featured = directorist_package_usage()->get_featured_uses( $user_id, $plan );
                $result['usage'] = [ $total, $featured ];
                $result['proof'] = $proof ? (array) $proof : null;
                $result['featured'] = 'pay_per_listing' === $plan->type ? ! empty( $plan->is_featured ) : $result['featured'];
                $result['verified'] = (bool) ( $valid && 0 !== (int) $total['remaining'] && ( ! $result['featured'] || 0 !== (int) $featured['remaining'] ) );
            }
        } else {
            self::check( 'free_claim' === $mode, 'Unsupported claim payment policy.', 'entitlement_blocked' );
            self::check( ! $order_id && ! $plan_id, 'Free claims do not accept purchase references.' );
        }
        return $result;
    }

    /** Everything whose change must invalidate confirmation, never returned as public output. */
    public static function state( int $listing_id, int $claim_id, array $payment ): array {
        return [ 'listing' => (array) get_post( $listing_id ), 'listing_meta' => get_post_meta( $listing_id ), 'claim' => $claim_id ? (array) get_post( $claim_id ) : [], 'claim_meta' => $claim_id ? get_post_meta( $claim_id ) : [], 'settings' => get_option( 'atbdp_option', [] ), 'directory' => get_term_meta( (int) directorist_get_listings_directory_type( $listing_id ) ), 'payment' => $payment ];
    }

    public static function prepare_submit( array $args ): array {
        self::check( is_user_logged_in() && current_user_can( 'read' ), 'Authentication is required.', 'forbidden' );
        $id = (int) $args['listing_id'];
        $eligibility = self::eligibility( $id );
        $fields = self::fields( $args );
        self::check( $eligibility['eligible'] || ( $eligibility['own_claim_id'] && 'own_pending_claim' === $eligibility['reason'] ), 'Listing cannot be claimed.', 'claim_ineligible' );
        $payment = self::payment( $id, get_current_user_id(), (int) ( $args['plan_id'] ?? 0 ), (int) ( $args['order_id'] ?? 0 ) );
        $existing = $eligibility['own_claim_id'];
        if ( $existing ) {
            foreach ( $fields as $key => $value ) { self::check( (string) get_post_meta( $existing, '_' . $key, true ) === $value, 'A different pending claim already exists.', 'claim_conflict' ); }
            self::check( (int) get_post_meta( $existing, '_dcl_plan_id', true ) === $payment['plan_id'] && ( ! ( $args['order_id'] ?? 0 ) || (int) get_post_meta( $existing, '_dcl_order_id', true ) === (int) $args['order_id'] ), 'Pending claim purchase references differ.', 'claim_conflict' );
        }
        return [ 'state' => self::state( $id, $existing, $payment ), 'preview' => [ 'listing_id' => $id, 'claimant_id' => get_current_user_id(), 'status' => 'pending', 'checkout_required' => ! $payment['verified'], 'notifications' => DCL_Claim_Outbox::recipients( 'submitted' ), 'profile_effect' => '', 'existing_claim_id' => $existing ], 'execute' => static function () use ( $args ): array { return self::submit( $args ); } ];
    }

    public static function submit( array $args ): array {
        return self::atomic( static function () use ( $args ): array {
            $id = (int) $args['listing_id'];
            self::touch( $id );
            self::prepare_submit( $args );
            $existing = self::pending( $id, get_current_user_id() );
            $payment = self::payment( $id, get_current_user_id(), (int) ( $args['plan_id'] ?? 0 ), (int) ( $args['order_id'] ?? 0 ) );
            if ( ! $existing ) {
                $fields = self::fields( $args );
                $existing = wp_insert_post( [ 'post_type' => 'dcl_claim_listing', 'post_status' => 'publish', 'post_title' => get_the_title( $id ), 'post_author' => get_current_user_id(), 'comment_status' => 'closed' ], true );
                self::check( ! is_wp_error( $existing ) && $existing, 'Could not create claim.', 'operation_failed' );
                self::touch( $id, (int) $existing );
                foreach ( $fields as $key => $value ) { self::meta( (int) $existing, '_' . $key, $value ); }
                foreach ( [ '_listing_claimer' => get_current_user_id(), '_claimed_listing' => $id, '_claim_status' => 'pending', '_dcl_owner' => (int) get_post_field( 'post_author', $id ), '_dcl_plan_id' => $payment['plan_id'], '_dcl_order_id' => $payment['order_id'], '_dcl_policy' => $payment['mode'] ] as $key => $value ) { self::meta( (int) $existing, $key, $value ); }
                DCL_Claim_Outbox::enqueue( (int) $existing, 'submitted' );
                DCL_Claim_Outbox::enqueue_event( (int) $existing );
            }
            return [ 'claim_id' => (int) $existing, 'listing_id' => $id, 'status' => 'pending', 'checkout_required' => ! $payment['verified'], 'checkout_url' => $payment['verified'] ? '' : $payment['checkout_url'] ];
        } );
    }

    public static function prepare_review( array $args, bool $system = false ): array {
        $claim = $system ? get_post( (int) $args['claim_id'] ) : self::claim( (int) $args['claim_id'] );
        self::check( $claim && 'dcl_claim_listing' === $claim->post_type && 'publish' === $claim->post_status, 'Claim unavailable.', 'not_found' );
        $id = (int) get_post_meta( $claim->ID, '_claimed_listing', true );
        $listing = get_post( $id );
        $user = (int) get_post_meta( $claim->ID, '_listing_claimer', true );
        self::check( $listing && ATBDP_POST_TYPE === $listing->post_type && get_userdata( $user ), 'Claim target or claimant unavailable.', 'not_found' );
        self::check( user_can( $user, 'read' ) && empty( get_userdata( $user )->spam ) && empty( get_userdata( $user )->deleted ), 'The stored claimant is no longer eligible.', 'claim_ineligible' );
        self::check( $system || ( self::admin() && current_user_can( 'edit_post', $id ) && current_user_can( 'edit_post', $claim->ID ) ), 'Claim review requires administrator and native edit capabilities.', 'forbidden' );
        self::check( get_directorist_option( 'enable_claim_listing', 1 ), 'Claim listing is disabled.', 'unavailable' );
        $decision = $args['decision'];
        self::check( in_array( $decision, [ 'approved', 'declined' ], true ), 'Use an approved or declined decision.' );
        $reason = sanitize_textarea_field( $args['reason'] ?? '' );
        self::check( strlen( $reason ) <= 2000, 'Review reason is too long.' );
        $status = (string) get_post_meta( $claim->ID, '_claim_status', true );
        self::check( 'pending' === $status || $status === $decision, 'Unsupported claim transition.', 'claim_conflict' );
        if ( $status === $decision ) { self::check( (string) get_post_meta( $claim->ID, '_dcl_review_reason', true ) === $reason, 'A completed decision cannot be edited.', 'claim_conflict' ); }
        $payment = [];
        if ( 'approved' === $decision && 'pending' === $status ) {
            self::check( ! get_post_meta( $id, '_claimed_by_admin', true ) && 'claim_approved' !== self::mode( $id ), 'Another claim already owns this listing.', 'claim_conflict' );
            self::check( metadata_exists( 'post', $claim->ID, '_dcl_owner' ) && (int) get_post_meta( $claim->ID, '_dcl_owner', true ) === (int) $listing->post_author && (int) $listing->post_author !== $user, 'Listing owner changed or the legacy claim has no ownership baseline. Submit a new claim.', 'state_changed' );
            self::check( (string) get_post_meta( $claim->ID, '_dcl_policy', true ) === self::mode( $id ), 'Claim payment policy changed. Submit a new claim.', 'state_changed' );
            self::check( \Directorist\MCP\Runtime\Access_Control::public_listing( $listing ), 'Listing is no longer public.', 'claim_ineligible' );
            self::check( ! get_directorist_option( 'admin_only_claimable', 0 ) || user_can( (int) $listing->post_author, 'manage_options' ), 'Listing claim policy changed.', 'claim_ineligible' );
            self::check( ! function_exists( 'directorist_is_listing_feature_available' ) || directorist_is_listing_feature_available( 'claim_listing', $id ), 'Listing entitlement no longer permits claims.', 'claim_ineligible' );
            $payment = self::payment( $id, $user, (int) get_post_meta( $claim->ID, '_dcl_plan_id', true ), (int) get_post_meta( $claim->ID, '_dcl_order_id', true ) );
            self::check( $payment['verified'], 'Verified native payment or active plan entitlement is required.', 'entitlement_blocked' );
            $old_plan = (int) get_post_meta( $id, function_exists( 'directorist_plan_key' ) ? directorist_plan_key() : '_plan_id', true );
            self::check( ! $old_plan || ! empty( $payment['plan'] ), 'The existing listing plan requires a validated replacement entitlement.', 'entitlement_blocked' );
        }
        return [ 'state' => self::state( $id, (int) $claim->ID, $payment ) + [ 'profile' => get_user_meta( $user, '_user_type', true ), 'claimant' => [ 'id' => $user, 'status' => (int) get_userdata( $user )->user_status ] ], 'preview' => [
            'claim_id' => (int) $claim->ID, 'listing_id' => $id, 'decision' => $decision, 'old_owner_id' => (int) $listing->post_author, 'new_owner_id' => 'approved' === $decision ? $user : (int) $listing->post_author,
            'plan_id' => (int) ( $payment['plan_id'] ?? 0 ), 'featured' => (bool) ( $payment['featured'] ?? get_post_meta( $id, '_featured', true ) ), 'profile_effect' => 'approved' === $decision && 'pending' === $status ? '_user_type=author' : '',
            'listing_status' => ! empty( $payment['plan'] ) ? ( 'publish' === directorist_get_directory_meta( directorist_get_listings_directory_type( $id ), 'edit_listing_status' ) ? 'publish' : 'pending' ) : $listing->post_status,
            'expiration_effect' => self::expiration_effect( $payment ), 'notifications' => $status === $decision ? [] : DCL_Claim_Outbox::recipients( $decision ), 'no_op' => $status === $decision,
        ], 'execute' => static function () use ( $args, $system ): array { return self::review( $args, $system ); } ];
    }

    public static function review( array $args, bool $system = false ): array {
        return self::atomic( static function () use ( $args, $system ): array {
            return DCL_Claim_Outbox::capture( (int) $args['claim_id'], (string) $args['decision'], static function () use ( $args, $system ): array {
                $claim_id = (int) $args['claim_id'];
                $id = (int) get_post_meta( $claim_id, '_claimed_listing', true );
                $user = (int) get_post_meta( $claim_id, '_listing_claimer', true );
                self::touch( $id, $claim_id, $user );
                $plan = self::prepare_review( $args, $system );
                $decision = $args['decision'];
                if ( ! $plan['preview']['no_op'] ) {
                    if ( 'approved' === $decision ) {
                        global $wpdb;
                        $old = (int) get_post_field( 'post_author', $id );
                        self::check( 1 === $wpdb->update( $wpdb->posts, [ 'post_author' => $user, 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ], [ 'ID' => $id, 'post_author' => $old ] ), 'Ownership transfer failed.', 'operation_failed' );
                        clean_post_cache( $id );
                        if ( $plan['preview']['plan_id'] ) {
                            $payment = $plan['state']['payment'];
                            $native_plan = (object) $payment['plan'];
                            $expiry_before = 'pay_per_listing' === $native_plan->type && 'lifetime' !== $native_plan->interval_type ? directorist_get_expiry_date( (int) $native_plan->interval_count, $native_plan->interval_type ) : null;
                            \DirectoristPricingPlan\App\MCP\Entitlements::service()->apply_assignment( $id, (object) $payment['package'], (object) $payment['plan'] );
                            self::check( (int) get_post_meta( $id, directorist_plan_key(), true ) === $plan['preview']['plan_id'] && get_post_status( $id ) === $plan['preview']['listing_status'] && 'post_status' === get_post_meta( $id, '_listing_status', true ), 'Native plan assignment could not be verified.', 'operation_failed' );
                            $expiration = (string) get_post_meta( $id, '_expiry_date', true );
                            $never = (bool) get_post_meta( $id, '_never_expire', true );
                            if ( $expiry_before ) {
                                $expiry_after = directorist_get_expiry_date( (int) $native_plan->interval_count, $native_plan->interval_type );
                                self::check( ! $never && $expiration >= $expiry_before && $expiration <= $expiry_after, 'Native plan expiration could not be verified.', 'operation_failed' );
                            } else {
                                $expected = 'package' === $native_plan->type ? (string) ( $payment['package']['current_period_end'] ?? '' ) : '';
                                self::check( $expected ? ! $never && $expiration === $expected : $never && '' === $expiration, 'Native package expiration could not be verified.', 'operation_failed' );
                            }
                            self::meta( $id, '_featured', $payment['featured'] ? 1 : 0 );
                        }
                        self::meta( $id, '_claimed_by_admin', 1 );
                        self::meta( $id, '_claim_fee', 'claim_approved' );
                        self::meta( $id, '_dcl_approved_claim', $claim_id );
                        update_user_meta( $user, '_user_type', 'author' );
                        self::check( 'author' === get_user_meta( $user, '_user_type', true ), 'Could not save native author marker.', 'operation_failed' );
                    }
                    // A losing decline never modifies listing markers or ownership.
                    self::meta( $claim_id, '_claim_status', $decision );
                    self::meta( $claim_id, '_dcl_review_reason', sanitize_textarea_field( $args['reason'] ?? '' ) );
                    DCL_Claim_Outbox::enqueue( $claim_id, $decision );
                    if ( $system && 'approved' === $decision ) { DCL_Claim_Outbox::enqueue_paid_event( $claim_id, (int) get_post_meta( $claim_id, '_dcl_order_id', true ) ); }
                }
                return [ 'claim_id' => $claim_id, 'listing_id' => $id, 'status' => $decision, 'owner_id' => (int) get_post_field( 'post_author', $id ), 'no_op' => $plan['preview']['no_op'] ];
            } );
        } );
    }

    public static function meta( int $id, string $key, $value ): void {
        update_post_meta( $id, $key, wp_slash( $value ) );
        self::check( (string) get_post_meta( $id, $key, true ) === (string) $value, 'Could not persist claim state.', 'operation_failed' );
    }

    private static function minor( $value, string $currency ): int {
        return \Directorist\MCP\Tool_Providers\Orders\Money::minor( \Directorist\MCP\Tool_Providers\Orders\Money::stored( $value, $currency ), $currency );
    }

    private static function expiration_effect( array $payment ): string {
        if ( empty( $payment['plan'] ) ) { return ''; }
        if ( 'package' === $payment['plan']['type'] ) { return 'Package expiration: ' . ( $payment['package']['current_period_end'] ?? 'never expires' ); }
        return 'lifetime' === $payment['plan']['interval_type'] ? 'Plan never expires.' : 'Native plan duration: ' . $payment['plan']['interval_count'] . ' ' . $payment['plan']['interval_type'];
    }

    /** Resolve real native settlement, including refunds, instead of trusting a paid flag. */
    private static function paid( $order ): bool {
        $payments = directorist_payment_repository()->get( (int) $order->id );
        $paid = 0;
        foreach ( $payments as $payment ) {
            if ( 'paid' === $payment->status && (string) $payment->currency === (string) $order->currency ) { $paid += self::minor( $payment->amount, (string) $order->currency ); }
        }
        global $wpdb;
        $refund = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}directorist_refunds WHERE order_id = %d AND status <> 'rejected'", (int) $order->id ) );
        // Any refund requires native reconciliation before an ownership grant.
        return ! $refund && $paid >= self::minor( $order->amount, (string) $order->currency ) && ( $paid > 0 || 0 === self::minor( $order->amount, (string) $order->currency ) );
    }
}
