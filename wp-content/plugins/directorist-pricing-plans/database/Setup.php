<?php

namespace DirectoristPricingPlan\Database;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\DTO\Plan\DTO;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\WpMVC\Database\Schema\Blueprint;
use DirectoristPricingPlan\WpMVC\Database\Schema\Schema;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Plan\TrialInterval as PlanTrialInterval;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\App\Enums\Plan\TaxType;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;

class Setup {
    public const DEFAULT_PLANS_INITIALIZED_OPTION = 'directorist_pricing_plans_default_plans_initialized';
    public const QUERY_INDEXES_VERSION_OPTION     = 'directorist_pricing_plans_query_indexes_version';
    public const QUERY_INDEXES_VERSION            = '20260611-no-status';

    public function execute() {
        $prefix = "directorist_";

        // Plans
        $this->create_table(
            "{$prefix}plans", function( Blueprint $table ) {
                $table->big_increments( "id" );
                // Basic Information
                $table->string( "title", 100 );
                $table->text( "description" )->nullable();
                $table->integer( "directory_type_id" );
                // Listing Limits
                $table->integer( "allowed_listings" )->default( 0 );
                $table->tiny_integer( "is_allowed_unlimited_listings" )->default( 0 );
                $table->integer( "allowed_featured_listings" )->default( 0 );
                $table->tiny_integer( "is_allowed_unlimited_featured_listings" )->default( 0 );
                // Plan Type
                $table->enum( "fee_type", PlanFeeType::all() )->default( PlanFeeType::FREE );
                $table->decimal( "price", 10, 2 )->default( 0.00 );
                // Tax
                $table->tiny_integer( "is_taxable" )->default( 0 );
                $table->enum( "tax_type", TaxType::all() )->default( TaxType::FLAT );
                $table->decimal( "tax_rate", 10, 2 )->default( 0.00 );
                // Subscription
                $table->enum( "interval_type", PlanInterval::all() )->default( PlanInterval::MONTH );
                $table->integer( "interval_count" )->default( 1 );
                $table->tiny_integer( "is_subscription_enabled" )->default( 0 );
                // Trial
                $table->tiny_integer( "is_trial_enabled" )->default( 0 );
                $table->enum( "trial_interval_type", PlanTrialInterval::all() )->default( PlanTrialInterval::MONTH );
                $table->integer( "trial_interval_count" )->default( 1 );
                // Sort Order
                $table->integer( "sort_order" )->default( 0 );
                // Status
                $table->tiny_integer( "is_published" )->default( 0 );
                // Visibility
                $table->tiny_integer( "is_hidden_from_plans_list" )->default( 0 );
                $table->tiny_integer( "is_marked_as_recommended" )->default( 0 );
                // Fallback
                $table->tiny_integer( "is_fallback" )->default( 0 );
                $table->unsigned_big_integer( "fallback_plan_id" )->nullable();
                $table->integer( "listing_display_priority" )->default( 0 );
                // Plan Type
                $table->enum( "type", PlanType::all() )->default( PlanType::PACKAGE );
                $table->tiny_integer( "is_featured" )->default( 0 );

                $table->timestamps();
            }
        );

        // Plan Features
        $this->create_table(
            "{$prefix}plan_features", function( Blueprint $table ) use ( $prefix ) {
                $table->big_increments( "id" );
                $table->unsigned_big_integer( "plan_id" );
                $table->string( "feature_key", 150 );
                $table->tiny_integer( "is_enabled" )->default( 0 );
                $table->tiny_integer( "is_show_in_pricing_table" )->default( 0 );
                $table->json( "data" )->nullable();
                $table->integer( "sort_order" )->default( 0 );
                $table->foreign( 'plan_id' )->references( 'id' )->on( "{$prefix}plans" )->on_delete( 'cascade' );
                $table->timestamps();
            }
        );

        // Plan App Configurations
        $this->create_table(
            "{$prefix}plan_app_configurations", function( Blueprint $table ) use ( $prefix ) {
                $table->big_increments( "id" );
                $table->unsigned_big_integer( "plan_id" );
                $table->string( "type" );
                $table->string( "product_id" );
                $table->string( "product_price" );
                $table->foreign( 'plan_id' )->references( 'id' )->on( "{$prefix}plans" )->on_delete( 'cascade' );
                $table->timestamps();
            }
        );

        // -- Table: user_packages
        $this->create_table(
            "{$prefix}user_packages", function( Blueprint $table ) {
                $table->big_increments( "id" );
                $table->unsigned_big_integer( "user_id" );
                $table->string( "subscription_id" )->nullable();
                $table->string( "subscription_method" )->nullable();
                $table->string( "subscription_currency" )->nullable();
                $table->decimal( "subscription_amount", 10, 2 )->nullable();
                $table->unsigned_big_integer( "directory_type_id" );
                $table->unsigned_big_integer( "plan_id" )->nullable();
                $table->unsigned_big_integer( "listing_display_priority" );
                $table->unsigned_big_integer( "last_order_id" );
                $table->tiny_integer( "is_recurring" )->default( 0 );
                $table->tiny_integer( "is_trial" )->default( 0 );
                $table->tiny_integer( "is_legacy" )->default( 0 );
                $table->enum( "status", UserPackageStatus::all() )->default( UserPackageStatus::ACTIVE );
                $table->timestamp( "started_at" )->use_current();
                $table->timestamp( "current_period_end" )->nullable();
                $table->timestamp( "cancelled_at" )->nullable();
                $table->timestamps();

                $table->index(
                    ['user_id', 'directory_type_id', 'listing_display_priority'],
                    'idx_dpp_pkg_user_dir_priority'
                );
                $table->index(
                    ['user_id', 'plan_id', 'listing_display_priority'],
                    'idx_dpp_pkg_user_plan_priority'
                );
            }
        );

        // -- Table: package_orders
        $this->create_table(
            "{$prefix}package_orders", function( Blueprint $table ) {
                $table->big_increments( "id" );
                $table->unsigned_big_integer( "order_id" );
                $table->unsigned_big_integer( "package_id" );
            }
        );

        // -- Table: plan_order_meta
        $this->create_table(
            "{$prefix}plan_order_meta", function( Blueprint $table ) use ( $prefix ) {
                $table->big_increments( "id" );
                $table->unsigned_big_integer( "order_id" );
                $table->tiny_integer( "is_recurring" )->default( 0 );
                $table->tiny_integer( "is_trial" )->default( 0 );
                $table->enum( "interval_type", PlanInterval::all() )->nullable();
                $table->integer( "interval_count" )->nullable();
                $table->timestamp( "current_period_end" )->nullable();
                $table->unique( "order_id", "unique_{$prefix}plan_order_meta_order_id" );
            }
        );

        // -- Table: queues
        $this->create_table(
            "{$prefix}plans_queues", function( Blueprint $table ) {
                $table->big_increments( 'id' );
                $table->unsigned_big_integer( 'task_id' )->nullable();
                $table->string( 'task_type' );
                $table->json( 'task_data' )->nullable();
                $table->string( 'message' )->nullable();
                $table->enum( 'status', ['in_queue', 'in_progress', 'failed'] )->default( 'in_queue' );
                $table->timestamps();
            }
        );

        // -- Table: subscription_logs
        $this->create_table(
            "{$prefix}subscription_logs", function( Blueprint $table ) use ( $prefix ) {
                $table->big_increments( 'id' );
                $table->unsigned_big_integer( 'package_id' );
                $table->string( 'action', 255 );
                $table->string( 'triggered_by', 255 );
                $table->text( 'description' )->nullable();
                $table->timestamp( 'created_at' )->use_current();
                $table->foreign( 'package_id' )->references( 'id' )->on( "{$prefix}user_packages" )->on_delete( 'cascade' );
            }
        );

        $this->maybe_add_large_scale_query_indexes();
    }

    public function maybe_add_large_scale_query_indexes(): void {
        if ( self::QUERY_INDEXES_VERSION === get_option( self::QUERY_INDEXES_VERSION_OPTION ) ) {
            return;
        }

        $table_name = 'directorist_user_packages';

        if ( ! $this->table_exists( $table_name ) ) {
            return;
        }

        $indexes_added = [
            $this->add_index_if_missing(
                $table_name,
                'idx_dpp_pkg_user_dir_priority',
                ['user_id', 'directory_type_id', 'listing_display_priority']
            ),
            $this->add_index_if_missing(
                $table_name,
                'idx_dpp_pkg_user_plan_priority',
                ['user_id', 'plan_id', 'listing_display_priority']
            ),
        ];

        if ( ! in_array( false, $indexes_added, true ) ) {
            update_option( self::QUERY_INDEXES_VERSION_OPTION, self::QUERY_INDEXES_VERSION, false );
        }
    }

    private function create_table( string $table_name, callable $callback ): void {
        if ( $this->table_exists( $table_name ) ) {
            return;
        }

        Schema::create( $table_name, $callback );
    }

    public function maybe_create_default_plans_for_fresh_install() {
        if ( $this->has_initialized_default_plans() ) {
            return;
        }

        if ( $this->has_legacy_pricing_plan_data() || $this->has_existing_v4_plans() ) {
            $this->mark_default_plans_as_initialized();
            return;
        }

        $this->create_default_plans();
        $this->mark_default_plans_as_initialized();
    }

    public function create_default_plans() {
        $types = directorist_get_directories(
            [
                'hide_empty'   => false,
                'default_only' => false,
            ] 
        );

        $directory_type_ids = array_map(
            function( $type ) {
                return $type->term_id;
            }, $types 
        );

        $plans                      = Plan::query()->select( 'directory_type_id' )->where_in( 'directory_type_id', $directory_type_ids )->group_by( 'directory_type_id' )->get();
        $plan_directory_type_ids    = array_column( $plans, 'directory_type_id' );
        $missing_directory_type_ids = array_diff( $directory_type_ids, $plan_directory_type_ids );

        $dtos = [];

        foreach ( $missing_directory_type_ids as $directory_type_id ) {
            $dto    = ( new DTO )->set_type( PlanType::PACKAGE )->set_title( 'Basic Plan' )->set_is_published( 0 )->set_directory_type_id( $directory_type_id )->set_interval_type( PlanInterval::YEAR )->set_interval_count( 1 )->set_is_allowed_unlimited_listings( true )->set_is_allowed_unlimited_featured_listings( false )->set_is_fallback( true );
            $dtos[] = $dto;
        }

        if ( empty( $dtos ) ) {
            return;
        }

        directorist_pricing_plan_repository()->creates( $dtos );
    }

    public function mark_default_plans_as_initialized() {
        update_option( self::DEFAULT_PLANS_INITIALIZED_OPTION, true, false );
    }

    public function has_initialized_default_plans(): bool {
        return (bool) get_option( self::DEFAULT_PLANS_INITIALIZED_OPTION, false );
    }

    private function has_existing_v4_plans(): bool {
        return Plan::query()->count() > 0;
    }

    private function table_exists( string $table_name ): bool {
        global $wpdb;

        $full_table_name = $wpdb->prefix . $table_name;

        return $full_table_name === $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $full_table_name )
            )
        );
    }

    private function add_index_if_missing( string $table_name, string $index_name, array $columns ): bool {
        global $wpdb;

        $full_table_name = $wpdb->prefix . $table_name;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and index names are hard-coded by this setup class.
        $index_exists = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM `{$full_table_name}` WHERE Key_name = %s",
                $index_name
            )
        );

        if ( $index_exists ) {
            return true;
        }

        $columns_sql = implode(
            ', ',
            array_map(
                static function( string $column ): string {
                    return "`{$column}`";
                },
                $columns
            )
        );

        $result = $wpdb->query( "ALTER TABLE `{$full_table_name}` ADD INDEX `{$index_name}` ({$columns_sql})" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return false !== $result;
    }

    private function has_legacy_pricing_plan_data(): bool {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1",
                'atbdp_pricing_plans'
            )
        );
    }
}
