<?php

namespace DirectoristPricingPlan\App\Repositories\Admin;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\WpMVC\Repositories\Repository;
use DirectoristPricingPlan\WpMVC\Database\Query\Builder;

use DirectoristPricingPlan\App\DTO\Plan\Read;
use DirectoristPricingPlan\App\DTO\Plan\DTO;

use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\App\Models\Plan;
use DirectoristPricingPlan\App\Models\Post;
use DirectoristPricingPlan\App\Models\PostMeta;
use DirectoristPricingPlan\App\Models\UserPackage;

use DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository;
use DirectoristPricingPlan\App\Repositories\Admin\PlanAppConfigurationRepository;

class PlanRepository extends Repository {
    public PlanFeatureRepository $plan_feature_repository;

    public PlanAppConfigurationRepository $plan_app_configuration_repository;

    public function __construct( PlanFeatureRepository $plan_feature_repository, PlanAppConfigurationRepository $plan_app_configuration_repository ) {
        $this->plan_feature_repository           = $plan_feature_repository;
        $this->plan_app_configuration_repository = $plan_app_configuration_repository;
    }

    public function get_query_builder(): Builder {
        return Plan::query( "plan" );
    }

    public function get( Read $dto ) {
        $query = $this->get_query_builder()->left_join( "terms as directory_type_term", "directory_type_term.term_id", "=", "plan.directory_type_id" );

        if ( $dto->get_search() ) {
            $query->where( "plan.title", "like", "%" . $dto->get_search() . "%" )
                ->or_where( "directory_type_term.name", "like", "%" . $dto->get_search() . "%" );
        }

        $count_query = clone $query;

        $query->select( "plan.*", "directory_type_term.name as directory_type", "general_config_meta.meta_value as directory_type_config" );

        $query->left_join(
            "termmeta as general_config_meta", function( $join ) {
                $join->on_column( "directory_type_term.term_id", "=", "general_config_meta.term_id" )->on( "general_config_meta.meta_key", "=", "general_config" );
            }
        );

        $plans = $query->order_by( "plan.id", "desc" )->pagination( $dto->get_page(), $dto->get_per_page() );

        // Optimize plans processing with early return for empty results
        if ( empty( $plans ) ) {
            return [
                "total" => 0,
                "items" => [],
            ];
        }

        $plans = array_map(
            function( $plan ) {
                $plan->directory_type_config = maybe_unserialize( $plan->directory_type_config );
                $plan->has_directory_type    = ! empty( $plan->directory_type );
                return $plan;
            }, $plans
        );

        return [
            "total" => $count_query->count( "plan.id" ),
            "items" => $plans,
        ];
    }

    public function get_by_directory_type( $directory_type_id ) {
        return $this->get_query_builder()->where( "plan.directory_type_id", $directory_type_id )
        ->left_join( "terms as directory_type_term", "directory_type_term.term_id", "=", "plan.directory_type_id" )
        ->where( "plan.is_published", 1 )
        ->where( "plan.is_hidden_from_plans_list", 0 )
        ->order_by( "plan.sort_order", "asc" )
        ->get();
    }

    public function creates( array $dtos ) {
        return $this->get_query_builder()->insert(
            array_map(
                function( DTO $dto ) {
                    return $this->process_values( $dto->to_array() );
                }, $dtos
            )
        );
    }

    public function update_plan( DTO $dto ) {
        do_action( 'directorist_before_update_plan', $dto );

        $status = $this->get_query_builder()->where( 'id', $dto->get_id() )->update( $this->process_values( $dto->to_array() ) );

        if ( false === $status ) {
            throw new Exception( 'Failed to update the plan', 400 );
        }

        do_action( 'directorist_after_update_plan', $dto );

        return $status;
    }

    public function get_single( $id ) {
        $plan = $this->get_by_id( $id );

        if ( ! $plan ) {
            return null;
        }

        $plan->features           = $this->plan_feature_repository->get( $plan );
        $plan->app_configurations = $this->plan_app_configuration_repository->get_by_plan_id( (int) $plan->id );

        return $plan;
    }

    public function get_all_plans_by_directory_types( array $directory_type_ids ) {
        return $this->get_query_builder()->where_in( 'plan.directory_type_id', $directory_type_ids )->get();
    }

    public function get_directory_types_with_no_plans( ?int $user_id = null ) {
        $directory_types_with_no_plans = Post::query( 'post' )
            ->select( 
                'directory_type.meta_value AS directory_type_id',
            )
            ->join(
                PostMeta::get_table_name() . ' as directory_type', function( $join ) {
                    $join->on_column( 'post.ID', '=', 'directory_type.post_id' )
                    ->on( 'directory_type.meta_key', '=', '_directory_type' );
                } 
            )
            ->where( 'post.post_type', 'at_biz_dir' )
            ->where( 'post.post_status', 'publish' )
            ->where_raw( "directory_type.meta_value REGEXP '^[0-9]+$'" )
            ->where_exists( [$this, 'add_existing_directory_type_constraint'] )
            ->where_not_exists(
                function( Builder $query ) {
                    $query->select( '1' )
                    ->from( UserPackage::get_table_name() )
                    ->where_raw( 'directorist_user_packages.user_id = post.post_author' )
                    ->where_raw( 'directorist_user_packages.directory_type_id = CAST( directory_type.meta_value AS UNSIGNED )' )
                    ->where_in( 'directorist_user_packages.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] );
                }
            )
            ->group_by( 'directory_type_id' )
            ->get();

        return array_column( $directory_types_with_no_plans, 'directory_type_id' );
    }

    public function get_listing_owner_without_plans() {
        return Post::query( 'post' )
            ->select( 
                'post.post_author',
                'GROUP_CONCAT( DISTINCT directory_type.meta_value) as directory_type_ids'
            )
            ->join(
                PostMeta::get_table_name() . ' as directory_type', function( $join ) {
                    $join->on_column( 'post.ID', '=', 'directory_type.post_id' )
                    ->on( 'directory_type.meta_key', '=', '_directory_type' );
                } 
            )
            ->where( 'post.post_type', 'at_biz_dir' )
            ->where( 'post.post_status', 'publish' )
            ->where_raw( "directory_type.meta_value REGEXP '^[0-9]+$'" )
            ->where_exists( [$this, 'add_existing_directory_type_constraint'] )
            ->where_not_exists(
                function( Builder $query ) {
                    $query->select( '1' )
                    ->from( UserPackage::get_table_name() )
                    ->where_raw( 'directorist_user_packages.user_id = post.post_author' )
                    ->where_raw( 'directorist_user_packages.directory_type_id = CAST( directory_type.meta_value AS UNSIGNED )' )
                    ->where_in( 'directorist_user_packages.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] );
                }
            )
            ->group_by( 'post.post_author' )
            ->first();
    }

    public function add_existing_directory_type_constraint( Builder $query ) {
        $query->select( '1' )
            ->from( 'term_taxonomy' )
            ->where_raw( 'term_taxonomy.term_id = CAST( directory_type.meta_value AS UNSIGNED )' )
            ->where( 'term_taxonomy.taxonomy', ATBDP_DIRECTORY_TYPE );
    }

    public function make_exceeding_listings_as_private( int $listing_owner_id, int $directory_type_id, int $plan_max_limit ) {
        global $wpdb;
        
        $posts_table    = $wpdb->prefix . Post::get_table_name();
        $postmeta_table = $wpdb->prefix . PostMeta::get_table_name();

        // If the plan max limit is less than 1, make all listings private
        if ( $plan_max_limit < 1 ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
            $sql = $wpdb->prepare(
                "UPDATE {$posts_table} AS post
                INNER JOIN {$postmeta_table} AS directory_type 
                    ON post.ID = directory_type.post_id 
                    AND directory_type.meta_key = '_directory_type'
                SET post.post_status = 'private'
                WHERE post.post_author = %d
                    AND directory_type.meta_value = %d",
                $listing_owner_id,
                $directory_type_id
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            
            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
            return;
        }

        // Get the oldest listing which is within the range of plan max limit
        $post = Post::query( 'post' )
            ->select( 'post.ID' )
            ->join(
                PostMeta::get_table_name() . ' as directory_type', function( $join ) {
                    $join->on_column( 'post.ID', '=', 'directory_type.post_id' )
                        ->on( 'directory_type.meta_key', '=', '_directory_type' );
                } 
            )
            ->where( 'post.post_author', $listing_owner_id )
            ->where( 'post.post_type', 'at_biz_dir' )
            ->where( 'post.post_status', 'publish' )
            ->where( 'directory_type.meta_value', $directory_type_id )
            ->order_by( 'post.ID', 'desc' )
            ->offset( $plan_max_limit - 1 )
            ->first();

        if ( ! $post ) {
            return;
        }

        // Make the listings private which are older than the plan max limit
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
        $sql = $wpdb->prepare(
            "UPDATE {$posts_table} AS post
            INNER JOIN {$postmeta_table} AS directory_type 
                ON post.ID = directory_type.post_id 
                AND directory_type.meta_key = '_directory_type'
            SET post.post_status = 'private'
            WHERE post.ID < %d
                AND post.post_author = %d
                AND directory_type.meta_value = %d",
            $post->ID,
            $listing_owner_id,
            $directory_type_id
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
    }

    public function make_exceeding_featured_listings_as_regular( int $listing_owner_id, int $directory_type_id, int $plan_max_limit ) {
        global $wpdb;
        
        $posts_table    = $wpdb->prefix . Post::get_table_name();
        $postmeta_table = $wpdb->prefix . PostMeta::get_table_name();

        // If the plan max limit is less than 1, make all listings as regular
        if ( $plan_max_limit < 1 ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
            $sql = $wpdb->prepare(
                "UPDATE {$posts_table} AS post
                INNER JOIN {$postmeta_table} AS directory_type 
                    ON post.ID = directory_type.post_id 
                    AND directory_type.meta_key = '_directory_type'
                LEFT JOIN {$postmeta_table} AS featured 
                    ON post.ID = directory_type.post_id 
                    AND directory_type.meta_key = '_featured'
                SET featured.meta_value = 0
                WHERE post.post_author = %d
                    AND directory_type.meta_value = %d",
                $listing_owner_id,
                $directory_type_id
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            
            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
            return;
        }

        // Get the oldest listing which is within the range of allowed listings limit
        $post = Post::query( 'post' )
            ->select( 'post.ID' )
            ->join(
                PostMeta::get_table_name() . ' as directory_type', function( $join ) {
                    $join->on_column( 'post.ID', '=', 'directory_type.post_id' )
                        ->on( 'directory_type.meta_key', '=', '_directory_type' );
                } 
            )
            ->join(
                PostMeta::get_table_name() . ' as featured', function( $join ) {
                    $join->on_column( 'post.ID', '=', 'directory_type.post_id' )
                        ->on( 'featured.meta_key', '=', '_featured' );
                } 
            )
            ->where( 'post.post_author', $listing_owner_id )
            ->where( 'post.post_type', 'at_biz_dir' )
            ->where( 'directory_type.meta_value', $directory_type_id )
            ->where( 'featured.meta_value', 1 )
            ->order_by( 'post.ID', 'desc' )
            ->offset( $plan_max_limit - 1 )
            ->first();

        if ( ! $post ) {
            return;
        }

        // Make the listings regular which are older than the max limit
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
        $sql = $wpdb->prepare(
            "UPDATE {$posts_table} AS post
            INNER JOIN {$postmeta_table} AS directory_type 
                ON post.ID = directory_type.post_id 
                AND directory_type.meta_key = '_directory_type'
            LEFT JOIN {$postmeta_table} AS featured 
                ON post.ID = directory_type.post_id 
                AND directory_type.meta_key = '_featured'
            SET featured.meta_value = 0
            WHERE post.ID < %d
                AND post.post_author = %d
                AND directory_type.meta_value = %d",
            $post->ID,
            $listing_owner_id,
            $directory_type_id
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
    }

    public function make_listings_pending( int $listing_owner_id, int $directory_type_id ) {
        global $wpdb;
        
        $posts_table    = $wpdb->prefix . Post::get_table_name();
        $postmeta_table = $wpdb->prefix . PostMeta::get_table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
        $sql = $wpdb->prepare(
            "UPDATE {$posts_table} AS post
            INNER JOIN {$postmeta_table} AS directory_type 
                ON post.ID = directory_type.post_id 
                AND directory_type.meta_key = '_directory_type'
            SET post.post_status = 'pending'
            WHERE post.post_author = %d
                AND directory_type.meta_value = %d",
            $listing_owner_id,
            $directory_type_id
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
    }

    public function get_belonging_listing_ids( int $listing_owner_id, int $directory_type_id, array $post_statuses = [], int $limit = -1 ): array {
        $args = [
            'post_type'      => ATBDP_POST_TYPE,
            'post_status'    => $post_statuses ?: 'any',
            'author'         => $listing_owner_id,
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'orderby'        => [
                'date' => 'DESC',
                'ID'   => 'DESC',
            ],
            'meta_query'     => [
                [
                    'key'   => '_directory_type',
                    'value' => $directory_type_id,
                ],
            ],
        ];

        return array_map( 'intval', get_posts( $args ) );
    }

    public function renew_belonging_listings_expiration( int $listing_owner_id, int $directory_type_id, string $expiry_date, int $limit = -1, array $post_statuses = [ 'publish', 'expired' ] ): int {
        if ( empty( $expiry_date ) ) {
            return 0;
        }

        $listing_ids = $this->get_belonging_listing_ids( $listing_owner_id, $directory_type_id, $post_statuses, $limit );

        foreach ( $listing_ids as $listing_id ) {
            directorist_pricing_plan_set_listing_expiry_date( $listing_id, $expiry_date );
            directorist_set_listing_status( $listing_id, 'publish' );
        }

        return count( $listing_ids );
    }

    public function expire_belonging_listings( int $listing_owner_id, int $directory_type_id ): int {
        $listing_ids = $this->get_belonging_listing_ids( $listing_owner_id, $directory_type_id );
        $expiry_date = current_time( 'mysql' );

        foreach ( $listing_ids as $listing_id ) {
            directorist_pricing_plan_set_listing_expiry_date( $listing_id, $expiry_date );
            directorist_set_listing_status( $listing_id, 'expired' );
        }

        return count( $listing_ids );
    }

    public function publish_available_pending_listings( int $listing_owner_id, int $directory_type_id, int $plan_max_limit ) {
        if ( $plan_max_limit === -1 ) {
            global $wpdb;

            $posts_table    = $wpdb->prefix . Post::get_table_name();
            $postmeta_table = $wpdb->prefix . PostMeta::get_table_name();

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
            $sql = $wpdb->prepare(
                "UPDATE {$posts_table} AS post
                INNER JOIN {$postmeta_table} AS directory_type
                    ON post.ID = directory_type.post_id
                    AND directory_type.meta_key = '_directory_type'
                SET post.post_status = 'publish'
                WHERE post.post_author = %d
                    AND post.post_type = 'at_biz_dir'
                    AND post.post_status = 'pending'
                    AND directory_type.meta_value = %d",
                $listing_owner_id,
                $directory_type_id
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            return $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
        }

        // Get current total pending listings count
        $pending_listings_count = Post::query( 'post' )
            ->select( 'post.ID' )
            ->join(
                PostMeta::get_table_name() . ' as directory_type', function( $join ) {
                    $join->on_column( 'post.ID', '=', 'directory_type.post_id' )
                        ->on( 'directory_type.meta_key', '=', '_directory_type' );
                } 
            )
            ->where( 'post.post_author', $listing_owner_id )
            ->where( 'post.post_type', 'at_biz_dir' )
            ->where( 'post.post_status', 'pending' )
            ->where( 'directory_type.meta_value', $directory_type_id )
            ->count();

        $max_limit = min( $plan_max_limit, $pending_listings_count );

        if ( $max_limit < 1 ) {
            return 0;
        }

        // Get the oldest listing which is within the range of max limit
        $post = Post::query( 'post' )
            ->select( 'post.ID' )
            ->join(
                PostMeta::get_table_name() . ' as directory_type', function( $join ) {
                    $join->on_column( 'post.ID', '=', 'directory_type.post_id' )
                        ->on( 'directory_type.meta_key', '=', '_directory_type' );
                } 
            )
            ->where( 'post.post_author', $listing_owner_id )
            ->where( 'post.post_type', 'at_biz_dir' )
            ->where( 'post.post_status', 'pending' )
            ->where( 'directory_type.meta_value', $directory_type_id )
            ->order_by( 'post.ID', 'desc' )
            ->offset( $max_limit - 1 )
            ->first();

        if ( ! $post ) {
            return 0;
        }

        // Update the post status
        global $wpdb;

        $posts_table    = $wpdb->prefix . Post::get_table_name();
        $postmeta_table = $wpdb->prefix . PostMeta::get_table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
        $sql = $wpdb->prepare(
            "UPDATE {$posts_table} AS post
            INNER JOIN {$postmeta_table} AS directory_type 
                ON post.ID = directory_type.post_id 
                AND directory_type.meta_key = '_directory_type'
            SET post.post_status = 'publish'
            WHERE post.post_author = %d
                AND post.ID >= %d
                AND post.post_status = 'pending'
                AND directory_type.meta_value = %d",
            $listing_owner_id,
            $post->ID,
            $directory_type_id
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.

        return $result;
    }

    public function mark_listing_as_regular( int $listing_id ): void {
        $old_status = get_post_meta( $listing_id, '_featured', true );

        if ( empty( $old_status ) || '1' !== strval( $old_status ) ) {
            return;
        }
            
        $result = update_post_meta( $listing_id, '_featured', 0 );

        if ( true !== $result ) {
            throw new Exception( 'Failed to mark the listing as regular', 400 );
        }
    }

    public function mark_listing_as_featured( int $listing_id ): void {
        $old_status = get_post_meta( $listing_id, '_featured', true );

        if ( '1' === $old_status ) {
            return;
        }
            
        $result = update_post_meta( $listing_id, '_featured', 1 );

        if ( true !== $result ) {
            throw new Exception( 'Failed to mark the listing as featured', 400 );
        }
    }

    public function has_pending_listing( int $listing_owner_id ): bool {
        $pending_listing = Post::query( 'post' )
            ->select( 'post.ID' )
            ->where( 'post.post_author', $listing_owner_id )
            ->where( 'post.post_type', 'at_biz_dir' )
            ->where( 'post.post_status', 'pending' )
            ->first();

        return $pending_listing ? true : false;
    }

    public function to_dto( $plan ) {
        if ( ! $plan ) {
            return null;
        }

        return ( new DTO() )
            ->set_id( $plan->id )
            ->set_title( $plan->title )
            ->set_description( $plan->description )
            ->set_directory_type_id( $plan->directory_type_id )
            ->set_allowed_listings( $plan->allowed_listings )
            ->set_is_allowed_unlimited_listings( $plan->is_allowed_unlimited_listings )
            ->set_allowed_featured_listings( $plan->allowed_featured_listings )
            ->set_is_allowed_unlimited_featured_listings( $plan->is_allowed_unlimited_featured_listings )
            ->set_fee_type( $plan->fee_type )
            ->set_price( $plan->price )
            ->set_is_taxable( $plan->is_taxable )
            ->set_tax_type( $plan->tax_type )
            ->set_tax_rate( $plan->tax_rate )
            ->set_interval_type( $plan->interval_type )
            ->set_interval_count( $plan->interval_count )
            ->set_is_subscription_enabled( $plan->is_subscription_enabled )
            ->set_is_trial_enabled( $plan->is_trial_enabled )
            ->set_trial_interval_type( $plan->trial_interval_type )
            ->set_trial_interval_count( $plan->trial_interval_count )
            ->set_sort_order( $plan->sort_order )
            ->set_is_published( $plan->is_published )
            ->set_is_hidden_from_plans_list( $plan->is_hidden_from_plans_list )
            ->set_is_marked_as_recommended( $plan->is_marked_as_recommended )
            ->set_is_fallback( (bool) $plan->is_fallback )
            ->set_fallback_plan_id( (int) $plan->fallback_plan_id )
            ->set_listing_display_priority( (int) $plan->listing_display_priority )
            ->set_type( $plan->type ?? 'package' )
            ->set_is_featured( (bool) ( $plan->is_featured ?? false ) );
    }
}
