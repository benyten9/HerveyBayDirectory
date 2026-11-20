<?php
/** Durable, per-claim notification intents. Delivery never repeats the claim writer. */
defined( 'ABSPATH' ) || exit;

final class DCL_Claim_Outbox {
    const PREFIX = '_directorist_claim_mail_';

    public static function boot(): void {
        add_action( 'init', [ self::class, 'schedule' ] );
        add_action( 'directorist_claim_deliver', [ self::class, 'deliver' ] );
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( 'directorist_claim_deliver' ) ) {
            wp_schedule_event( time() + 60, 'hourly', 'directorist_claim_deliver' );
        }
    }

    /** Safe roles only; preview discloses no email addresses or competing evidence. */
    public static function recipients( string $event ): array {
        if ( get_directorist_option( 'disable_email_notification' ) ) { return []; }
        if ( 'submitted' !== $event ) {
            $enabled = function_exists( 'directorist_is_owner_notifiable_event' ) ? directorist_is_owner_notifiable_event( 'claim_confirmation' ) : in_array( 'claim_confirmation', (array) get_directorist_option( 'notify_user', [ 'claim_confirmation' ] ), true );
            return $enabled ? [ 'claimant' ] : [];
        }
        $roles = [];
        if ( function_exists( 'directorist_is_owner_notifiable_event' ) ? directorist_is_owner_notifiable_event( 'new_claim_submitted' ) : in_array( 'new_claim_submitted', (array) get_directorist_option( 'notify_user', [ 'new_claim_submitted' ] ), true ) ) { $roles[] = 'claimant'; }
        if ( function_exists( 'directorist_is_admin_notifiable_event' ) ? directorist_is_admin_notifiable_event( 'new_claim_submitted' ) : in_array( 'new_claim_submitted', (array) get_directorist_option( 'notify_admin', [ 'new_claim_submitted' ] ), true ) ) { $roles[] = 'site_reviewers'; }
        return $roles;
    }

    public static function enqueue( int $claim_id, string $event ): void {
        $user_id = (int) get_post_meta( $claim_id, '_listing_claimer', true );
        $user = get_userdata( $user_id );
        $listing_id = (int) get_post_meta( $claim_id, '_claimed_listing', true );
        $title = get_the_title( $listing_id );
        $url = get_permalink( $listing_id );
        $site = get_bloginfo( 'name' );
        $site_url = get_bloginfo( 'url' );
        $name = (string) get_post_meta( $claim_id, '_claimer_name', true );
        $phone = (string) get_post_meta( $claim_id, '_claimer_phone', true );
        $details = (string) get_post_meta( $claim_id, '_claimer_details', true );
        foreach ( self::recipients( $event ) as $role ) {
            $key = self::PREFIX . $claim_id . '_' . $event . '_' . $role;
            if ( get_option( $key ) ) { continue; }
            $template = 'submitted' === $event ? 'new_claim' : $event . '_claim';
            $subject = get_directorist_option( 'email_sub_' . $template, '[==SITE_NAME==] Claim ' . $event . ': ==LISTING_TITLE==', true );
            $body = get_directorist_option( 'email_tmpl_' . $template, 'Dear ==NAME==, your claim for ==LISTING_TITLE== is ' . $event . '. ==LISTING_URL==', true );
            if ( 'site_reviewers' === $role ) {
                $subject = '[==SITE_NAME==] New claim: ==LISTING_TITLE==';
                $body = 'A claim was submitted for ==LISTING_LINK==.<br>Name: ==SENDER_NAME==<br>Phone: ==SENDER_PHONE==<br>Details: ==MESSAGE==';
            }
            $values = [
                '==NAME==' => esc_html( $user->display_name ), '==USERNAME==' => esc_html( $user->user_login ), '==SITE_NAME==' => esc_html( $site ),
                '==SITE_LINK==' => sprintf( '<a href="%s">%s</a>', esc_url( $site_url ), esc_html( $site ) ), '==SITE_URL==' => esc_url( $site_url ),
                '==LISTING_TITLE==' => esc_html( $title ), '==LISTING_LINK==' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $title ) ), '==LISTING_URL==' => esc_url( $url ),
                '==SENDER_NAME==' => esc_html( $name ?: $site ), '==SENDER_PHONE==' => esc_html( $phone ), '==MESSAGE==' => esc_html( $details ),
                '==TODAY==' => date_i18n( get_option( 'date_format' ) ), '==NOW==' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
            ];
            $to = 'claimant' === $role ? $user->user_email : ATBDP()->email->get_admin_email_list();
            if ( ! $to ) { $to = get_bloginfo( 'admin_email' ); }
            $intent = [ 'state' => 'pending', 'attempts' => 0, 'next_attempt' => time(), 'claim_id' => $claim_id, 'event' => $event, 'role' => $role, 'to' => $to, 'subject' => wp_strip_all_tags( strtr( $subject, $values ) ), 'message' => nl2br( strtr( $body, $values ) ), 'headers' => ATBDP()->email->get_email_headers() ];
            DCL_Claim_Service::check( add_option( $key, $intent, '', false ), 'Could not persist claim notification intent.', 'operation_failed' );
        }
        // The cron record is committed with the claim. The periodic sweep also recovers missed scheduling.
        if ( ! wp_next_scheduled( 'directorist_claim_deliver' ) || wp_next_scheduled( 'directorist_claim_deliver' ) > time() + 15 ) {
            wp_schedule_single_event( time() + 10, 'directorist_claim_deliver' );
        }
    }

    /** Preserve the native insertion hook, after commit, without rerunning a claim writer. */
    public static function enqueue_event( int $claim_id ): void {
        $key = self::PREFIX . $claim_id . '_native_event';
        DCL_Claim_Service::check( add_option( $key, [ 'state' => 'pending', 'kind' => 'inserted_event', 'claim_id' => $claim_id, 'next_attempt' => time() ], '', false ), 'Could not persist the native claim event.', 'operation_failed' );
    }

    public static function enqueue_paid_event( int $claim_id, int $order_id ): void {
        $order = directorist_order_repository()->get_by_id( $order_id );
        DCL_Claim_Service::check( $order && add_option( self::PREFIX . $claim_id . '_paid_event', [ 'state' => 'pending', 'kind' => 'paid_event', 'claim_id' => $claim_id, 'order' => (array) $order, 'next_attempt' => time() ], '', false ), 'Could not persist the paid claim event.', 'operation_failed' );
    }

    /** Capture incidental native status mail as part of the same durable database unit. */
    public static function capture( int $claim_id, string $event, callable $writer ): array {
        $index = 0;
        $capture = static function ( $return, array $mail ) use ( $claim_id, $event, &$index ) {
            $key = self::PREFIX . $claim_id . '_' . $event . '_native_' . ++$index;
            if ( ! get_option( $key ) ) {
                DCL_Claim_Service::check( add_option( $key, $mail + [ 'state' => 'pending', 'attempts' => 0, 'next_attempt' => time(), 'claim_id' => $claim_id, 'event' => $event, 'role' => 'native_listing_notifications' ], '', false ), 'Could not persist native status notification.', 'operation_failed' );
            }
            return true;
        };
        add_filter( 'pre_wp_mail', $capture, PHP_INT_MIN, 2 );
        try { return $writer(); } finally { remove_filter( 'pre_wp_mail', $capture, PHP_INT_MIN ); }
    }

    private static function deliver_event( string $key, array $intent ): void {
        // Unknown hook outcomes require reconciliation; never rerun arbitrary hook side effects.
        $intent['state'] = 'dispatching';
        if ( ! update_option( $key, $intent, false ) ) { return; }
        $index = 0;
        $capture = static function ( $return, array $mail ) use ( $key, &$index ) {
            $mail_key = $key . '_mail_' . ++$index;
            $entry = $mail + [ 'state' => 'pending', 'attempts' => 0, 'next_attempt' => time() ];
            if ( ! get_option( $mail_key ) ) { DCL_Claim_Service::check( add_option( $mail_key, $entry, '', false ), 'Could not persist native event mail.' ); }
            return true;
        };
        add_filter( 'pre_wp_mail', $capture, PHP_INT_MIN, 2 );
        try {
            if ( 'paid_event' === $intent['kind'] ) {
                $claim_id = (int) $intent['claim_id'];
                do_action( 'directorist_claim_listing_paid_claim_approved', $claim_id, (int) get_post_meta( $claim_id, '_claimed_listing', true ), (int) get_post_meta( $claim_id, '_listing_claimer', true ), directorist_order_repository()->to_dto( (object) $intent['order'] ) );
            } else { do_action( 'atbdp_new_claim_inserted', (int) $intent['claim_id'] ); }
            update_option( $key, [ 'state' => 'sent', 'claim_id' => $intent['claim_id'], 'kind' => $intent['kind'] ], false );
        } finally { remove_filter( 'pre_wp_mail', $capture, PHP_INT_MIN ); }
    }

    public static function deliver(): void {
        if ( ! class_exists( '\Directorist\MCP\Safety\Write_Lock' ) || DCL_Claim_Service::in_transaction() ) { return; }
        DCL_Claim_Service::lock();
        try {
            global $wpdb;
            $keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC", $wpdb->esc_like( self::PREFIX ) . '%' ) );
            $delivered = 0;
            foreach ( $keys as $key ) {
                $intent = get_option( $key );
                if ( ! is_array( $intent ) || 'pending' !== $intent['state'] || $intent['next_attempt'] > time() ) { continue; }
                if ( in_array( $intent['kind'] ?? '', [ 'inserted_event', 'paid_event' ], true ) ) {
                    try { self::deliver_event( $key, $intent ); } catch ( Throwable $error ) { /* Retain dispatching intent for reconciliation. */ }
                    continue;
                }
                try {
                    $sent = ATBDP()->email->send_mail( $intent['to'], $intent['subject'], $intent['message'], $intent['headers'] );
                } catch ( Throwable $error ) { $sent = false; }
                $notification = $intent;
                ++$intent['attempts'];
                if ( $sent ) {
                    // Retain only a deduplication tombstone after successful delivery.
                    $intent = [ 'state' => 'sent', 'claim_id' => $intent['claim_id'] ?? 0, 'event' => $intent['event'] ?? 'native_event', 'role' => $intent['role'] ?? 'native_event_recipient' ];
                } else {
                    $intent['next_attempt'] = time() + 300;
                    wp_schedule_single_event( time() + 300, 'directorist_claim_deliver' );
                }
                update_option( $key, $intent, false );
                if ( 'approved' === ( $notification['event'] ?? '' ) && 'claimant' === ( $notification['role'] ?? '' ) ) {
                    try {
                        do_action( 'directorist_claim_listing_email_on_claim_confirmation', [ 'is_sent' => (bool) $sent, 'to_email' => $notification['to'], 'subject' => $notification['subject'], 'message' => $notification['message'], 'headers' => $notification['headers'], 'listing_id' => (int) get_post_meta( $notification['claim_id'], '_claimed_listing', true ), 'claimer_id' => (int) get_post_meta( $notification['claim_id'], '_listing_claimer', true ) ] );
                    } catch ( Throwable $error ) { /* Observational listeners cannot replay the ownership writer. */ }
                }
                if ( ++$delivered >= 50 ) { wp_schedule_single_event( time() + 60, 'directorist_claim_deliver' ); break; }
            }
        } finally { \Directorist\MCP\Safety\Write_Lock::unlock(); }
    }
}

DCL_Claim_Outbox::boot();
