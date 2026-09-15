<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class RepairV4LegacySubscriptionsMigration implements Migration {
    public function more_than_version() {
        return '4.0.1';
    }

    public function execute(): bool {
        global $wpdb;

        $packages_table = "{$wpdb->prefix}directorist_user_packages";
        $payments_table = "{$wpdb->prefix}directorist_payments";

        foreach ( [ $packages_table, $payments_table ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                return true;
            }
        }

        // Legacy gateways stored their recurring reference as the order's
        // transaction ID. Restore it so each gateway can resolve renewals.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the WordPress table prefix and checked before use.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$packages_table} AS package
                INNER JOIN {$payments_table} AS payment
                    ON payment.order_id = package.last_order_id
                LEFT JOIN {$payments_table} AS newer_payment
                    ON newer_payment.order_id = payment.order_id
                    AND newer_payment.id > payment.id
                    AND newer_payment.status = payment.status
                    AND COALESCE(newer_payment.transaction_id, '') != ''
                    AND COALESCE(newer_payment.method, '') != ''
                SET package.subscription_id = payment.transaction_id,
                    package.subscription_method = payment.method,
                    package.subscription_currency = NULLIF(payment.currency, ''),
                    package.subscription_amount = payment.amount
                WHERE package.is_legacy = 1
                    AND package.is_recurring = 1
                    AND ( package.subscription_id IS NULL OR package.subscription_id = '' )
                    AND newer_payment.id IS NULL
                    AND payment.status = %s
                    AND COALESCE(payment.transaction_id, '') != ''
                    AND COALESCE(payment.method, '') != ''",
                'paid'
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return false !== $updated;
    }
}
