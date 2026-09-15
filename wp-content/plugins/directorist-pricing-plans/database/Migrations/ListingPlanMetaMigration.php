<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class ListingPlanMetaMigration implements Migration {
    public const STATE_OPTION = 'directorist_listing_plan_meta_migration_state';

    private const BATCH_SIZE = 250;

    public function more_than_version() {
        return '4.0.1';
    }

    public function execute(): bool {
        global $wpdb;

        $posts_table    = $wpdb->posts;
        $postmeta_table = $wpdb->postmeta;
        $packages_table = "{$wpdb->prefix}directorist_user_packages";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $packages_table ) ) ) !== $packages_table ) {
            return true;
        }

        $post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';
        $state     = get_option( self::STATE_OPTION, [] );

        if ( ! is_array( $state ) || empty( $state ) ) {
            $state = [
                'status'    => 'running',
                'cursor'    => 0,
                'processed' => 0,
                'total'     => (int) $wpdb->get_var(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name from wpdb.
                    $wpdb->prepare( "SELECT COUNT(ID) FROM {$posts_table} WHERE post_type = %s", $post_type )
                ),
                'error'     => '',
            ];
        } else {
            $state['status'] = 'running';
            $state['error']  = '';
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name from wpdb.
        $listing_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                FROM {$posts_table}
                WHERE post_type = %s AND ID > %d
                ORDER BY ID ASC
                LIMIT %d",
                $post_type,
                (int) $state['cursor'],
                self::BATCH_SIZE
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! empty( $wpdb->last_error ) ) {
            return $this->fail( $state, $wpdb->last_error );
        }

        if ( empty( $listing_ids ) ) {
            delete_option( self::STATE_OPTION );
            return true;
        }

        $placeholders = implode( ', ', array_fill( 0, count( $listing_ids ), '%d' ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names are trusted and the ID placeholder count is generated from the validated batch.
        $sql = $wpdb->prepare(
            "SELECT post.ID,
                post.post_author,
                CAST((
                    SELECT directory_meta.meta_value
                    FROM {$postmeta_table} AS directory_meta
                    WHERE directory_meta.post_id = post.ID
                        AND directory_meta.meta_key = '_directory_type'
                    ORDER BY directory_meta.meta_id DESC
                    LIMIT 1
                ) AS UNSIGNED) AS directory_type_id,
                CAST((
                    SELECT current_plan_meta.meta_value
                    FROM {$postmeta_table} AS current_plan_meta
                    WHERE current_plan_meta.post_id = post.ID
                        AND current_plan_meta.meta_key = '_plan_id'
                    ORDER BY current_plan_meta.meta_id DESC
                    LIMIT 1
                ) AS UNSIGNED) AS current_plan_id,
                (
                    SELECT package.plan_id
                    FROM {$packages_table} AS package
                    WHERE package.user_id = post.post_author
                        AND package.directory_type_id = CAST((
                            SELECT package_directory_meta.meta_value
                            FROM {$postmeta_table} AS package_directory_meta
                            WHERE package_directory_meta.post_id = post.ID
                                AND package_directory_meta.meta_key = '_directory_type'
                            ORDER BY package_directory_meta.meta_id DESC
                            LIMIT 1
                        ) AS UNSIGNED)
                        AND package.is_legacy = 0
                        AND package.status IN (%s, %s)
                    ORDER BY package.started_at DESC, package.id DESC
                    LIMIT 1
                ) AS target_plan_id,
                (
                    SELECT COUNT(legacy_package.id)
                    FROM {$packages_table} AS legacy_package
                    WHERE legacy_package.user_id = post.post_author
                        AND legacy_package.directory_type_id = CAST((
                            SELECT legacy_directory_meta.meta_value
                            FROM {$postmeta_table} AS legacy_directory_meta
                            WHERE legacy_directory_meta.post_id = post.ID
                                AND legacy_directory_meta.meta_key = '_directory_type'
                            ORDER BY legacy_directory_meta.meta_id DESC
                            LIMIT 1
                        ) AS UNSIGNED)
                        AND legacy_package.plan_id = CAST((
                            SELECT legacy_plan_meta.meta_value
                            FROM {$postmeta_table} AS legacy_plan_meta
                            WHERE legacy_plan_meta.post_id = post.ID
                                AND legacy_plan_meta.meta_key = '_plan_id'
                            ORDER BY legacy_plan_meta.meta_id DESC
                            LIMIT 1
                        ) AS UNSIGNED)
                        AND legacy_package.is_legacy = 1
                        AND legacy_package.status IN (%s, %s)
                ) AS has_valid_legacy_assignment
            FROM {$posts_table} AS post
            WHERE post.ID IN ({$placeholders})
            ORDER BY post.ID ASC",
            array_merge(
                [
                    UserPackageStatus::ACTIVE,
                    UserPackageStatus::CANCELLED_AT_PERIOD_END,
                    UserPackageStatus::ACTIVE,
                    UserPackageStatus::CANCELLED_AT_PERIOD_END,
                ],
                array_map( 'absint', $listing_ids )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
        $rows = $wpdb->get_results( $sql );

        if ( ! empty( $wpdb->last_error ) ) {
            return $this->fail( $state, $wpdb->last_error );
        }

        foreach ( $rows as $row ) {
            if ( ! empty( $row->has_valid_legacy_assignment ) || empty( $row->target_plan_id ) ) {
                continue;
            }

            delete_post_meta( (int) $row->ID, '_plan_id' );
            add_post_meta( (int) $row->ID, '_plan_id', (int) $row->target_plan_id, true );
        }

        if ( ! empty( $wpdb->last_error ) ) {
            return $this->fail( $state, $wpdb->last_error );
        }

        $state['cursor']    = (int) end( $listing_ids );
        $state['processed'] = min( (int) $state['total'], (int) $state['processed'] + count( $listing_ids ) );

        update_option( self::STATE_OPTION, $state, false );

        return count( $listing_ids ) < self::BATCH_SIZE
            ? $this->finish()
            : false;
    }

    private function finish(): bool {
        delete_option( self::STATE_OPTION );
        return true;
    }

    private function fail( array $state, string $error ): bool {
        $state['status'] = 'failed';
        $state['error']  = sanitize_text_field( $error );
        update_option( self::STATE_OPTION, $state, false );

        return false;
    }
}
