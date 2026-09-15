<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class AddPastDueUserPackageStatusMigration implements Migration {
    public function more_than_version() {
        return '4.0.1';
    }

    public function execute(): bool {
        global $wpdb;

        $packages_table = "{$wpdb->prefix}directorist_user_packages";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $packages_table ) ) ) !== $packages_table ) {
            return true;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the WordPress table prefix and checked before use.
        $status_column = $wpdb->get_row( "SHOW COLUMNS FROM {$packages_table} LIKE 'status'", ARRAY_A );

        if ( ! $status_column ) {
            return false;
        }

        if ( false === strpos( strtolower( (string) $status_column['Type'] ), "'" . UserPackageStatus::PAST_DUE . "'" ) ) {
            $statuses = array_map( 'esc_sql', UserPackageStatus::all() );
            $enum     = "'" . implode( "','", $statuses ) . "'";
            $altered  = $wpdb->query(
                "ALTER TABLE {$packages_table}
                MODIFY status ENUM({$enum}) NOT NULL DEFAULT '" . esc_sql( UserPackageStatus::ACTIVE ) . "'"
            );

            if ( false === $altered ) {
                return false;
            }
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$packages_table}
                SET status = %s,
                    current_period_end = NULL
                WHERE status = %s
                    AND is_recurring = 1
                    AND COALESCE(subscription_id, '') != ''
                    AND COALESCE(subscription_method, '') != ''",
                UserPackageStatus::PAST_DUE,
                UserPackageStatus::EXPIRED
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return false !== $updated;
    }
}
