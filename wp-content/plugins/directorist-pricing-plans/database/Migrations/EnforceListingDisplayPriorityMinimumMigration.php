<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class EnforceListingDisplayPriorityMinimumMigration implements Migration {
    public function more_than_version() {
        return '4.0.1';
    }

    public function execute(): bool {
        global $wpdb;

        $plans_table    = "{$wpdb->prefix}directorist_plans";
        $packages_table = "{$wpdb->prefix}directorist_user_packages";

        foreach ( [ $plans_table, $packages_table ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                return true;
            }
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the WordPress table prefix and checked before use.
        $wpdb->query( 'START TRANSACTION' );

        $plans_updated = $wpdb->query(
            "UPDATE {$plans_table}
            SET listing_display_priority = 1
            WHERE listing_display_priority < 1"
        );

        if ( false === $plans_updated ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $packages_updated = $wpdb->query(
            "UPDATE {$packages_table} AS package
            INNER JOIN {$plans_table} AS plan ON plan.id = package.plan_id
            SET package.listing_display_priority = plan.listing_display_priority"
        );

        if ( false === $packages_updated ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            return false;
        }

        return false !== $wpdb->query(
            "ALTER TABLE {$plans_table}
            ALTER COLUMN listing_display_priority SET DEFAULT 1"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
