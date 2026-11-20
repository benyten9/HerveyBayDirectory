<?php

namespace DirectoristPricingPlan\App\MCP;

defined( 'ABSPATH' ) || exit;

/** Transactional mail intents. Cron observes an intent only after its native unit commits. */
final class Outbox {
    public static function capture( callable $writer ): array {
        $capture = static function ( $return, array $mail ) {
            if ( null !== $return ) { return $return; }
            $key = '_directorist_pp_mail_' . wp_generate_uuid4();
            Access::check( add_option( $key, $mail, '', false ), 'Could not persist notification intent.', 'operation_failed' );
            $scheduled = wp_schedule_single_event( time() + 10, 'directorist_pp_mcp_deliver_mail', [ $key ], true );
            Access::check( ! is_wp_error( $scheduled ) && false !== $scheduled, 'Could not schedule notification delivery.', 'operation_failed' );
            return true;
        };
        add_filter( 'pre_wp_mail', $capture, PHP_INT_MIN, 2 );
        try {
            return $writer();
        } finally {
            remove_filter( 'pre_wp_mail', $capture, PHP_INT_MIN );
        }
    }

    /** Defer the public assignment notification; native transactional hooks still run in the writer. */
    public static function assignment_event( int $listing_id, int $plan_id, int $package_id ): void {
        $claim_transaction = class_exists( '\DCL_Claim_Service' ) && \DCL_Claim_Service::in_transaction();
        if ( ! $claim_transaction && ( ! class_exists( '\Directorist\MCP\Safety\Write_Guard' ) || ! \Directorist\MCP\Safety\Write_Guard::is_mutating() ) ) {
            do_action( 'directorist_listing_plan_reassigned', $listing_id, $plan_id, $package_id );
            return;
        }
        $key = '_directorist_pp_event_' . wp_generate_uuid4();
        Access::check( add_option( $key, [ $listing_id, $plan_id, $package_id ], '', false ), 'Could not persist the assignment notification.', 'operation_failed' );
        $scheduled = wp_schedule_single_event( time() + 10, 'directorist_pp_mcp_deliver_event', [ $key ], true );
        Access::check( ! is_wp_error( $scheduled ) && false !== $scheduled, 'Could not schedule the assignment notification.', 'operation_failed' );
    }

    public static function deliver_event( string $key ): void {
        if ( ! preg_match( '/^_directorist_pp_event_[a-f0-9-]{36}$/', $key ) ) { return; }
        NativeSafety::lock();
        $event = get_option( $key );
        if ( ! is_array( $event ) || 3 !== count( $event ) ) { return; }
        try {
            do_action( 'directorist_listing_plan_reassigned', ...array_map( 'intval', $event ) );
            delete_option( $key );
        } catch ( \Throwable $error ) {
            wp_schedule_single_event( time() + 300, 'directorist_pp_mcp_deliver_event', [ $key ] );
        }
    }

    public static function deliver( string $key ): void {
        if ( ! preg_match( '/^_directorist_pp_mail_[a-f0-9-]{36}$/', $key ) ) {
            return;
        }
        // Serialize delivery attempts with native/MCP writes on this site.
        NativeSafety::lock();
        $mail = get_option( $key );
        if ( ! is_array( $mail ) ) {
            return;
        }
        if ( wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'], $mail['attachments'] ) ) {
            delete_option( $key );
        } else {
            wp_schedule_single_event( time() + 300, 'directorist_pp_mcp_deliver_mail', [ $key ] );
        }
    }
}
