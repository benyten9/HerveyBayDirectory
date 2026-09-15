<?php

namespace DirectoristPricingPlan\App\Repositories;

use stdClass;

defined( "ABSPATH" ) || exit;

use Directorist\Helpers\DateTime;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\DBModels\Payment;
use Directorist\DBModels\Order;
use Directorist\Enums\Order\Status as OrderStatus;
use Directorist\Repositories\OrderRepository;

use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;

use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Enums\Order\RefType as OrderRefType;
use DirectoristPricingPlan\App\DTO\UserPackage\Read;
use DirectoristPricingPlan\App\DTO\UserPackage\Activation as UserPackageActivationDTO;
use DirectoristPricingPlan\App\DTO\PackageOrder\Read as PackageOrderRead;
use DirectoristPricingPlan\App\DTO\PackageOrder\DTO as PackageOrderDTO;
use DirectoristPricingPlan\App\DTO\PlanOrderMeta\DTO as PlanOrderMetaDTO;
use DirectoristPricingPlan\App\DTO\UserPackage\DTO as UserPackageDTO;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Models\UserPackage;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Repositories\PackageOrderRepository;
use DirectoristPricingPlan\App\Repositories\PlanOrderMetaRepository;

class UserPackageRepository extends Repository {
    public PackageOrderRepository $package_order_repository;

    public OrderRepository $order_repository;

    public function __construct( PackageOrderRepository $package_order_repository, OrderRepository $order_repository ) {
        $this->package_order_repository = $package_order_repository;
        $this->order_repository         = $order_repository;
    }

    public function get_query_builder(): Builder {
        return UserPackage::query( 'package' );
    }

    public function get( Read $dto ) {
        $id_query = $this->get_package_index_query( $dto );

        return [
            "items" => $this->get_packages( clone $id_query, $dto ),
            "total" => $this->get_packages_total( clone $id_query, $dto ),
        ];
    }

    /**
     * Get plan IDs referenced by packages whose plans no longer exist.
     *
     * @return stdClass[]
     */
    public function get_missing_plan_groups(): array {
        return $this->get_query_builder()
            ->select(
                'package.plan_id',
                'MIN(package.directory_type_id) as directory_type_id',
                'COUNT(DISTINCT package.directory_type_id) as directory_type_count',
                'COUNT(package.id) as package_count'
            )
            ->left_join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
            ->where_not_null( 'package.plan_id' )
            ->where_null( 'plan.id' )
            ->group_by( 'package.plan_id' )
            ->order_by( 'package.plan_id', 'asc' )
            ->get();
    }

    /**
     * Replace missing package plan IDs and their matching pricing-plan order refs.
     *
     * @param array<int, int> $assignments Missing plan ID to replacement plan ID map.
     * @return array{packages: int, orders: int}
     */
    public function recover_missing_plans( array $assignments ): array {
        global $wpdb;

        $packages_table = $wpdb->prefix . UserPackage::get_table_name();
        $orders_table   = $wpdb->prefix . Order::get_table_name();
        $updated_at     = current_time( 'mysql' );
        $updated        = [
            'packages' => 0,
            'orders'   => 0,
        ];

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            throw new Exception( esc_html__( 'Failed to start package plan recovery.', 'directorist-pricing-plans' ), 500 );
        }

        try {
            foreach ( $assignments as $missing_plan_id => $new_plan_id ) {
                $package_count = $wpdb->update(
                    $packages_table,
                    [
                        'plan_id'    => $new_plan_id,
                        'updated_at' => $updated_at,
                    ],
                    [ 'plan_id' => $missing_plan_id ],
                    [ '%d', '%s' ],
                    [ '%d' ]
                );

                if ( false === $package_count ) {
                    throw new Exception( esc_html__( 'Failed to update packages with missing plans.', 'directorist-pricing-plans' ), 500 );
                }

                $order_count = $wpdb->update(
                    $orders_table,
                    [
                        'ref'        => (string) $new_plan_id,
                        'updated_at' => $updated_at,
                    ],
                    [
                        'ref'      => (string) $missing_plan_id,
                        'ref_type' => OrderRefType::PRICING_PLAN,
                    ],
                    [ '%s', '%s' ],
                    [ '%s', '%s' ]
                );

                if ( false === $order_count ) {
                    throw new Exception( esc_html__( 'Failed to update pricing plan orders.', 'directorist-pricing-plans' ), 500 );
                }

                $updated['packages'] += $package_count;
                $updated['orders']   += $order_count;
            }

            if ( false === $wpdb->query( 'COMMIT' ) ) {
                throw new Exception( esc_html__( 'Failed to commit package plan recovery.', 'directorist-pricing-plans' ), 500 );
            }
        } catch ( \Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' );
            throw $exception;
        }

        return $updated;
    }

    private function get_package_details_query(): Builder {
        return $this->get_query_builder()->select(
            'package.*',
            'users.user_email',
            'users.display_name as user_display_name',
            'plan.title as plan_title',
            'plan.fee_type as fee_type',
            'plan.price',
            'plan.interval_type as interval_type',
            'plan.interval_count as interval_count',
            'plan.trial_interval_type as trial_interval_type',
            'plan.trial_interval_count as trial_interval_count',
            'plan.type as plan_type',
            'plan.is_featured as is_plan_featured',
            'directory_type_term.name as directory_type',
            'general_config_meta.meta_value as directory_type_config',
            'payment.method as payment_method',
            'payment.currency'
        );
    }

    private function add_package_list_joins( Builder $query ): Builder {
        return $query->left_join( 'users', 'package.user_id', '=', 'users.ID' )
            ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
            ->left_join( "terms as directory_type_term", "directory_type_term.term_id", "=", "plan.directory_type_id" )
            ->left_join(
                "termmeta as general_config_meta", function( $join ) {
                    $join->on_column( "directory_type_term.term_id", "=", "general_config_meta.term_id" )->on( "general_config_meta.meta_key", "=", "general_config" );
                }
            )
            ->left_join( Payment::get_table_name() . ' as payment', 'package.last_order_id', '=', 'payment.order_id' );
    }

    private function get_package_index_query( Read $dto ): Builder {
        $query = $this->get_query_builder()->select( 'package.id' );
        $query->where_in( 'package.status', UserPackageStatus::visible() );

        if ( null !== $dto->get_user_id() ) {
            $query->where( 'package.user_id', $dto->get_user_id() );
        }

        if ( null !== $dto->get_directory_type_id() ) {
            $query->where( 'package.directory_type_id', $dto->get_directory_type_id() );
        }

        if ( null !== $dto->is_is_recurring() ) {
            $query->where( 'package.is_recurring', $dto->is_is_recurring() ? 1 : 0 );
        }

        if ( ! empty( $dto->get_search() ) ) {
            $this->add_package_list_joins( $query )
                ->where(
                    function( Builder $search_query ) use ( $dto ) {
                        $search = $dto->get_search();

                        $search_query
                            ->where_like( "package.id", $search )
                            ->or_where_like( "users.user_email", $search )
                            ->or_where_like( "users.display_name", $search )
                            ->or_where_like( "plan.title", $search )
                            ->or_where_like( "directory_type_term.name", $search )
                            ->or_where_like( "general_config_meta.meta_value", $search );
                    }
                );
        }

        return $query;
    }

    private function get_packages_total( Builder $query, Read $dto ): int {
        if ( empty( $dto->get_search() ) ) {
            return $query->count( 'package.id' );
        }

        return $query->distinct()->count( 'package.id' );
    }

    public function single( $id ) {
        $package = $this->get_query_builder()->select( 
            'package.*',
            'plan.title as plan_title',
            'plan.type as plan_type',
            'plan.is_featured as is_plan_featured',
            'plan.interval_type as interval_type',
            'plan.interval_count as interval_count',
            'directory_type_term.name as directory_type',
            'general_config_meta.meta_value as directory_type_config',
            'payment.method as payment_method',
            'payment.currency'
        )->with(
            [
                'user' => function( $query ) {
                    $query->select( 'ID', 'user_email', 'display_name' );
                },
            ]
        )
        ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
        ->left_join( "terms as directory_type_term", "directory_type_term.term_id", "=", "package.directory_type_id" )
        ->left_join(
            "termmeta as general_config_meta", function( $join ) {
                $join->on_column( "directory_type_term.term_id", "=", "general_config_meta.term_id" )->on( "general_config_meta.meta_key", "=", "general_config" );
            }
        )
        ->left_join( Payment::get_table_name() . ' as payment', 'package.last_order_id', '=', 'payment.order_id' )
        ->where( 'package.id', $id )
        ->first();

        if ( ! $package ) {
            return null;
        }

        return $this->format_item_with_usage_data( $package );
    }

    public function get_by_subscription_id( string $subscription_id ): ?stdClass {
        return $this->get_query_builder()->where( 'subscription_id', $subscription_id )->first();
    }

    public function get_prepaid_order_for_package( int $package_id ): ?stdClass {
        return $this->package_order_repository->get_query_builder()
            ->select( 'd_order.*' )
            ->join( Order::get_table_name() . ' as d_order', 'package_order.order_id', '=', 'd_order.id' )
            ->where( 'package_order.package_id', $package_id )
            ->where( 'd_order.status', OrderStatus::PREPAID )
            ->order_by_desc( 'd_order.id' )
            ->first();
    }

    public function get_pending_renewal_order_for_package( int $package_id ): ?stdClass {
        return $this->package_order_repository->get_query_builder()
            ->select( 'd_order.*' )
            ->join( Order::get_table_name() . ' as d_order', 'package_order.order_id', '=', 'd_order.id' )
            ->where( 'package_order.package_id', $package_id )
            ->where( 'd_order.status', OrderStatus::PENDING )
            ->order_by_desc( 'd_order.id' )
            ->first();
    }

    public function create_renewal_order_by_subscription_id( string $subscription_id ): ?OrderDTO {
        $package = $this->get_by_subscription_id( $subscription_id );

        if ( ! $package ) {
            return null;
        }

        $pending_order = $this->get_pending_renewal_order_for_package( (int) $package->id );

        if ( $pending_order ) {
            return $this->order_repository->to_dto( $pending_order );
        }

        $order_id = $this->create_renewal_order( $package, OrderStatus::PENDING );

        return $order_id ? $this->order_repository->to_dto( $this->order_repository->get_by_id( $order_id ) ) : null;
    }
    
    public function get_by_last_order_id( string $last_order_id ): ?stdClass {
        return $this->get_query_builder()->where( 'last_order_id', $last_order_id )->first();
    }

    /**
     * Get the active package assigned to a listing.
     *
     * Resolve the exact active package matching the listing's plan meta. While
     * the backfill is incomplete, retain the previous directory fallback.
     *
     * @param int $listing_id Listing post ID.
     * @return stdClass|null
     */
    public function get_listings_package( int $listing_id ): ?stdClass {
        $author_id = (int) get_post_field( 'post_author', $listing_id );

        if ( ! $author_id ) {
            return null;
        }

        $listing_plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );

        if ( directorist_is_listing_plan_meta_migrated() ) {
            return $listing_plan_id
                ? $this->get_active_package_for_listing_plan( $author_id, $listing_plan_id )
                : null;
        }

        $directory_type_id = directorist_get_listings_directory_type( $listing_id );

        if ( ! $directory_type_id ) {
            return null;
        }

        $active_packages = $this->get_active_packages_for_directory( $author_id, $directory_type_id );

        if ( $listing_plan_id ) {
            foreach ( $active_packages as $package ) {
                if ( (int) $package->plan_id === $listing_plan_id ) {
                    return $package;
                }
            }
        }

        return $active_packages[0] ?? null;
    }

    private function get_active_package_for_listing_plan( int $user_id, int $plan_id ): ?stdClass {
        $package = $this->get_query_builder()
            ->select(
                'package.*',
                'plan.title as plan_title',
                'plan.type as plan_type',
                'plan.is_featured as is_plan_featured'
            )
            ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
            ->where( 'package.user_id', $user_id )
            ->where( 'package.plan_id', $plan_id )
            ->where_in( 'package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] )
            ->order_by_desc( 'package.id' )
            ->first();

        return $package ?: null;
    }

    /**
     * Return all active/cancelled-at-period-end packages for a user in a given directory.
     * May return more than one row for users migrated from the old version.
     *
     * @param int $user_id
     * @param int $directory_type_id
     * @return stdClass[]
     */
    public function get_active_packages_for_directory( int $user_id, int $directory_type_id ): array {
        return $this->get_query_builder()
            ->select( 
                'package.*',
                'plan.title as plan_title',
                'plan.type as plan_type',
                'plan.is_featured as is_plan_featured',
            )
            ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
            ->where( 'package.user_id', $user_id )
            ->where( 'package.directory_type_id', $directory_type_id )
            ->where_in( 'package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] )
            ->order_by( 'package.id', 'desc' )
            ->get();
    }

    public function count_active_packages_for_directory( int $user_id, int $directory_type_id ): int {
        return $this->get_query_builder()
            ->select( 'package.id' )
            ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
            ->where( 'package.user_id', $user_id )
            ->where( 'package.directory_type_id', $directory_type_id )
            ->where_in( 'package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] )
            ->count();
    }

    public function get_legacy_package_by_plan_id( int $user_id, int $plan_id ): ?stdClass {
        $package = $this->get_query_builder()->select(
            'package.*',
            'plan.title as plan_title',
            'plan.type as plan_type',
            'plan.is_featured as is_plan_featured',
            'directory_type_term.name as directory_type',
            'general_config_meta.meta_value as directory_type_config'
        )
        ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
        ->left_join( 'terms as directory_type_term', 'directory_type_term.term_id', '=', 'plan.directory_type_id' )
        ->left_join(
            'termmeta as general_config_meta', function( $join ) {
                $join->on_column( 'directory_type_term.term_id', '=', 'general_config_meta.term_id' )->on( 'general_config_meta.meta_key', '=', 'general_config' );
            }
        )
        ->where( 'package.user_id', $user_id )
        ->where( 'package.plan_id', $plan_id )
        ->where( 'package.is_legacy', 1 )
        ->order_by( 'package.id', 'desc' )
        ->first();

        if ( ! $package ) {
            return null;
        }

        return $this->format_item_with_usage_data( $package );
    }

    public function get_package_by_plan( int $user_id, int $plan_id ): ?stdClass {
        $package = $this->get_query_builder()->select(
            'package.*',
            'plan.title as plan_title',
            'plan.type as plan_type',
            'plan.is_featured as is_plan_featured',
            'directory_type_term.name as directory_type',
            'general_config_meta.meta_value as directory_type_config'
        )
        ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
        ->left_join( 'terms as directory_type_term', 'directory_type_term.term_id', '=', 'plan.directory_type_id' )
        ->left_join(
            'termmeta as general_config_meta', function( $join ) {
                $join->on_column( 'directory_type_term.term_id', '=', 'general_config_meta.term_id' )->on( 'general_config_meta.meta_key', '=', 'general_config' );
            }
        )
        ->where( 'package.user_id', $user_id )
        ->where( 'package.plan_id', $plan_id )
        ->where_in( 'package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] )
        ->order_by( 'package.id', 'desc' )
        ->first();

        if ( ! $package ) {
            return null;
        }

        return $this->format_item_with_usage_data( $package );
    }

    protected function get_packages( Builder $query, Read $dto ) {
        $query
            ->order_by_desc( 'package.started_at' )
            ->order_by_desc( 'package.id' );

        if ( ! empty( $dto->get_search() ) ) {
            $query->group_by( 'package.id' );
        }

        $package_ids = array_map(
            'absint',
            wp_list_pluck(
                $query->pagination( $dto->get_page(), $dto->get_per_page() ),
                'id'
            )
        );

        if ( empty( $package_ids ) ) {
            return [];
        }

        $query = $this->add_package_list_joins(
            $this->get_package_details_query()
        )
            ->where_in( 'package.id', $package_ids )
            ->group_by( 'package.id' )
            ->order_by_raw( 'FIELD(package.id, ' . implode( ', ', $package_ids ) . ')' );

        return array_map(
            [ $this, 'format_item' ],
            $query->get()
        );
    }

    public function get_current_package( int $user_id, int $directory_type_id ): ?stdClass {
        $package = $this->get_query_builder()->select( 
            'package.*',
            'plan.title as plan_title',
            'plan.type as plan_type',
            'plan.is_featured as is_plan_featured',
            'directory_type_term.name as directory_type',
            'general_config_meta.meta_value as directory_type_config'
        )->with(
            [
                'user' => function( $query ) {
                    $query->select( 'ID', 'user_email', 'display_name' );
                },
            ]
        )
        ->join( Plan::get_table_name() . ' as plan', 'package.plan_id', '=', 'plan.id' )
        ->left_join( "terms as directory_type_term", "directory_type_term.term_id", "=", "package.directory_type_id" )
        ->left_join(
            "termmeta as general_config_meta", function( $join ) {
                $join->on_column( "directory_type_term.term_id", "=", "general_config_meta.term_id" )->on( "general_config_meta.meta_key", "=", "general_config" );
            }
        )
        ->where( 'package.user_id', $user_id )
        ->where( 'package.directory_type_id', $directory_type_id )
        ->where_in( 'package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] )
        ->order_by( 'package.id', 'desc' )
        ->first();

        if ( ! $package ) {
            return null;
        }

        return $this->format_item_with_usage_data( $package );
    }

    public function activate_package( UserPackageActivationDTO $activation_dto ): UserPackageDTO {
        $plan     = $activation_dto->get_plan();
        $user_id  = $activation_dto->get_user_id();
        $order_id = $activation_dto->get_order_id();

        $plan_type = $plan->type ?? PlanType::PACKAGE;

        // Pay per listing plans do not have package-level expiration.
        if ( PlanType::PAY_PER_LISTING === $plan_type ) {
            $is_trial           = false;
            $is_recurring       = false;
            $current_period_end = null;
        } else {
            $is_trial           = $activation_dto->is_is_trial() && $plan->is_trial_enabled && $plan->trial_interval_count > 0;
            $is_recurring       = $activation_dto->is_initialized( 'is_recurring' )
                ? $activation_dto->is_is_recurring()
                : directorist_plan_has_subscription( $plan );
            $current_period_end = $this->resolve_current_period_end( $activation_dto, $plan, $is_trial );
        }

        $package_dto = ( new UserPackageDTO )
            ->set_user_id( $user_id )
            ->set_directory_type_id( $plan->directory_type_id )
            ->set_plan_id( $plan->id )
            ->set_listing_display_priority( $plan->listing_display_priority )
            ->set_last_order_id( $order_id )
            ->set_is_recurring( $is_recurring )
            ->set_is_trial( $is_trial )
            ->set_is_legacy( $activation_dto->is_is_legacy() )
            ->set_status( UserPackageStatus::ACTIVE )
            ->set_started_at( $activation_dto->get_started_at() ?? directorist_now() )
            ->set_cancelled_at( null )
            ->set_current_period_end( $current_period_end );

        if ( ! $is_recurring ) {
            $package_dto
                ->set_subscription_id( null )
                ->set_subscription_method( null )
                ->set_subscription_currency( null )
                ->set_subscription_amount( null );
        }

        return $this->set_package( $package_dto, $order_id );
    }

    private function resolve_current_period_end( UserPackageActivationDTO $activation_dto, stdClass $plan, bool $is_trial ): ?DateTime {
        if ( null !== $activation_dto->get_current_period_end() ) {
            return $activation_dto->get_current_period_end();
        }

        $current_period_end = $this->resolve_current_period_end_from_interval(
            $activation_dto->get_interval_count(),
            $activation_dto->get_interval_type()
        );

        if ( null !== $current_period_end ) {
            return $current_period_end;
        }

        if ( $is_trial ) {
            return $this->resolve_current_period_end_from_interval(
                (int) $plan->trial_interval_count,
                $plan->trial_interval_type
            );
        }

        return $this->resolve_current_period_end_from_interval(
            (int) $plan->interval_count,
            $plan->interval_type
        );
    }

    private function resolve_current_period_end_from_interval( ?int $interval_count, ?string $interval_type ): ?DateTime {
        if ( null === $interval_count || null === $interval_type ) {
            return null;
        }

        $current_period_end = directorist_get_expiry_date( $interval_count, $interval_type );

        return $current_period_end ? new DateTime( $current_period_end ) : null;
    }

    public function set_package( UserPackageDTO $package_dto, ?int $order_id = null ): UserPackageDTO {
        if ( ! $package_dto->is_is_legacy() && ! directorist_allow_multiple_plans_per_directory_type() ) {
            $previous_packages = $this->get_query_builder()
                ->where( 'user_id', $package_dto->get_user_id() )
                ->where( 'directory_type_id', $package_dto->get_directory_type_id() )
                ->where( 'plan_id', '!=', $package_dto->get_plan_id() )
                ->where_in( 'package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] )
                ->get();

            foreach ( $previous_packages as $previous_package ) {
                $previous_package_dto = ( new UserPackageDTO() )
                    ->set_id( $previous_package->id )
                    ->set_status( UserPackageStatus::ARCHIVED )
                    ->set_cancelled_at( directorist_now() );

                $this->update( $previous_package_dto );

                $updated_dto = $this->to_dto( $previous_package )
                    ->set_status( $previous_package_dto->get_status() )
                    ->set_cancelled_at( $previous_package_dto->get_created_at() );

                do_action( 'directorist_package_updated', $updated_dto, $this->to_dto( $previous_package ) );
            }
        }

        $old_package = $this->get_query_builder()
            ->where( 'user_id', $package_dto->get_user_id() )
            ->where( 'directory_type_id', $package_dto->get_directory_type_id() )
            ->where( 'plan_id', $package_dto->get_plan_id() )
            ->first();

        $create_new_package = true;

        if ( $old_package ) {
            $user_package_id = $old_package->id;

            if ( $old_package->is_trial ) {
                $old_package_dto = ( new UserPackageDTO() )
                    ->set_id( $old_package->id )
                    ->set_status( UserPackageStatus::ARCHIVED )
                    ->set_cancelled_at( directorist_now() );

                $this->update( $old_package_dto );

                $updated_dto = $this->to_dto( $old_package )
                    ->set_status( $old_package_dto->get_status() )
                    ->set_cancelled_at( $old_package_dto->get_created_at() );

                do_action( 'directorist_package_updated', $updated_dto, $this->to_dto( $old_package ) );
            } else {
                $create_new_package = false;

                $package_dto->set_id( $old_package->id );

                $this->update( $package_dto );

                do_action( 'directorist_package_updated', $package_dto, $this->to_dto( $old_package ) );
            }
        } 
        
        if ( $create_new_package ) {
            $user_package_id = $this->create( $package_dto );

            if ( ! $user_package_id ) {
                throw new Exception( 'Failed to activate user package', 400 );
            }

            $package_dto->set_id( $user_package_id );
            
            do_action( 'directorist_package_created', $package_dto );
        }

        if ( $order_id ) {
            $this->link_package_order( $user_package_id, $order_id );
        }

        return $package_dto;
    }

    public function link_package_order( int $package_id, int $order_id ) {
        // Check if the order is already linked to the package
        $old_package_order = $this->package_order_repository->get_query_builder()
            ->where( 'package_id', $package_id )
            ->where( 'order_id', $order_id )
            ->first();

        if ( $old_package_order ) {
            return;
        }

        // Link the order to the package
        $package_order_dto = ( new PackageOrderDTO )
            ->set_order_id( $order_id )
            ->set_package_id( $package_id );

        $this->package_order_repository->create( $package_order_dto );
    }

    private function create_renewal_order( stdClass $package, string $status ): int {
        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

        if ( ! $plan ) {
            throw new Exception( 'The plan associated with this package is not available anymore.', 404 );
        }

        $amount   = null !== $package->subscription_amount ? (float) $package->subscription_amount : (float) $plan->price;
        $currency = ! empty( $package->subscription_currency ) ? $package->subscription_currency : atbdp_get_payment_currency();

        $order_dto = ( new OrderDTO() )
            ->set_user_id( (int) $package->user_id )
            ->set_ref_type( OrderRefType::PRICING_PLAN )
            ->set_ref( (string) $plan->id )
            ->set_currency( $currency )
            ->set_status( $status )
            ->set_amount( $amount )
            ->set_sub_total( $amount );

        if ( ! empty( $plan->is_taxable ) ) {
            $order_dto->set_tax_type( $plan->tax_type )->set_tax_rate( (float) $plan->tax_rate );
        }

        $order_id = (int) $this->order_repository->create( $order_dto );

        if ( ! $order_id ) {
            return 0;
        }

        $this->link_package_order( (int) $package->id, $order_id );
        $this->plan_order_meta_repository()->upsert_by_order_id( $this->build_renewal_order_meta_dto( $order_id, $package, $plan ) );

        return $order_id;
    }

    private function build_renewal_order_meta_dto( int $order_id, stdClass $package, stdClass $plan ): PlanOrderMetaDTO {
        $interval_type  = null;
        $interval_count = null;

        if ( PlanInterval::LIFETIME !== $plan->interval_type && (int) $plan->interval_count > 0 ) {
            $interval_type  = $plan->interval_type;
            $interval_count = (int) $plan->interval_count;
        }

        return ( new PlanOrderMetaDTO )
            ->set_order_id( $order_id )
            ->set_is_recurring( ! empty( $package->is_recurring ) )
            ->set_is_trial( false )
            ->set_interval_type( $interval_type )
            ->set_interval_count( $interval_count );
    }

    private function plan_order_meta_repository(): PlanOrderMetaRepository {
        return directorist_pricing_plans_singleton( PlanOrderMetaRepository::class );
    }

    public function has_activated_package_for_order( int $order_id ): bool {
        $package = $this->get_query_builder()
            ->where( 'last_order_id', $order_id )
            ->first();

        if ( $package ) {
            return true;
        }

        return (bool) $this->package_order_repository->get_query_builder()
            ->where( 'order_id', $order_id )
            ->first();
    }

    public function expire_package( int $package_id ): bool {
        $old_package = $this->get_query_builder()->where( 'id', $package_id )->first();

        if ( ! $old_package ) {
            throw new Exception( 'Package not found', 404 );
        }
        
        $new_package_dto = $this->to_dto( $old_package )
            ->set_status( UserPackageStatus::EXPIRED )
            ->set_current_period_end( null );

        $status = $this->update( $new_package_dto );

        if ( ! $status ) {
            throw new Exception( 'Failed to expire package', 400 );
        }

        do_action( 'directorist_package_updated', $new_package_dto, $this->to_dto( $old_package ) );
        
        return true;
    }

    public function mark_package_past_due( int $package_id ): bool {
        $old_package = $this->get_query_builder()->where( 'id', $package_id )->first();

        if ( ! $old_package ) {
            throw new Exception( 'Package not found', 404 );
        }

        $new_package_dto = $this->to_dto( $old_package )
            ->set_status( UserPackageStatus::PAST_DUE )
            ->set_current_period_end( null );

        $status = $this->update( $new_package_dto );

        if ( ! $status ) {
            throw new Exception( 'Failed to mark package as past due', 400 );
        }

        do_action( 'directorist_package_updated', $new_package_dto, $this->to_dto( $old_package ) );

        return true;
    }

    public function renew( int $package_id, ?int $order_id = null, ?string $triggered_by = null ): int {
        $old_package = $this->get_query_builder()->where( 'id', $package_id )->first();

        if ( ! $old_package ) {
            throw new Exception( 'Package not found', 404 );
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $old_package->plan_id );

        if ( ! $plan ) {
            throw new Exception( 'The plan associated with this package is not available anymore.', 404 );
        }

        if ( PlanType::PACKAGE !== ( $plan->type ?? PlanType::PACKAGE ) ) {
            throw new Exception( 'This package type cannot be renewed.', 400 );
        }

        if ( ! $order_id ) {
            $prepaid_order = $this->get_prepaid_order_for_package( $package_id );
            $order_id      = $prepaid_order ? (int) $prepaid_order->id : $this->create_renewal_order( $old_package, OrderStatus::PAID );
        }

        if ( ! $order_id ) {
            throw new Exception( 'Failed to create renewal order', 400 );
        }

        $period_end = $this->resolve_current_period_end_from_interval( (int) $plan->interval_count, $plan->interval_type );

        $new_package_dto = $this->to_dto( $old_package )
            ->set_status( UserPackageStatus::ACTIVE )
            ->set_last_order_id( $order_id )
            ->set_is_trial( false )
            ->set_current_period_end( $period_end )
            ->set_cancelled_at( null );

        $status = $this->update( $new_package_dto );

        if ( ! $status ) {
            throw new Exception( 'Failed to renew package', 400 );
        }

        $this->link_package_order( $package_id, $order_id );

        $order = $this->order_repository->get_by_id( $order_id );

        if ( $order && OrderStatus::PAID !== $order->status ) {
            $this->order_repository->update(
                ( new OrderDTO() )
                    ->set_id( $order_id )
                    ->set_status( OrderStatus::PAID )
            );
        }

        do_action( 'directorist_package_updated', $new_package_dto, $this->to_dto( $old_package ), $triggered_by ?? 'system' );

        if ( $period_end ) {
            $limit = ! empty( $plan->is_allowed_unlimited_listings ) ? -1 : (int) $plan->allowed_listings;

            directorist_pricing_plans_singleton( PlanRepository::class )->renew_belonging_listings_expiration(
                (int) $old_package->user_id,
                (int) $old_package->plan_id,
                $period_end->format( 'Y-m-d H:i:s' ),
                $limit
            );
        }

        return $order_id;
    }

    public function cancel_package( int $package_id, ?string $triggered_by = null ): bool {
        $old_package = $this->get_query_builder()->where( 'id', $package_id )->first();

        if ( ! $old_package ) {
            throw new Exception( 'Package not found', 404 );
        }

        if ( $old_package->status === UserPackageStatus::CANCELLED ) {
            return true;
        }

        $new_package_dto = $this->to_dto( $old_package )
            ->set_status( UserPackageStatus::CANCELLED )
            ->set_current_period_end( null )
            ->set_cancelled_at( directorist_now() );

        $status = $this->update( $new_package_dto );

        if ( ! $status ) {
            throw new Exception( 'Failed to cancel package', 400 );
        }

        do_action( 'directorist_package_updated', $new_package_dto, $this->to_dto( $old_package ), $triggered_by );
        
        return true;
    }

    public function cancel_package_at_period_end( int $package_id, ?string $triggered_by = null ): bool {
        $old_package = $this->get_query_builder()->where( 'id', $package_id )->first();

        if ( ! $old_package ) {
            throw new Exception( 'Package not found', 404 );
        }
        
        if ( $old_package->status === UserPackageStatus::CANCELLED_AT_PERIOD_END ) {
            return true;
        }

        $new_package_dto = $this->to_dto( $old_package )->set_status( UserPackageStatus::CANCELLED_AT_PERIOD_END );

        $status = $this->update( $new_package_dto );

        if ( ! $status ) {
            throw new Exception( 'Failed to cancel package at period end', 400 );
        }

        do_action( 'directorist_package_updated', $new_package_dto, $this->to_dto( $old_package ), $triggered_by );
        
        return true;
    }

    public function get_last_order( $id, string $column = 'id' ): ?OrderDTO {
        $package = $this->get_query_builder()->where( $column, $id )->first();
            
        if ( ! $package ) {
            return null;
        }

        $order = $this->order_repository->get_query_builder()->where( 'id', $package->last_order_id )->first();
        return $order ? $this->order_repository->to_dto( $order ) : null;
    }

    public function get_orders( PackageOrderRead $dto ) {
        return $this->package_order_repository->get( $dto );
    }

    public function update_plan_listing_display_priority( int $plan_id, int $listing_display_priority ) {
        return $this->get_query_builder()->where( 'plan_id', $plan_id )->update( [ 'listing_display_priority' => $listing_display_priority ] );
    }

    /**
     * Returns true if the user has ever activated a package for the given plan,
     * regardless of its current status (active, expired, cancelled, archived, etc.).
     */
    public function has_ever_used_plan( int $user_id, int $plan_id ): bool {
        return (bool) $this->get_query_builder()
            ->where( 'user_id', $user_id )
            ->where( 'plan_id', $plan_id )
            ->count( 'id' );
    }

    protected function format_item( stdClass $item ): stdClass {
        $is_expired_package = ( $item->plan_type ?? null ) !== PlanType::PAY_PER_LISTING
            && ! $this->is_empty_datetime( $item->current_period_end ?? null )
            && new DateTime( $item->current_period_end ) < directorist_now();

        if ( $is_expired_package && UserPackageStatus::PAST_DUE !== $item->status ) {
            $item->status = UserPackageStatus::ACTIVE === $item->status && ! empty( $item->is_recurring )
                ? UserPackageStatus::PAST_DUE
                : UserPackageStatus::EXPIRED;
        }

        $item->method                = ! empty( $item->payment_method ) ? $this->get_payment_method_title( $item->payment_method ) : null;
        $item->directory_type_config = ! empty( $item->directory_type_config ) ? maybe_unserialize( $item->directory_type_config ) : null;

        return $item;
    }

    protected function format_item_with_usage_data( stdClass $item ): stdClass {
        $item = $this->format_item( $item );

        $this->attach_plan_usage_data( $item );

        return $item;
    }

    public function attach_plan_usage_data( stdClass &$item ) {
        $package_usage = directorist_package_usage( ! empty( $item->is_legacy ) );
        $plan          = $package_usage->get_plan_by_id( $item->plan_id );

        if ( ! $plan ) {
            $item->uses = null;
            return;
        }

        try {
            $item->uses = $package_usage->get_uses_by_plan( $item->user_id, $plan );
        } catch ( Exception $e ) {
            $item->uses = null;
        }
    }

    public function get_usage_by_id( int $package_id, ?int $user_id = null ): ?stdClass {
        $query = $this->get_query_builder()->where( 'id', $package_id );

        if ( null !== $user_id ) {
            $query->where( 'user_id', $user_id );
        }

        $package = $query->first();

        if ( ! $package ) {
            return null;
        }

        $this->attach_plan_usage_data( $package );

        return $package;
    }

    public function to_dto( $package ): UserPackageDTO {
        return ( new UserPackageDTO )
            ->set_id( $package->id )
            ->set_user_id( $package->user_id )
            ->set_directory_type_id( $package->directory_type_id )
            ->set_plan_id( $package->plan_id )
            ->set_listing_display_priority( $package->listing_display_priority )
            ->set_last_order_id( $package->last_order_id )
            ->set_is_recurring( $package->is_recurring )
            ->set_is_trial( ! empty( $package->is_trial ) )
            ->set_is_legacy( ! empty( $package->is_legacy ) )
            ->set_status( $package->status )
            ->set_subscription_id( $package->subscription_id )
            ->set_subscription_method( $package->subscription_method )
            ->set_subscription_currency( $package->subscription_currency )
            ->set_subscription_amount( $package->subscription_amount )
            ->set_started_at( $this->to_nullable_datetime( $package->started_at ?? null ) )
            ->set_current_period_end( $this->to_nullable_datetime( $package->current_period_end ?? null ) )
            ->set_cancelled_at( $this->to_nullable_datetime( $package->cancelled_at ?? null ) )
            ->set_created_at( $this->to_nullable_datetime( $package->created_at ?? null ) )
            ->set_updated_at( $this->to_nullable_datetime( $package->updated_at ?? null ) );
    }

    private function get_payment_method_title( $payment_method ) {
        $payment_method_title = get_directorist_option( "{$payment_method}_title" );
        return ! empty( $payment_method_title ) ? $payment_method_title : $payment_method;
    }

    private function to_nullable_datetime( $value ): ?DateTime {
        if ( $this->is_empty_datetime( $value ) ) {
            return null;
        }

        return new DateTime( $value );
    }

    private function is_empty_datetime( $value ): bool {
        return empty( $value ) || '0000-00-00 00:00:00' === $value;
    }
}
