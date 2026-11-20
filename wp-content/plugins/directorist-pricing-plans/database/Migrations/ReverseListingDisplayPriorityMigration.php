<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class ReverseListingDisplayPriorityMigration implements Migration {
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
        $maximum_priority = (int) $wpdb->get_var( "SELECT MAX(listing_display_priority) FROM {$plans_table}" );
        $priority_offset  = $maximum_priority + 1;

        $wpdb->query( 'START TRANSACTION' );

        $plans_updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$plans_table}
                SET listing_display_priority = %d - listing_display_priority",
                $priority_offset
            )
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
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( false === $packages_updated ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        return false !== $wpdb->query( 'COMMIT' );
    }
}
