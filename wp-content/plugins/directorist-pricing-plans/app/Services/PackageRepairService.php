<?php

namespace DirectoristPricingPlan\App\Services;

defined( 'ABSPATH' ) || exit;

use Directorist\DBModels\Order;
use DirectoristPricingPlan\App\Enums\Order\RefType;
use DirectoristPricingPlan\App\Models\PackageOrder;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Models\UserPackage;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolated identifiers come exclusively from wpdb and model table names; values use placeholders.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries passed to rows() are prepared at their call sites.
class PackageRepairService {
    /** Get the same repair groups used by discovery and submission. */
    public function get_groups( bool $lock = false ): array {
        global $wpdb;
        $packages = $wpdb->prefix . UserPackage::get_table_name();
        $plans    = $wpdb->prefix . Plan::get_table_name();
        $suffix   = $lock ? ' FOR UPDATE' : '';
        $rows     = $this->rows(
            $wpdb->prepare(
                "SELECT p.*, plan.title AS plan_title, plan.id AS existing_plan_id,
                    term.term_id AS existing_directory_id, term.name AS directory_type_name
                FROM {$packages} p
                LEFT JOIN {$plans} plan ON plan.id = p.plan_id
                LEFT JOIN {$wpdb->term_taxonomy} dt ON dt.term_id = p.directory_type_id AND dt.taxonomy = %s
                LEFT JOIN {$wpdb->terms} term ON term.term_id = dt.term_id
                WHERE plan.id IS NULL OR term.term_id IS NULL
                ORDER BY p.id{$suffix}",
                ATBDP_DIRECTORY_TYPE
            )
        );
        $groups   = [];
        foreach ( $rows as $row ) {
            $key = (int) $row->plan_id . ':' . (int) $row->directory_type_id;
            if ( ! isset( $groups[$key] ) ) {
                $groups[$key] = [
                    'key'                 => $key,
                    'plan_id'             => (int) $row->plan_id,
                    'directory_type_id'   => (int) $row->directory_type_id,
                    'plan_title'          => $row->plan_title ?: '#' . (int) $row->plan_id,
                    'directory_type_name' => $row->directory_type_name ?: '#' . (int) $row->directory_type_id,
                    'missing_plan'        => null === $row->existing_plan_id,
                    'missing_directory'   => null === $row->existing_directory_id,
                    'packages'            => [],
                ];
            }
            $groups[$key]['packages'][] = $row;
        }
        foreach ( $groups as &$group ) {
            $group['package_count'] = count( $group['packages'] );
            $group['version']       = hash( 'sha256', wp_json_encode( $group ) );
        }
        unset( $group );
        return $groups;
    }

    /** Listings are associated by author, original plan and original directory. */
    public function get_listing_ids( array $group, bool $lock = false ): array {
        global $wpdb;
        $suffix  = $lock ? ' FOR UPDATE' : '';
        $authors = implode( ',', array_unique( array_map( 'absint', array_column( $group['packages'], 'user_id' ) ) ) );
        return array_map(
            'intval', array_column(
                $this->rows(
                    $wpdb->prepare(
                        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->postmeta} plan ON plan.post_id = p.ID AND plan.meta_key = %s
                        WHERE p.post_type = %s AND p.post_author IN ({$authors}) AND plan.meta_value = %s
                        AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = '_directory_type' AND d.meta_value = %s)
                        OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
                            WHERE r.object_id = p.ID AND t.taxonomy = %s AND t.term_id = %d)){$suffix}",
                        directorist_plan_key(), ATBDP_POST_TYPE, (string) $group['plan_id'],
                        (string) $group['directory_type_id'], ATBDP_DIRECTORY_TYPE, $group['directory_type_id']
                    )
                ), 'ID'
            )
        );
    }

    /** Repair only submitted groups, retaining support for unambiguous S1 callers. */
    public function repair( array $submitted ): array {
        global $wpdb;
        if ( empty( $submitted ) ) {
            throw new Exception( esc_html__( 'Select at least one package group to repair.', 'directorist-pricing-plans' ), 400 );
        }
        $packages_table = $wpdb->prefix . UserPackage::get_table_name();
        $plans_table    = $wpdb->prefix . Plan::get_table_name();
        $orders_table   = $wpdb->prefix . Order::get_table_name();
        $links_table    = $wpdb->prefix . PackageOrder::get_table_name();
        $listing_ids    = [];
        $term_ids       = [];
        $updated        = [ 'packages' => 0, 'orders' => 0, 'listings' => 0 ];
        $this->check_write( $wpdb->query( 'START TRANSACTION' ) );
        try {
            $groups      = $this->get_groups( true );
            $assignments = [];
            $targets     = [];
            foreach ( $submitted as $assignment ) {
                if ( ! is_array( $assignment ) ) {
                    $this->invalid_assignment();
                }
                $old_plan = $this->id( $assignment['plan_id'] ?? $assignment['missing_plan_id'] ?? null, true );
                $matches  = array_filter(
                    $groups, static function( $group ) use ( $old_plan ) {
                        return $group['plan_id'] === $old_plan;
                    }
                );
                if ( ! array_key_exists( 'directory_type_id', $assignment ) ) {
                    if ( count( $matches ) !== 1 || ! reset( $matches )['missing_plan'] || reset( $matches )['missing_directory'] ) {
                        $this->stale();
                    }
                    $assignment['directory_type_id'] = reset( $matches )['directory_type_id'];
                }
                $old_directory = $this->id( $assignment['directory_type_id'], true );
                $key           = $old_plan . ':' . $old_directory;
                if ( ! isset( $groups[$key] ) || isset( $assignments[$key] ) ) {
                    $this->stale();
                }
                $group = $groups[$key];
                if ( isset( $assignment['version'] ) && $assignment['version'] !== $group['version'] ) {
                    $this->stale();
                }
                $new_plan      = $this->id( $assignment['new_plan_id'] ?? null );
                $new_directory = $this->id( $assignment['new_directory_type_id'] ?? ( $group['missing_directory'] ? null : $old_directory ) );
                if ( ! $group['missing_directory'] && $new_directory !== $old_directory ) {
                    $this->invalid_assignment();
                }
                $valid = $this->rows(
                    $wpdb->prepare(
                        "SELECT plan.id, t.term_taxonomy_id FROM {$plans_table} plan
                        INNER JOIN {$wpdb->term_taxonomy} t ON t.term_id = plan.directory_type_id AND t.taxonomy = %s
                        INNER JOIN {$wpdb->terms} term ON term.term_id = t.term_id
                        WHERE plan.id = %d AND plan.directory_type_id = %d AND plan.is_published = 1 FOR UPDATE",
                        ATBDP_DIRECTORY_TYPE, $new_plan, $new_directory
                    )
                );
                if ( empty( $valid ) ) {
                    throw new Exception( esc_html__( 'Select an existing directory type and a published plan belonging to it.', 'directorist-pricing-plans' ), 400 );
                }
                $term_ids[]                     = (int) $valid[0]->term_taxonomy_id;
                $group['new_plan_id']           = $new_plan;
                $group['new_directory_type_id'] = $new_directory;
                $group['listing_ids']           = $this->get_listing_ids( $group, true );
                $assignments[$key]              = $group;
                foreach ( $group['packages'] as $package ) {
                    $targets[(int) $package->id] = $new_plan;
                }
            }

            // Resolve everything before writes so one mapping cannot feed another.
            $order_targets   = [];
            $listing_targets = [];
            foreach ( $assignments as $group ) {
                foreach ( $group['listing_ids'] as $listing_id ) {
                    $target = [
                        'plan_id'                    => $group['new_plan_id'],
                        'directory_type_id'          => $group['new_directory_type_id'],
                        'original_directory_type_id' => $group['directory_type_id'],
                        'original_plan_id'           => $group['plan_id'],
                    ];
                    if ( isset( $listing_targets[$listing_id] ) && $listing_targets[$listing_id] !== $target ) {
                        $this->conflict();
                    }
                    $listing_targets[$listing_id] = $target;
                }
                $ids    = implode( ',', array_map( 'absint', array_column( $group['packages'], 'id' ) ) );
                $orders = $this->rows(
                    $wpdb->prepare(
                        "SELECT o.id FROM {$orders_table} o WHERE o.ref_type = %s AND o.ref = %s
                        AND (EXISTS (SELECT 1 FROM {$links_table} l WHERE l.order_id = o.id AND l.package_id IN ({$ids}))
                        OR EXISTS (SELECT 1 FROM {$packages_table} p WHERE p.last_order_id = o.id AND p.id IN ({$ids}))) FOR UPDATE",
                        RefType::PRICING_PLAN, (string) $group['plan_id']
                    )
                );
                foreach ( $orders as $order ) {
                    $owners = $this->rows(
                        $wpdb->prepare(
                            "SELECT p.id, p.plan_id FROM {$packages_table} p
                            WHERE p.last_order_id = %d OR EXISTS (
                                SELECT 1 FROM {$links_table} l WHERE l.package_id = p.id AND l.order_id = %d
                            ) FOR UPDATE",
                            $order->id, $order->id
                        )
                    );
                    foreach ( $owners as $owner ) {
                        if ( ( $targets[(int) $owner->id] ?? (int) $owner->plan_id ) !== $group['new_plan_id'] ) {
                            $this->conflict();
                        }
                    }
                    if ( isset( $order_targets[$order->id] ) && $order_targets[$order->id] !== $group['new_plan_id'] ) {
                        $this->conflict();
                    }
                    $order_targets[$order->id] = $group['new_plan_id'];
                }
            }

            // Refuse ambiguous listing ownership, including an unselected directory
            // represented by stale metadata or a second taxonomy assignment.
            foreach ( $listing_targets as $listing_id => $target ) {
                $metadata = $this->rows(
                    $wpdb->prepare(
                        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $listing_id
                    )
                );
                foreach ( $metadata as $meta ) {
                    if ( directorist_plan_key() === $meta->meta_key && (int) $meta->meta_value !== $target['original_plan_id'] ) {
                        $this->conflict();
                    }
                    if ( '_directory_type' === $meta->meta_key && (int) $meta->meta_value !== $target['original_directory_type_id'] ) {
                        $this->conflict();
                    }
                }
                $directories = $this->rows(
                    $wpdb->prepare(
                        "SELECT t.term_id FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
                    WHERE r.object_id = %d AND t.taxonomy = %s FOR UPDATE", $listing_id, ATBDP_DIRECTORY_TYPE
                    )
                );
                foreach ( $directories as $directory ) {
                    if ( (int) $directory->term_id !== $target['original_directory_type_id'] ) {
                        $this->conflict();
                    }
                }
            }

            $now = current_time( 'mysql' );
            foreach ( $assignments as $group ) {
                foreach ( $group['packages'] as $package ) {
                    $this->check_write(
                        $wpdb->update(
                            $packages_table,
                            [
                                'plan_id'           => $group['new_plan_id'],
                                'directory_type_id' => $group['new_directory_type_id'],
                                'updated_at'        => $now,
                            ],
                            [ 'id' => $package->id ],
                            [ '%d', '%d', '%s' ],
                            [ '%d' ]
                        )
                    );
                    $updated['packages']++;
                }
            }
            foreach ( $order_targets as $order_id => $plan_id ) {
                $updated['orders'] += $this->check_write(
                    $wpdb->update(
                        $orders_table,
                        [ 'ref' => (string) $plan_id, 'updated_at' => $now ],
                        [ 'id' => $order_id ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    )
                );
            }
            foreach ( $listing_targets as $listing_id => $target ) {
                $listing_ids[] = $listing_id;
                clean_post_cache( $listing_id );
                clean_object_term_cache( $listing_id, ATBDP_POST_TYPE );
                $this->update_listing_meta( $listing_id, directorist_plan_key(), $target['plan_id'] );
                if ( $target['directory_type_id'] !== $target['original_directory_type_id'] ) {
                    $old_terms = wp_get_object_terms( $listing_id, ATBDP_DIRECTORY_TYPE, [ 'fields' => 'tt_ids' ] );
                    if ( is_wp_error( $old_terms ) ) {
                        throw new Exception( esc_html__( 'Could not read listing directory types.', 'directorist-pricing-plans' ), 500 );
                    }
                    $term_ids = array_merge( $term_ids, $old_terms );
                    $this->update_listing_meta( $listing_id, '_directory_type', $target['directory_type_id'] );
                    $terms = wp_set_object_terms( $listing_id, $target['directory_type_id'], ATBDP_DIRECTORY_TYPE );
                    if ( is_wp_error( $terms ) || $wpdb->last_error ) {
                        throw new Exception( esc_html__( 'Could not update listing directory types.', 'directorist-pricing-plans' ), 500 );
                    }
                    $term_ids = array_merge( $term_ids, $terms );
                    $saved    = $this->rows(
                        $wpdb->prepare(
                            "SELECT t.term_id FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
                        WHERE r.object_id = %d AND t.taxonomy = %s", $listing_id, ATBDP_DIRECTORY_TYPE
                        )
                    );
                    if ( count( $saved ) !== 1 || (int) $saved[0]->term_id !== $target['directory_type_id'] ) {
                        throw new Exception( esc_html__( 'Could not update listing directory types.', 'directorist-pricing-plans' ), 500 );
                    }
                }
                $updated['listings']++;
            }
            $this->check_write( $wpdb->query( 'COMMIT' ) );
        } catch ( \Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            throw $error;
        } finally {
            foreach ( $listing_ids as $listing_id ) {
                clean_post_cache( $listing_id );
                clean_object_term_cache( $listing_id, ATBDP_POST_TYPE );
            }
            if ( $term_ids ) {
                wp_update_term_count_now( array_unique( $term_ids ), ATBDP_DIRECTORY_TYPE );
            }
        }
        return $updated;
    }

    private function update_listing_meta( int $listing_id, string $key, int $value ): void {
        if ( false === update_post_meta( $listing_id, $key, $value ) && (string) get_post_meta( $listing_id, $key, true ) !== (string) $value ) {
            throw new Exception( esc_html__( 'Could not update listing assignments.', 'directorist-pricing-plans' ), 500 );
        }
    }

    private function rows( string $sql ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $sql );
        if ( $wpdb->last_error ) {
            throw new Exception( esc_html__( 'Could not read package repair data.', 'directorist-pricing-plans' ), 500 );
        }
        return $rows ?: [];
    }

    private function check_write( $result ): int {
        if ( false === $result ) {
            throw new Exception( esc_html__( 'Package repair failed. No changes were saved.', 'directorist-pricing-plans' ), 500 );
        }
        return (int) $result;
    }

    private function id( $value, bool $allow_zero = false ): int {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || ( ! $allow_zero && (int) $value < 1 ) ) {
            $this->invalid_assignment();
        }
        return (int) $value;
    }

    private function invalid_assignment(): void {
        throw new Exception( esc_html__( 'Provide a valid replacement for each selected package group.', 'directorist-pricing-plans' ), 400 );
    }

    private function stale(): void {
        throw new Exception( esc_html__( 'Package repair data has changed. Refresh the page and try again.', 'directorist-pricing-plans' ), 409 );
    }

    private function conflict(): void {
        throw new Exception( esc_html__( 'The selected groups share listings or orders with conflicting plan assignments.', 'directorist-pricing-plans' ), 409 );
    }
}
