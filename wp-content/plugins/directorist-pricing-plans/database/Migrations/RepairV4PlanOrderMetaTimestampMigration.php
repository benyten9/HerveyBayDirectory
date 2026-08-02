<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class RepairV4PlanOrderMetaTimestampMigration implements Migration {
    public function more_than_version() {
        return '3.9.9';
    }

    public function execute(): bool {
        global $wpdb;

        $table = "{$wpdb->prefix}directorist_plan_order_meta";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return true;
        }

        $wpdb->query( "ALTER TABLE {$table} MODIFY current_period_end TIMESTAMP NULL DEFAULT NULL" );

        $wpdb->query(
            "UPDATE {$table}
            SET current_period_end = NULL
            WHERE current_period_end = '0000-00-00 00:00:00'"
        );

        return true;
    }
}
