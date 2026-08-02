<?php

namespace DirectoristPricingPlan\App\Jobs;

defined( 'ABSPATH' ) || exit;

use stdClass;
use Directorist\Helpers\DateTime;
use DirectoristPricingPlan\App\DTO\UserPackage\Activation as UserPackageActivationDTO;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\TaxType as PlanTaxType;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as PackageStatus;
use DirectoristPricingPlan\App\Repositories\Admin\PlanAppConfigurationRepository;
use DirectoristPricingPlan\App\Repositories\OldDataMigrationRepository;

class OldDataMigrationQueue extends BaseSequence {
    protected $prefix = 'directorist_plans_migration';

    protected $action = 'old_data_migration_processor';

    private OldDataMigrationRepository $migration_repository;

    private PlanAppConfigurationRepository $plan_app_configuration_repository;

    /**
     * Plan config meta keys to exclude when identifying feature meta keys.
     */
    private const PLAN_CONFIG_KEYS = [
        'fm_description',
        '_recurrence_time',
        '_assign_to_directory',
        'plan_type',
        'is_featured_listing',
        'num_regular',
        'num_regular_unl',
        'num_featured',
        'num_featured_unl',
        'free_plan',
        'fm_price',
        'plan_tax',
        'plan_tax_type',
        'fm_tax',
        '_recurrence_period_term',
        'fm_length',
        '_atpp_recurring',
        '_hide_from_plans',
        'default_pln',
        '_dpp_plan_sorting_order',
        '_plan_post_id',
        '_dpp_playstore_product_id',
        '_dpp_playstore_product_price',
        '_dpp_appstore_product_id',
        '_dpp_appstore_product_price',
        '_thumbnail_id', 
        '_edit_lock', 
        '_edit_last',
    ];

    /**
     * Old post meta to new plan app configuration mapping.
     */
    private const PLAN_APP_CONFIGURATION_MAP = [
        'apple_app_store' => [
            'product_id'    => '_dpp_appstore_product_id',
            'product_price' => '_dpp_appstore_product_price',
        ],
        'google_play_store' => [
            'product_id'    => '_dpp_playstore_product_id',
            'product_price' => '_dpp_playstore_product_price',
        ],
    ];

    /**
     * Old order status to new status mapping.
     */
    private const ORDER_STATUS_MAP = [
        'completed' => 'paid',
        'created'   => 'unpaid',
        'pending'   => 'pending',
        'failed'    => 'failed',
        'cancelled' => 'cancelled',
        'refunded'  => 'refunded',
    ];

    public function __construct( OldDataMigrationRepository $migration_repository, PlanAppConfigurationRepository $plan_app_configuration_repository ) {
        $this->migration_repository               = $migration_repository;
        $this->plan_app_configuration_repository = $plan_app_configuration_repository;

        parent::__construct();
    }

    protected function triggered_error( ?array $error ) {
        $this->migration_repository->update_migration_error( $error );
    }

    protected function get_item( $item ) {
        return $item;
    }

    protected function perform_sequence_task( $item ) {
        if ( empty( $item['step'] ) ) {
            return false;
        }

        $step = $item['step'];

        try {
            switch ( $step ) {
                case 'plans':
                    $this->migrate_plans();
                    $this->bulk_update_listing_plan_meta();
                    $this->enqueue_order_batches_and_packages_init();
                    break;

                case 'orders':
                    $this->migrate_orders_batch( (int) ( $item['batch_size'] ?? 50 ) );
                    break;

                case 'packages_init':
                    if ( ! $this->are_orders_migrated() ) {
                        $this->migration_repository->update_step_error(
                            'packages',
                            __( 'Order migration is incomplete. Please retry the migration after all orders are migrated.', 'directorist-pricing-plans' )
                        );

                        return false;
                    }

                    $this->migration_repository->delete_step_error( 'packages' );

                    // Kick off the first user batch; subsequent batches self-enqueue.
                    $this->push_to_queue( [ 'step' => 'packages_users_batch', 'batch_size' => 10 ] );
                    $this->save();
                    break;

                case 'packages_users_batch':
                    $this->enqueue_user_package_batch(
                        (int) ( $item['batch_size'] ?? 10 )
                    );
                    break;

                default:
                    return false;
            }
        } catch ( \Throwable $e ) {
            error_log( sprintf( 'Directorist Pricing Plans Migration: Error in step "%s": %s', $step, $e->getMessage() ) );
            $this->migration_repository->update_step_error( $this->get_notice_step( $step ), $e->getMessage() );
        }

        return false;
    }

    /**
     * Normalize internal queue items to the notice/progress step names.
     *
     * @param string $step
     * @return string
     */
    private function get_notice_step( string $step ): string {
        if ( in_array( $step, [ 'packages_init', 'packages_users_batch' ], true ) ) {
            return 'packages';
        }

        return $step;
    }

    /**
     * Check if migration is needed.
     * Returns true if any of the following have un-migrated data:
     *   - Old atbdp_pricing_plans posts without _is_migrated meta
     *   - Old atbdp_orders posts without _is_migrated meta
     *   - Users with completed legacy paid orders but without _is_migrated_packages user meta
     *
     * @return bool
     */
    public function is_migration_needed(): bool {
        $plans_total = $this->migration_repository->query_plans_total();

        if ( $plans_total < 1  ) {
            return false;
        }

        if ( $plans_total !== $this->migration_repository->query_plans_migrated() ) {
            return true;
        }

        $orders_total = $this->migration_repository->query_orders_total();
        
        if ( $orders_total > 0 && $orders_total !== $this->migration_repository->query_orders_migrated() ) {
            return true;
        }

        $users_total = $this->migration_repository->query_users_total();
        
        if ( $users_total > 0 && $users_total !== $this->migration_repository->query_users_migrated() ) {
            return true;
        }

        return false;
    }

    /**
     * Start the migration process. Only enqueues the plans step; order batches
     * and per-user package steps are enqueued dynamically after plans complete.
     */
    public function start() {
        $this->migration_repository->clear_migration_notice_dismissal();
        $this->before_dispatch();

        $this->push_to_queue( [ 'step' => 'plans' ] )
            ->save();

        $this->dispatch_or_process();
    }

    /**
     * Retry failed migration by clearing errors and restarting.
     * Individual migrate_* methods are idempotent: already-migrated records are skipped.
     */
    public function retry_failed() {
        $this->migration_repository->delete_step_error( 'plans' );
        $this->migration_repository->delete_step_error( 'orders' );
        $this->migration_repository->delete_step_error( 'packages_init' );
        $this->migration_repository->delete_step_error( 'packages' );
        $this->migration_repository->delete_migration_error();

        $this->start();
    }

    /**
     * Get failed step count (how many steps have errors).
     *
     * @return int
     */
    public function get_failed_count(): int {
        $count = 0;

        foreach ( [ 'plans', 'orders', 'packages' ] as $step ) {
            if ( $this->migration_repository->has_step_error( $step ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get numeric migration progress for plans, orders, and user packages.
     * All values are computed in real time from the database.
     *
     * @return array{plans_total: int, plans_migrated: int, orders_total: int, orders_migrated: int, users_total: int, users_migrated: int}
     */
    public function get_progress(): array {
        return $this->migration_repository->get_progress();
    }

    /**
     * Get the current step being processed (based on completed steps).
     *
     * @return string|null
     */
    public function get_current_step(): ?string {
        if ( $this->migration_repository->has_step_error( 'plans' ) ) {
            return 'plans';
        }

        if ( $this->migration_repository->has_step_error( 'orders' ) ) {
            return 'orders';
        }

        if ( $this->migration_repository->has_step_error( 'packages' ) ) {
            return 'packages';
        }

        return null;
    }

    /**
     * Migrate old atbdp_pricing_plans posts to directorist_plans.
     */
    private function migrate_plans() {
        // Exclude posts already stamped with _is_migrated to support idempotent retries.
        $old_plans = $this->migration_repository->get_unmigrated_old_plans();

        if ( empty( $old_plans ) ) {
            return;
        }

        // Preserve any previously migrated entries (partial retry support).
        $plan_id_map = $this->migration_repository->get_plan_id_map();

        foreach ( $old_plans as $old_plan ) {
            $post_id       = (int) $old_plan->ID;
            $postmeta      = $this->migration_repository->get_postmeta_map( $post_id );
            $interval_type = PlanInterval::MONTH;

            if ( '1'  === (string) $postmeta['fm_length_unl'] ) {
                $interval_type = PlanInterval::LIFETIME;
            } else if ( ! empty( $postmeta['_recurrence_period_term'] ) && \in_array( $postmeta['_recurrence_period_term'], PlanInterval::all(), true ) ) {
                $interval_type = $postmeta['_recurrence_period_term'];
            }

            $allowed_regular_listings  = (int) ( $postmeta['num_regular'] ?? 0 );
            $allowed_featured_listings = (int) ( $postmeta['num_featured'] ?? 0 );
            $allowed_total_listings    = $allowed_regular_listings + $allowed_featured_listings;

            $plan_data = [
                'title'                                  => $old_plan->post_title,
                'description'                            => $postmeta['fm_description'] ?? '',
                'directory_type_id'                      => (int) ( $postmeta['_assign_to_directory'] ?? 0 ),
                'type'                                   => ! empty( $postmeta['plan_type'] ) && 'pay_per_listng' === $postmeta['plan_type'] ? PlanType::PAY_PER_LISTING : PlanType::PACKAGE,
                'is_featured'                            => (int) ( $postmeta['is_featured_listing'] ?? 0 ),
                'allowed_listings'                       => $allowed_total_listings,
                'is_allowed_unlimited_listings'          => ( ( $postmeta['num_regular_unl'] ?? '' ) === '1' ) ? 1 : 0,
                'allowed_featured_listings'              => $allowed_featured_listings,
                'is_allowed_unlimited_featured_listings' => ( ( $postmeta['num_featured_unl'] ?? '' ) === '1' ) ? 1 : 0,
                'fee_type'                               => ( ( $postmeta['free_plan'] ?? '' ) === '1' ) ? 'free' : 'paid',
                'price'                                  => (float) ( $postmeta['fm_price'] ?? 0 ),
                'is_taxable'                             => ( ( $postmeta['plan_tax'] ?? '' ) === '1' ) ? 1 : 0,
                'tax_type'                               => ! empty( $postmeta['plan_tax_type'] ) ? $postmeta['plan_tax_type'] : 'percent',
                'tax_rate'                               => (float) ( $postmeta['fm_tax'] ?? 0 ),
                'interval_type'                          => $interval_type,
                'interval_count'                         => (int) ( $postmeta['fm_length'] ?? 1 ),
                'is_subscription_enabled'                => ( ( $postmeta['_atpp_recurring'] ?? '' ) === 'yes' ) ? 1 : 0,
                'is_trial_enabled'                       => 0,
                'trial_interval_type'                    => 'month',
                'trial_interval_count'                   => 1,
                'sort_order'                             => 0,
                'is_published'                           => ( $old_plan->post_status === 'publish' ) ? 1 : 0,
                'is_hidden_from_plans_list'              => ( ( $postmeta['_hide_from_plans'] ?? '' ) === 'yes' ) ? 1 : 0,
                'is_marked_as_recommended'               => ( ( $postmeta['default_pln'] ?? '' ) === 'yes' ) ? 1 : 0,
                'fallback_plan_id'                       => null,
                'listing_display_priority'               => (int) ( $postmeta['_dpp_plan_sorting_order'] ?? 0 ),
            ];

            // Convert free pay_per_listing plans to package type.
            if ( PlanType::PAY_PER_LISTING === $plan_data['type'] && ( 'free' === $plan_data['fee_type'] || 0.0 === (float) $plan_data['price'] ) ) {
                $plan_data['type'] = PlanType::PACKAGE;

                if ( ! empty( $plan_data['is_featured'] ) ) {
                    $plan_data['allowed_listings']          = 0;
                    $plan_data['allowed_featured_listings'] = 1;
                } else {
                    $plan_data['allowed_listings']          = 1;
                    $plan_data['allowed_featured_listings'] = 0;
                }

                $plan_data['is_allowed_unlimited_listings']          = 0;
                $plan_data['is_allowed_unlimited_featured_listings'] = 0;
            }

            $new_plan_id = $this->migration_repository->create_plan( $plan_data );

            if ( $new_plan_id ) {
                $plan_id_map[ $post_id ] = $new_plan_id;
                $this->migrate_plan_features( $new_plan_id, $postmeta );
                $this->migrate_plan_app_configurations( $new_plan_id, $postmeta );
                $this->migration_repository->mark_post_as_migrated( $post_id );
            }
        }

        // Merge with any previously migrated entries rather than overwriting.
        $this->migration_repository->update_plan_id_map( $plan_id_map );

        // Bulk update listing meta after plans are migrated
        $this->migration_repository->set_listing_plan_meta_bulk_updated( false );
    }

    /**
     * Migrate plan features for an old plan post to a new plan.
     *
     * @param int   $new_plan_id
     * @param array $postmeta
     */
    private function migrate_plan_features( int $new_plan_id, array $postmeta ) {
        $config_keys = self::PLAN_CONFIG_KEYS;

        foreach ( $postmeta as $meta_key => $meta_value ) {
            $meta_value = (string) $meta_value;

            if ( 'cf_owner' === $meta_key ) {
                $show_in_pricing_table = 0;

                if ( isset( $postmeta['hide_Cowner'] ) ) {
                    $show_in_pricing_table = (int) $postmeta['hide_Cowner'] === 1 ? 0 : 1;
                }

                $this->migration_repository->create_plan_feature(
                    [
                        'plan_id'                  => $new_plan_id,
                        'feature_key'              => 'contact_listings_owner',
                        'is_enabled'               => '1' === $meta_value || 'yes' === $meta_value,
                        'is_show_in_pricing_table' => $show_in_pricing_table,
                        'data'                     => null,
                        'sort_order'               => 0,
                    ]
                );

                continue;
            }

            if ( 'fm_cs_review' === $meta_key ) {
                $show_in_pricing_table = 0;

                if ( isset( $postmeta['hide_review'] ) ) {
                    $show_in_pricing_table = (int) $postmeta['hide_review'] === 1 ? 0 : 1;
                }

                $this->migration_repository->create_plan_feature(
                    [
                        'plan_id'                  => $new_plan_id,
                        'feature_key'              => 'review',
                        'is_enabled'               => '1' === $meta_value || 'yes' === $meta_value,
                        'is_show_in_pricing_table' => $show_in_pricing_table,
                        'data'                     => null,
                        'sort_order'               => 0,
                    ]
                );

                continue;
            }

            if ( strpos( $meta_key, '_' ) !== 0 ) {
                continue;
            }

            $key_without_prefix = preg_replace( '/^(_+)?(fm_+)?/', '', $meta_key );

            if ( in_array( $meta_key, $config_keys, true ) || in_array( $key_without_prefix, $config_keys, true ) ) {
                continue;
            }

            if ( 
                strpos( $key_without_prefix, 'hide_' ) === 0 ||
                strpos( $key_without_prefix, 'unlimited_' ) === 0 ||
                strpos( $key_without_prefix, 'max_' ) === 0
            ) {
                continue;
            }

            $data = [];

            if ( isset( $postmeta[ "_max_{$key_without_prefix}" ] ) && '' !== $postmeta[ "_max_{$key_without_prefix}" ] ) {
                $data['limit'] = '' === $postmeta[ "_max_{$key_without_prefix}" ] ? '' : (int) $postmeta[ "_max_{$key_without_prefix}" ];
            }

            if ( isset( $postmeta[ "_unlimited_{$key_without_prefix}" ] ) ) {
                $data['is_unlimited'] = (int) $postmeta[ "_unlimited_{$key_without_prefix}" ] === 1;
            }

            if ( '_category' === $meta_key && isset( $postmeta['exclude_cat'] ) ) {
                if ( ! empty( $postmeta['exclude_cat'] ) ) {
                    $deserialized = maybe_unserialize( maybe_unserialize( $postmeta['exclude_cat'] ) );

                    if ( is_array( $deserialized ) && ! empty( $deserialized ) ) {
                        $data['exclude']     = array_map( 'absint',  array_values( $deserialized ) );
                        $data['is_excluded'] = 1;
                    }
                } else {
                    $data['exclude']     = [];
                    $data['is_excluded'] = 0;
                }
            }

            $feature_key_map = [
                '_fm_claim'        => 'claim_listing',
                '_fm_booking'      => 'bdb',
                '_fm_live_chat'    => 'live_chat',
                '_fm_mark_as_sold' => 'sold_badge',
                '_category'        => 'admin_category_select[]',
                '_tag'             => 'tax_input[at_biz_dir-tags][]',
                '_location'        => 'tax_input[at_biz_dir-location][]',
            ];

            $show_in_pricing_table = 0;

            if ( isset( $postmeta[ "_hide_{$key_without_prefix}" ] ) ) {
                $show_in_pricing_table = (int) $postmeta[ "_hide_{$key_without_prefix}" ] === 1 ? 0 : 1;
            }
            
            $this->migration_repository->create_plan_feature(
                [
                    'plan_id'                  => $new_plan_id,
                    'feature_key'              => isset( $feature_key_map[ $meta_key ] ) ? $feature_key_map[ $meta_key ] : $key_without_prefix,
                    'is_enabled'               => '1' === $meta_value || 'yes' === $meta_value,
                    'is_show_in_pricing_table' => $show_in_pricing_table,
                    'data'                     => wp_json_encode( $data ),
                    'sort_order'               => 0,
                ]
            );
        }
    }

    /**
     * Migrate legacy plan app configuration post meta to the new table.
     *
     * @param int   $new_plan_id
     * @param array $postmeta
     * @return void
     */
    private function migrate_plan_app_configurations( int $new_plan_id, array $postmeta ): void {
        $configurations = [];

        foreach ( self::PLAN_APP_CONFIGURATION_MAP as $type => $meta_keys ) {
            $product_id    = isset( $postmeta[ $meta_keys['product_id'] ] ) ? sanitize_text_field( (string) $postmeta[ $meta_keys['product_id'] ] ) : '';
            $product_price = isset( $postmeta[ $meta_keys['product_price'] ] ) ? sanitize_text_field( (string) $postmeta[ $meta_keys['product_price'] ] ) : '';

            if ( '' === $product_id && '' === $product_price ) {
                continue;
            }

            $configurations[] = [
                'type'          => $type,
                'product_id'    => $product_id,
                'product_price' => $product_price,
            ];
        }

        if ( empty( $configurations ) ) {
            return;
        }

        $this->plan_app_configuration_repository->update_configurations( $configurations, $new_plan_id );
    }

    /**
     * After plans are migrated, enqueue order batches (50 per item) followed by
     * a packages_init item — all in one queue save so they are processed in order.
     */
    private function enqueue_order_batches_and_packages_init() {
        $plan_id_map = $this->migration_repository->get_plan_id_map();

        if ( empty( $plan_id_map ) ) {
            return;
        }

        $total      = $this->migration_repository->count_migratable_orders();
        $batch_size = 50;

        // Always consume from the first currently-unmigrated page because each
        // successful batch removes rows from the set.
        for ( $remaining = max( 1, $total ); $remaining > 0; $remaining -= $batch_size ) {
            $this->push_to_queue( [ 'step' => 'orders', 'batch_size' => $batch_size ] );
        }

        // packages_init is the last item — it runs after all order batches
        $this->push_to_queue( [ 'step' => 'packages_init' ] );
        $this->save();
    }

    /**
     * Migrate a single batch of old atbdp_orders posts.
     *
     * @param int $batch_size
     */
    private function migrate_orders_batch( int $batch_size ) {
        $plan_id_map = $this->migration_repository->get_plan_id_map();

        if ( empty( $plan_id_map ) ) {
            return;
        }

        $old_orders = $this->migration_repository->get_unmigrated_old_orders_batch( $batch_size );

        if ( empty( $old_orders ) ) {
            return;
        }

        $currency       = function_exists( 'atbdp_get_payment_currency' ) ? atbdp_get_payment_currency() : 'USD';
        $new_plan_cache = [];

        foreach ( $old_orders as $old_order ) {
            $post_id  = (int) $old_order->ID;
            $postmeta = $this->migration_repository->get_postmeta_map( $post_id );

            $old_plan_id = isset( $postmeta['_fm_plan_ordered'] ) ? (int) $postmeta['_fm_plan_ordered'] : 0;
            $new_plan_id = $plan_id_map[ $old_plan_id ] ?? null;

            if ( $new_plan_id && ! isset( $new_plan_cache[ $new_plan_id ] ) ) {
                $new_plan_cache[ $new_plan_id ] = $this->migration_repository->get_plan_by_id( $new_plan_id );
            }

            $plan       = $new_plan_id && isset( $new_plan_cache[ $new_plan_id ] ) ? $new_plan_cache[ $new_plan_id ] : null;
            $tax_rate   = $plan ? (float) $plan->tax_rate : 0;
            $tax_type   = $plan ? $plan->tax_type : PlanTaxType::PERCENT;
            $amount     = (float) ( $postmeta['_amount'] ?? 0 );
            $old_status = $postmeta['_payment_status'] ?? 'pending';
            $new_status = 'trash' === $old_order->post_status ? 'cancelled' : ( self::ORDER_STATUS_MAP[ $old_status ] ?? 'pending' );
            $listing_id = $this->get_legacy_order_listing_id( $postmeta );
            $user_id    = $this->get_legacy_order_user_id( $old_order, $listing_id );

            $order_data = [
                'user_id'              => $user_id,
                'legacy_id'            => $post_id,
                'ref'                  => $new_plan_id ? (string) $new_plan_id : null,
                'ref_type'             => OrderRefType::PRICING_PLAN,
                'listing_id'           => $listing_id,
                'amount'               => $amount,
                'sub_total'            => $amount,
                'status'               => $new_status,
                'created_at'           => $old_order->post_date,
                'updated_at'           => $old_order->post_modified,
                'currency'             => $currency,
                'coupon_code'          => null,
                'coupon_discount'      => 0.00,
                'coupon_discount_type' => null,
                'subscription_id'      => null,
                'tax_rate'             => $tax_rate,
                'tax_type'             => $tax_type,
                'expires_at'           => null,
                'is_featured_listing'  => isset( $postmeta['_featured'] ) ? (int) $postmeta['_featured'] : 0,
            ];

            $new_order_id = $this->migration_repository->insert_order( $order_data );

            if ( $new_order_id ) {
                $this->migration_repository->mark_post_as_migrated( $post_id );

                $this->insert_payment( $new_order_id, $postmeta, $amount, $currency, $new_status, $old_order->post_date, $old_order->post_modified );
            }
        }
    }

    /**
     * Legacy order listing meta may be stored as a scalar ID or a serialized
     * single-item array.
     *
     * @param array $postmeta
     * @return int|null
     */
    private function get_legacy_order_listing_id( array $postmeta ): ?int {
        if ( ! isset( $postmeta['_listing_id'] ) ) {
            return null;
        }

        $listing_id = maybe_unserialize( maybe_unserialize( $postmeta['_listing_id'] ) );

        if ( is_array( $listing_id ) ) {
            $listing_id = reset( $listing_id );
        }

        $listing_id = absint( $listing_id );

        return $listing_id > 0 ? $listing_id : null;
    }

    /**
     * Some legacy plan orders were saved with post_author = 0. Recover the
     * customer from the attached listing when possible, otherwise preserve the
     * legacy guest user value.
     *
     * @param stdClass $old_order
     * @param int|null $listing_id
     * @return int
     */
    private function get_legacy_order_user_id( stdClass $old_order, ?int $listing_id ): int {
        $user_id = (int) $old_order->post_author;

        if ( $user_id > 0 ) {
            return $user_id;
        }

        if ( $listing_id ) {
            $listing_author = (int) get_post_field( 'post_author', $listing_id );

            if ( $listing_author > 0 ) {
                return $listing_author;
            }
        }

        return 0;
    }

    /**
     * Insert a payment record for a migrated order.
     *
     * @param int    $new_order_id
     * @param array  $postmeta
     * @param float  $amount
     * @param string $currency
     * @param string $status
     * @param string $created_at
     * @param string $updated_at
     */
    private function insert_payment( int $new_order_id, array $postmeta, float $amount, string $currency, string $status, string $created_at, string $updated_at ) {
        $this->migration_repository->insert_payment(
            [
                'method'         => $postmeta['_payment_gateway'] ?? '',
                'order_id'       => $new_order_id,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => $status,
                'transaction_id' => $postmeta['_transaction_id'] ?? null,
                'created_at'     => $created_at,
                'updated_at'     => $updated_at,
            ]
        );
    }

    /**
     * Check whether every legacy plan order has a migrated order record.
     *
     * @return bool
     */
    private function are_orders_migrated(): bool {
        return empty( $this->migration_repository->get_unmigrated_old_orders_batch( 1 ) );
    }

    /**
     * Bulk-insert `_plan_id` meta for all migrated listings
     * using a single SQL INSERT … SELECT statement with a CASE expression.
     * This replaces the previous per-row update_post_meta() loop.
     */
    private function bulk_update_listing_plan_meta() {
        $is_done = $this->migration_repository->is_listing_plan_meta_bulk_updated();

        if ( $is_done ) {
            return;
        }

        $plan_id_map = $this->migration_repository->get_plan_id_map();

        if ( empty( $plan_id_map ) ) {
            return;
        }

        $this->migration_repository->bulk_update_listing_plan_meta( $plan_id_map );
        $this->migration_repository->mark_listing_plan_meta_bulk_updated();
    }

    /**
     * Fetch one page of not-yet-migrated users and migrate them immediately.
     * If a full batch was returned, self-enqueue the next first page so we
     * never load thousands of users in a single PHP request.
     *
     * Users are selected from completed legacy paid plan orders.
     * Users who already have `_is_migrated_packages` user meta are excluded,
     * making retries safe and efficient.
     *
     * @param int $batch_size
     */
    private function enqueue_user_package_batch( int $batch_size ) {
        $plan_id_map = $this->migration_repository->get_plan_id_map();

        if ( empty( $plan_id_map ) ) {
            return;
        }

        $user_ids = $this->migration_repository->get_unmigrated_paid_user_ids_batch( $batch_size );

        foreach ( $user_ids as $user_id ) {
            $this->migrate_packages_for_user( (int) $user_id );
        }

        // If a full batch came back there are likely more users. Re-read from
        // the start because processed users are removed from the unmigrated set.
        if ( count( $user_ids ) === $batch_size ) {
            $this->push_to_queue( [ 'step' => 'packages_users_batch', 'batch_size' => $batch_size ] );
        }

        $this->save();
    }

    /**
     * Migrate packages for a single user across all migrated plans.
     * Called once per user from a `packages_users_batch` queue item.
     *
     * @param int $user_id
     */
    private function migrate_packages_for_user( int $user_id ) {
        $plan_id_map = $this->migration_repository->get_plan_id_map();

        foreach ( $plan_id_map as $old_plan_post_id => $new_plan_id ) {
            $plan = $this->migration_repository->get_plan_by_id( $new_plan_id );

            if ( ! $plan ) {
                continue;
            }

            if ( $this->migration_repository->user_plan_package_exists( $user_id, $new_plan_id ) ) {
                continue;
            }

            $last_order = $this->migration_repository->get_last_paid_order_for_user_plan( $user_id, $new_plan_id );

            if ( ! $last_order ) {
                continue; // No paid order → no package
            }

            $new_package_id = $this->create_migrated_package( $user_id, $plan, $last_order, (int) $old_plan_post_id );

            if ( $new_package_id ) {
                directorist_user_package_repository()->link_package_order( $new_package_id, (int) $last_order->id );
                $this->link_all_past_orders( $new_package_id, (int) $new_plan_id, $user_id, (int) $last_order->id );
            }
        }

        // Mark this user as fully migrated so retries skip them.
        $this->migration_repository->mark_user_packages_as_migrated( $user_id );
    }

    /**
     * Determine the correct package state for a user+plan and create it.
     *
     * @param int       $user_id
     * @param stdClass $plan
     * @param stdClass $last_order
     * @param int       $old_plan_post_id
     * @return int New package ID, or 0 on failure.
     */
    private function create_migrated_package( int $user_id, stdClass $plan, stdClass $last_order, int $old_plan_post_id ): int {
        $plan_type = $plan->type ?? PlanType::PACKAGE;

        if ( PlanType::PAY_PER_LISTING === $plan_type ) {
            return $this->activate_package_for_migration( $user_id, $plan, (int) $last_order->id );
        }

        // Package type: check for lifetime duration
        if ( PlanInterval::LIFETIME === $plan->interval_type ) {
            return $this->activate_package_for_migration( $user_id, $plan, (int) $last_order->id );
        }

        // Non-lifetime package: find the latest publish listing after order creation date
        $order_date    = $last_order->created_at;
        $listing_query = new \WP_Query(
            [
                'post_type'      => 'at_biz_dir',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'author'         => $user_id,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'date_query'     => [
                    [
                        'after'     => $order_date,
                        'inclusive' => false,
                    ],
                ],
                'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    [
                        'key'   => '_fm_plans',
                        'value' => (string) $old_plan_post_id,
                    ],
                ],
            ]
        );

        if ( ! $listing_query->have_posts() ) {
            // No listing after order date: activate with current date, expiry from plan settings
            return $this->activate_package_for_migration( $user_id, $plan, (int) $last_order->id );
        }

        $listing      = $listing_query->posts[0];
        $never_expire = get_post_meta( $listing->ID, '_never_expire', true );
        $expiry_date  = get_post_meta( $listing->ID, '_expiry_date', true );
        $is_unexpired = '1' === $never_expire || empty( $expiry_date ) || strtotime( $expiry_date ) > time();

        if ( $is_unexpired ) {
            // Unexpired listing: preserve the migrated expiry from the listing/order timeline.
            $started_at_str = ( $listing->post_date > $order_date ) ? $listing->post_date : $order_date;
            $period_end     = $this->calculate_expiry_from_date( $started_at_str, (int) $plan->interval_count, $plan->interval_type );
            return $this->activate_package_for_migration( $user_id, $plan, (int) $last_order->id, $period_end );
        }

        if ( $this->has_unused_listing_quota_for_migration( $user_id, $plan, $old_plan_post_id, $last_order->created_at ) ) {
            return $this->activate_package_for_migration( $user_id, $plan, (int) $last_order->id );
        }

        // All listings after order date are expired and the package quota is used up.
        return $this->create_expired_migrated_package( $user_id, $plan, (int) $last_order->id, $last_order->created_at );
    }

    /**
     * Check whether the current migrated package still has unused listing quota.
     *
     * @param int       $user_id
     * @param stdClass $plan
     * @param int       $old_plan_post_id
     * @param string    $order_date MySQL datetime string.
     * @return bool
     */
    private function has_unused_listing_quota_for_migration( int $user_id, stdClass $plan, int $old_plan_post_id, string $order_date ): bool {
        $has_unlimited_regular  = (bool) $plan->is_allowed_unlimited_listings;
        $has_unlimited_featured = (bool) $plan->is_allowed_unlimited_featured_listings;

        if ( $has_unlimited_regular || $has_unlimited_featured ) {
            return true;
        }

        $allowed_featured_listings = (int) $plan->allowed_featured_listings;
        $allowed_regular_listings  = max( 0, (int) $plan->allowed_listings - $allowed_featured_listings );

        $regular_listings_count  = $this->count_current_order_assigned_listings( $user_id, $old_plan_post_id, $order_date, false );
        $featured_listings_count = $this->count_current_order_assigned_listings( $user_id, $old_plan_post_id, $order_date, true );

        return $regular_listings_count < $allowed_regular_listings || $featured_listings_count < $allowed_featured_listings;
    }

    /**
     * Count listings assigned to the current legacy package inferred from the last order date.
     *
     * @param int    $user_id
     * @param int    $old_plan_post_id
     * @param string $order_date MySQL datetime string.
     * @param bool   $is_featured_listing
     * @return int
     */
    private function count_current_order_assigned_listings( int $user_id, int $old_plan_post_id, string $order_date, bool $is_featured_listing ): int {
        $meta_query = [
            [
                'key'   => '_fm_plans',
                'value' => (string) $old_plan_post_id,
            ],
        ];

        if ( $is_featured_listing ) {
            $meta_query[] = [
                'key'   => '_featured',
                'value' => '1',
            ];
        } else {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key'     => '_featured',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_featured',
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ];
        }

        $listing_query = new \WP_Query(
            [
                'post_type'           => 'at_biz_dir',
                'post_status'         => 'any',
                'posts_per_page'      => 1,
                'fields'              => 'ids',
                'author'              => $user_id,
                'ignore_sticky_posts' => true,
                'date_query'          => [
                    [
                        'after'     => $order_date,
                        'inclusive' => false,
                    ],
                ],
                'meta_query'          => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            ]
        );

        return (int) $listing_query->found_posts;
    }

    /**
     * Activate a package via UserPackageRepository with optional period_end override.
     *
     * @param int       $user_id
     * @param stdClass $plan
     * @param int       $order_id
     * @param DateTime|null $period_end
     * @return int New package ID.
     */
    private function activate_package_for_migration( int $user_id, stdClass $plan, int $order_id, ?DateTime $period_end = null ): int {
        $activation_dto = ( new UserPackageActivationDTO )
            ->set_user_id( $user_id )
            ->set_plan( $plan )
            ->set_order_id( $order_id )
            ->set_is_legacy( true );

        if ( null !== $period_end ) {
            $activation_dto->set_current_period_end( $period_end );
        }

        $package_dto = directorist_user_package_repository()->activate_package( $activation_dto );

        return $package_dto->get_id();
    }

    /**
     * Create an expired package record directly, bypassing the one-active-package constraint.
     *
     * @param int       $user_id
     * @param stdClass $plan
     * @param int       $order_id
     * @param string    $started_at MySQL datetime string.
     * @return int New package ID, or 0 on failure.
     */
    private function create_expired_migrated_package( int $user_id, stdClass $plan, int $order_id, string $started_at ): int {
        return $this->migration_repository->insert_expired_package(
            [
            'user_id'                  => $user_id,
            'plan_id'                  => (int) $plan->id,
            'last_order_id'            => $order_id,
            'directory_type_id'        => (int) $plan->directory_type_id,
            'listing_display_priority' => (int) $plan->listing_display_priority,
            'status'                   => PackageStatus::EXPIRED,
            'is_recurring'             => 0,
            'is_trial'                 => 0,
            'is_legacy'                => 1,
            'started_at'               => $started_at,
            'current_period_end'       => null,
            'cancelled_at'             => null,
            'created_at'               => $started_at,
            'updated_at'               => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Calculate an expiry DateTime from a given start date and plan interval.
     *
     * @param string $from_date     MySQL datetime string.
     * @param int    $interval_count
     * @param string $interval_type
     * @return DateTime|null Null for lifetime or zero-count plans.
     */
    private function calculate_expiry_from_date( string $from_date, int $interval_count, string $interval_type ): ?DateTime {
        if ( PlanInterval::LIFETIME === $interval_type || $interval_count <= 0 ) {
            return null;
        }

        $interval_map = [
            PlanInterval::DAY   => 'days',
            PlanInterval::WEEK  => 'weeks',
            PlanInterval::MONTH => 'months',
            PlanInterval::YEAR  => 'years',
        ];

        $unit = $interval_map[ $interval_type ] ?? 'months';
        $dt   = new \DateTime( $from_date );
        $dt->modify( "+{$interval_count} {$unit}" );

        return new DateTime( $dt->format( 'Y-m-d H:i:s' ) );
    }

    /**
     * Link all orders for a user+plan to the given package, excluding the last order
     * which is already linked via the package's last_order_id.
     *
     * @param int $package_id
     * @param int $plan_id
     * @param int $user_id
     * @param int $last_order_id Order ID already linked; excluded from this query.
     */
    private function link_all_past_orders( int $package_id, int $plan_id, int $user_id, int $last_order_id ): void {
        $order_ids = $this->migration_repository->get_order_ids_for_user_plan_except( $plan_id, $user_id, $last_order_id );

        $user_package_repository = directorist_user_package_repository();

        foreach ( $order_ids as $order_id ) {
            $user_package_repository->link_package_order( $package_id, (int) $order_id );
        }
    }
}
