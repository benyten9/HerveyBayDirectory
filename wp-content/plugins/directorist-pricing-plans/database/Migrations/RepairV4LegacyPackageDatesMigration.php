<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use Directorist\Helpers\DateTime;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\WpMVC\Contracts\Migration;

class RepairV4LegacyPackageDatesMigration implements Migration {
    public const STATE_OPTION   = 'directorist_repair_v4_legacy_package_dates_state';
    public const STATUS_PENDING = 'pending';

    private const BATCH_SIZE = 50;

    public function more_than_version() {
        return '4.0.2';
    }

    public function execute(): bool {
        global $wpdb;

        $packages_table = "{$wpdb->prefix}directorist_user_packages";
        $plans_table    = "{$wpdb->prefix}directorist_plans";
        $orders_table   = "{$wpdb->prefix}directorist_orders";

        if ( ! $this->table_exists( $packages_table ) || ! $this->table_exists( $plans_table ) || ! $this->table_exists( $orders_table ) ) {
            return true;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the WordPress table prefix and checked before use.
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$packages_table} WHERE is_legacy = 1" );

        if ( 0 === $total ) {
            delete_option( self::STATE_OPTION );
            return true;
        }

        $state = get_option( self::STATE_OPTION, [] );

        if ( is_array( $state ) && 'completed' === ( $state['status'] ?? '' ) ) {
            return true;
        }

        if ( ! is_array( $state ) || empty( $state ) ) {
            $state = [
                'status'            => self::STATUS_PENDING,
                'cursor'            => 0,
                'total'             => $total,
                'processed'         => 0,
                'repaired'          => 0,
                'skipped'           => 0,
                'repair_started_at' => '',
                'error'             => '',
            ];

            update_option( self::STATE_OPTION, $state, false );

            return false;
        }

        if ( self::STATUS_PENDING === ( $state['status'] ?? '' ) ) {
            return false;
        }

        if ( empty( $state['repair_started_at'] ) ) {
            $state['repair_started_at'] = current_time( 'mysql' );
        }

        $state['status'] = 'running';
        $state['error']  = '';

        update_option( self::STATE_OPTION, $state, false );

        try {
            $packages = $this->get_package_batch(
                $packages_table,
                $plans_table,
                $orders_table,
                (int) $state['cursor']
            );

            if ( empty( $packages ) ) {
                return $this->finish( $state );
            }

            $batch_repaired = 0;
            $batch_skipped  = 0;

            foreach ( $packages as $package ) {
                if ( ! $this->can_repair( $package ) ) {
                    $batch_skipped++;
                    continue;
                }

                $period_end = $this->resolve_period_end( $package, (string) $state['repair_started_at'] );
                $updated    = $wpdb->update(
                    $packages_table,
                    [
                        'started_at'         => $package->order_created_at,
                        'current_period_end' => $period_end ? $period_end->format( 'Y-m-d H:i:s' ) : null,
                    ],
                    [ 'id' => (int) $package->id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                if ( false === $updated ) {
                    throw new \RuntimeException(
                        sprintf(
                            /* translators: %d: package ID */
                            __( 'Could not repair legacy package %d.', 'directorist-pricing-plans' ),
                            (int) $package->id
                        )
                    );
                }

                $this->reschedule_expiration( $package, $period_end );
                $batch_repaired++;
            }

            $state['cursor']    = (int) end( $packages )->id;
            $state['processed'] = min( (int) $state['total'], (int) $state['processed'] + count( $packages ) );
            $state['repaired']  = (int) $state['repaired'] + $batch_repaired;
            $state['skipped']   = (int) $state['skipped'] + $batch_skipped;

            update_option( self::STATE_OPTION, $state, false );

            return count( $packages ) < self::BATCH_SIZE
                ? $this->finish( $state )
                : false;
        } catch ( \Throwable $exception ) {
            return $this->fail( $state, $exception->getMessage() );
        }
    }

    /**
     * Confirm and start the package date repair.
     */
    public function start(): bool {
        $state = get_option( self::STATE_OPTION, [] );

        if ( ! is_array( $state ) || self::STATUS_PENDING !== ( $state['status'] ?? '' ) ) {
            return false;
        }

        $state['status']            = 'running';
        $state['repair_started_at'] = current_time( 'mysql' );
        $state['error']             = '';

        return update_option( self::STATE_OPTION, $state, false );
    }

    private function table_exists( string $table ): bool {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    }

    /**
     * @return array<int, object>
     */
    private function get_package_batch( string $packages_table, string $plans_table, string $orders_table, int $cursor ): array {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the WordPress table prefix and checked before use.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT package.id,
                    package.user_id,
                    package.plan_id,
                    package.status,
                    package.started_at AS migration_date,
                    plan.id AS valid_plan_id,
                    plan.type AS plan_type,
                    plan.interval_type,
                    plan.interval_count,
                    plan_order.id AS valid_order_id,
                    plan_order.created_at AS order_created_at
                FROM {$packages_table} AS package
                LEFT JOIN {$plans_table} AS plan ON plan.id = package.plan_id
                LEFT JOIN {$orders_table} AS plan_order ON plan_order.id = package.last_order_id
                WHERE package.is_legacy = 1
                    AND package.id > %d
                ORDER BY package.id ASC
                LIMIT %d",
                $cursor,
                self::BATCH_SIZE
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private function can_repair( object $package ): bool {
        return ! empty( $package->valid_plan_id )
            && ! empty( $package->valid_order_id )
            && ! empty( $package->order_created_at )
            && ! empty( $package->migration_date )
            && '0000-00-00 00:00:00' !== $package->order_created_at
            && '0000-00-00 00:00:00' !== $package->migration_date;
    }

    private function resolve_period_end( object $package, string $repair_started_at ): ?DateTime {
        $plan_type = $package->plan_type ?? PlanType::PACKAGE;

        if ( PlanType::PAY_PER_LISTING === $plan_type || PlanInterval::LIFETIME === $package->interval_type || (int) $package->interval_count <= 0 ) {
            return null;
        }

        $latest_listing_date = $this->get_latest_listing_date(
            (int) $package->user_id,
            (int) $package->plan_id,
            $package->order_created_at,
            $package->migration_date
        );
        $period_start        = $latest_listing_date ?? $repair_started_at;

        return $this->calculate_expiry_from_date( $period_start, (int) $package->interval_count, $package->interval_type );
    }

    private function get_latest_listing_date( int $user_id, int $plan_id, string $after_date, string $before_date ): ?string {
        global $wpdb;

        $post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table names are provided by wpdb.
        $listing_date = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT listing.post_date
                FROM {$wpdb->posts} AS listing
                INNER JOIN {$wpdb->postmeta} AS plan_meta
                    ON plan_meta.post_id = listing.ID
                    AND plan_meta.meta_key = '_plan_id'
                    AND plan_meta.meta_value = %s
                WHERE listing.post_type = %s
                    AND listing.post_author = %d
                    AND listing.post_status NOT IN ( 'trash', 'auto-draft' )
                    AND listing.post_date > %s
                    AND listing.post_date <= %s
                ORDER BY listing.post_date DESC, listing.ID DESC
                LIMIT 1",
                (string) $plan_id,
                $post_type,
                $user_id,
                $after_date,
                $before_date
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_string( $listing_date ) && '' !== $listing_date ? $listing_date : null;
    }

    private function calculate_expiry_from_date( string $from_date, int $interval_count, string $interval_type ): DateTime {
        $interval_map = [
            PlanInterval::DAY   => 'days',
            PlanInterval::WEEK  => 'weeks',
            PlanInterval::MONTH => 'months',
            PlanInterval::YEAR  => 'years',
        ];
        $unit         = $interval_map[ $interval_type ] ?? 'months';
        $date         = new DateTime( $from_date );

        $date->modify( "+{$interval_count} {$unit}" );

        return $date;
    }

    private function reschedule_expiration( object $package, ?DateTime $period_end ): void {
        $args = [ [ 'package_id' => (int) $package->id ] ];

        $cleared = wp_clear_scheduled_hook( 'directorist_package_expiry_event', $args, true );

        if ( is_wp_error( $cleared ) ) {
            throw new \RuntimeException( $cleared->get_error_message() );
        }

        if ( null === $period_end || ! in_array( $package->status, [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ], true ) ) {
            return;
        }

        $scheduled = wp_schedule_single_event(
            $period_end->getTimestamp(),
            'directorist_package_expiry_event',
            $args,
            true
        );

        if ( false === $scheduled || is_wp_error( $scheduled ) ) {
            $message = is_wp_error( $scheduled )
                ? $scheduled->get_error_message()
                : __( 'Could not schedule the repaired package expiration.', 'directorist-pricing-plans' );

            throw new \RuntimeException( $message );
        }
    }

    private function finish( array $state ): bool {
        $state['status']       = 'completed';
        $state['processed']    = (int) $state['total'];
        $state['completed_at'] = current_time( 'mysql' );
        $state['error']        = '';

        update_option( self::STATE_OPTION, $state, false );

        return true;
    }

    private function fail( array $state, string $error ): bool {
        $state['status'] = 'failed';
        $state['error']  = sanitize_text_field( $error );

        update_option( self::STATE_OPTION, $state, false );

        return false;
    }
}
