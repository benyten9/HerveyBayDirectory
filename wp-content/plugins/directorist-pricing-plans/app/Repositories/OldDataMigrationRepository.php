<?php

namespace DirectoristPricingPlan\App\Repositories;

defined( "ABSPATH" ) || exit;

use stdClass;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Models\PlanFeature;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;

class OldDataMigrationRepository extends Repository {
    private const MIGRATION_NOTICE_DISMISSED_OPTION = 'directorist_plans_migration_notice_dismissed';

    public function get_query_builder(): Builder {
        return Plan::query( 'plan' );
    }

    public function query_plans_total(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'atbdp_pricing_plans' AND post_status IN ( 'publish', 'pending', 'draft' )"
        );
    }

    public function query_plans_migrated(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'atbdp_pricing_plans'
               AND post_status IN ( 'publish', 'pending', 'draft' ) 
               AND ID IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_is_migrated' AND meta_value = '1'
               )"
        );
    }

    public function query_orders_total(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'atbdp_orders'
               AND EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} plan_meta
                   WHERE plan_meta.post_id = p.ID
                     AND plan_meta.meta_key = '_fm_plan_ordered'
               )"
        );
    }

    public function query_orders_migrated(): int {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";
        $ref_type     = OrderRefType::PRICING_PLAN;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} plan_meta
                    ON plan_meta.post_id = p.ID
                    AND plan_meta.meta_key = '_fm_plan_ordered'
                 INNER JOIN {$orders_table} migrated_order
                    ON migrated_order.legacy_id = p.ID
                    AND migrated_order.ref_type = %s
                 WHERE p.post_type = 'atbdp_orders'",
                $ref_type
            )
        );
        // phpcs:enable
    }

    public function query_users_total(): int {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id)
             FROM {$orders_table}
             WHERE legacy_id IS NOT NULL
               AND ref_type = 'pricing_plan'
               AND status = 'paid'
               AND user_id != 0"
        );
        // phpcs:enable
    }

    public function query_users_migrated(): int {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT um.user_id)
             FROM {$wpdb->usermeta} um
             INNER JOIN {$orders_table} o
                ON o.user_id = um.user_id
                AND o.legacy_id IS NOT NULL
                AND o.ref_type = 'pricing_plan'
                AND o.status = 'paid'
             WHERE um.meta_key = '_is_migrated_packages'
               AND um.meta_value = '1'
               AND um.user_id != 0"
        );
        // phpcs:enable
    }

    public function query_unmigrated_old_plans(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->posts}
             WHERE post_type = 'atbdp_pricing_plans'
               AND post_status IN ( 'publish', 'pending', 'draft' ) 
               AND ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_is_migrated' AND meta_value = '1'
               )
             ORDER BY ID ASC"
        );
    }

    public function query_count_migratable_orders(): int {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'atbdp_orders'
               AND EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} plan_meta
                   WHERE plan_meta.post_id = p.ID
                     AND plan_meta.meta_key = '_fm_plan_ordered'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$orders_table} migrated_order
                   WHERE migrated_order.legacy_id = p.ID
                     AND migrated_order.ref_type = 'pricing_plan'
               )"
        );
        // phpcs:enable
    }

    public function query_unmigrated_old_orders_batch( int $batch_size ): array {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->posts} p
                 WHERE p.post_type = 'atbdp_orders'
                   AND EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} plan_meta
                       WHERE plan_meta.post_id = p.ID
                         AND plan_meta.meta_key = '_fm_plan_ordered'
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM {$orders_table} migrated_order
                       WHERE migrated_order.legacy_id = p.ID
                         AND migrated_order.ref_type = 'pricing_plan'
                   )
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $batch_size
            )
        );
        // phpcs:enable
    }

    public function run_bulk_update_listing_plan_meta_query( array $plan_id_map ): void {
        global $wpdb;

        $case_parts = [];
        $params     = [];

        foreach ( $plan_id_map as $old_id => $new_id ) {
            $case_parts[] = 'WHEN %s THEN %s';
            $params[]     = (string) $old_id;
            $params[]     = (string) $new_id;
        }

        $old_ids         = array_keys( $plan_id_map );
        $in_placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%s' ) );
        $case_expr       = 'CASE pm.meta_value ' . implode( ' ', $case_parts ) . ' END';
        $params          = array_merge( $params, array_map( 'strval', $old_ids ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                    SELECT pm.post_id, '_plan_id', {$case_expr}
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = '_fm_plans'
                    AND pm.meta_value IN ({$in_placeholders})
                    AND p.post_type = 'at_biz_dir'
                    AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} existing
                        WHERE existing.post_id = pm.post_id
                            AND existing.meta_key = '_plan_id'
                    )",
                ...$params
            )
        );
        // phpcs:enable
    }

    public function get_unmigrated_paid_user_ids_batch( int $batch_size ): array {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are safe
        return array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT o.user_id
                     FROM {$orders_table} o
                     WHERE o.legacy_id IS NOT NULL
                        AND o.ref_type = 'pricing_plan'
                        AND o.status = 'paid'
                        AND o.user_id != 0
                        AND NOT EXISTS (
                            SELECT 1 FROM {$wpdb->usermeta} um
                            WHERE um.user_id = o.user_id
                                AND um.meta_key = '_is_migrated_packages'
                                AND um.meta_value = '1'
                     )
                     ORDER BY o.user_id ASC
                     LIMIT %d",
                    $batch_size
                )
            )
        );
        // phpcs:enable
    }

    public function query_user_plan_package_exists( int $user_id, int $plan_id ): bool {
        global $wpdb;

        $packages_table = "{$wpdb->prefix}directorist_user_packages";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$packages_table} WHERE user_id = %d AND plan_id = %d",
                $user_id,
                $plan_id
            )
        );

        return $exists > 0;
        // phpcs:enable
    }

    public function query_last_paid_order_for_user_plan( int $user_id, int $plan_id ): ?stdClass {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$orders_table}
                 WHERE ref_type = 'pricing_plan' AND ref = %d AND user_id = %d AND status = 'paid'
                 ORDER BY created_at DESC LIMIT 1",
                $plan_id,
                $user_id
            )
        );
        // phpcs:enable
    }

    public function query_order_ids_for_user_plan_except( int $plan_id, int $user_id, int $excluded_order_id ): array {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
        return array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$orders_table} WHERE ref_type = 'pricing_plan' AND ref = %d AND user_id = %d AND id != %d",
                    $plan_id,
                    $user_id,
                    $excluded_order_id
                )
            )
        );
        // phpcs:enable
    }

    public function query_postmeta_map( int $post_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
                $post_id
            )
        );

        $map = [];

        foreach ( $rows as $row ) {
            $map[ $row->meta_key ] = $row->meta_value;
        }

        return $map;
    }

    public function update_migration_error( ?array $error ): void {
        update_option( 'directorist_plans_migration_error', $error );
    }

    public function update_step_error( string $step, string $message ): void {
        update_option( 'directorist_plans_migration_step_error_' . $step, $message );
    }

    public function delete_step_error( string $step ): void {
        delete_option( 'directorist_plans_migration_step_error_' . $step );
    }

    public function delete_migration_error(): void {
        delete_option( 'directorist_plans_migration_error' );
    }

    public function has_step_error( string $step ): bool {
        return (bool) get_option( 'directorist_plans_migration_step_error_' . $step );
    }

    public function is_migration_notice_dismissed(): bool {
        return (bool) get_option( self::MIGRATION_NOTICE_DISMISSED_OPTION, false );
    }

    public function dismiss_migration_notice(): void {
        update_option( self::MIGRATION_NOTICE_DISMISSED_OPTION, true, false );
    }

    public function clear_migration_notice_dismissal(): void {
        delete_option( self::MIGRATION_NOTICE_DISMISSED_OPTION );
    }

    public function get_plan_id_map(): array {
        $plan_id_map = get_option( 'directorist_migration_plan_id_map', [] );

        return is_array( $plan_id_map ) ? $plan_id_map : [];
    }

    public function update_plan_id_map( array $plan_id_map ): void {
        update_option( 'directorist_migration_plan_id_map', $plan_id_map );
    }

    public function mark_post_as_migrated( int $post_id ): void {
        update_post_meta( $post_id, '_is_migrated', 1 );
    }

    public function mark_user_packages_as_migrated( int $user_id ): void {
        update_user_meta( $user_id, '_is_migrated_packages', 1 );
    }

    public function is_listing_plan_meta_bulk_updated(): bool {
        return (bool) get_option( 'directorist_bulk_update_listing_plan_meta_done', false );
    }

    public function set_listing_plan_meta_bulk_updated( bool $is_done ): void {
        update_option( 'directorist_bulk_update_listing_plan_meta_done', $is_done );
    }

    public function mark_listing_plan_meta_bulk_updated(): void {
        $this->set_listing_plan_meta_bulk_updated( true );
    }

    public function get_progress(): array {
        return [
            'plans_total'     => $this->query_plans_total(),
            'plans_migrated'  => $this->query_plans_migrated(),
            'orders_total'    => $this->query_orders_total(),
            'orders_migrated' => $this->query_orders_migrated(),
            'users_total'     => $this->query_users_total(),
            'users_migrated'  => $this->query_users_migrated(),
        ];
    }

    /**
     * @return stdClass[]
     */
    public function get_unmigrated_old_plans(): array {
        return $this->query_unmigrated_old_plans();
    }

    public function create_plan( array $plan_data ): int {
        return (int) Plan::query( 'plan' )->insert_get_id( $plan_data );
    }

    public function create_plan_feature( array $feature_data ): bool {
        return (bool) PlanFeature::query( 'plan_feature' )->insert( $feature_data );
    }

    public function count_migratable_orders(): int {
        return $this->query_count_migratable_orders();
    }

    /**
     * @return stdClass[]
     */
    public function get_unmigrated_old_orders_batch( int $batch_size ): array {
        return $this->query_unmigrated_old_orders_batch( $batch_size );
    }

    public function insert_order( array $order_data ): int {
        global $wpdb;

        $orders_table = "{$wpdb->prefix}directorist_orders";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert needed for migration
        $inserted = $wpdb->insert( $orders_table, $order_data );

        if ( ! $inserted ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function insert_payment( array $payment_data ): bool {
        global $wpdb;

        $payments_table = "{$wpdb->prefix}directorist_payments";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert needed for migration
        return (bool) $wpdb->insert( $payments_table, $payment_data );
    }

    public function bulk_update_listing_plan_meta( array $plan_id_map ): void {
        if ( empty( $plan_id_map ) ) {
            return;
        }

        $this->run_bulk_update_listing_plan_meta_query( $plan_id_map );
    }

    public function get_plan_by_id( int $plan_id ): ?stdClass {
        return Plan::query( 'plan' )->where( 'plan.id', $plan_id )->first();
    }

    public function user_plan_package_exists( int $user_id, int $plan_id ): bool {
        return $this->query_user_plan_package_exists( $user_id, $plan_id );
    }

    public function get_last_paid_order_for_user_plan( int $user_id, int $plan_id ): ?stdClass {
        return $this->query_last_paid_order_for_user_plan( $user_id, $plan_id );
    }

    public function insert_expired_package( array $package_data ): int {
        global $wpdb;

        $packages_table = "{$wpdb->prefix}directorist_user_packages";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert to bypass constraints for migration
        $wpdb->insert( $packages_table, $package_data );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return int[]
     */
    public function get_order_ids_for_user_plan_except( int $plan_id, int $user_id, int $excluded_order_id ): array {
        return $this->query_order_ids_for_user_plan_except( $plan_id, $user_id, $excluded_order_id );
    }

    public function get_postmeta_map( int $post_id ): array {
        return $this->query_postmeta_map( $post_id );
    }
}
