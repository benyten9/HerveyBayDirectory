<?php

namespace DirectoristPricingPlan\App\Http\Controllers\Admin;

defined( "ABSPATH" ) || exit;

use WP_REST_Request;
use Directorist\Enums\Order\Status;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;
use DirectoristPricingPlan\App\Enums\Plan\FeeType as PlanFeeType;
use DirectoristPricingPlan\WpMVC\RequestValidator\Validator;
use DirectoristPricingPlan\WpMVC\Routing\Response;
use DirectoristPricingPlan\App\Http\Controllers\Controller;
use DirectoristPricingPlan\App\Jobs\UnassignedPlanOrderQueue;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;

class DirectoryController extends Controller {
    private UnassignedPlanOrderQueue $unassigned_plan_order_queue;

    public function __construct() {
        $this->unassigned_plan_order_queue = directorist_pricing_plans_singleton( UnassignedPlanOrderQueue::class );
    }

    public function get_categories( WP_REST_Request $request ) {
        $directory_type_id = absint( $request->get_param( 'directory_type_id' ) );

        $args = [
            'taxonomy'   => ATBDP_CATEGORY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if ( $directory_type_id ) {
            $args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'     => '_directory_type',
                    'value'   => $directory_type_id,
                    'compare' => 'LIKE',
                ],
            ];
        }

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            return Response::send( [] );
        }

        $categories = array_map(
            function ( $term ) {
                return [
                    'value' => $term->term_id,
                    'label' => $term->name,
                ];
            },
            $terms
        );

        return Response::send( array_values( $categories ) );
    }

    public function get_types( WP_REST_Request $request ) {
        $types = directorist_get_directories(
            [
                'hide_empty'   => false,
                'default_only' => false,
            ] 
        );

        $types = array_map(
            function( $type ) {
                return [
                    "value" => $type->term_id,
                    "label" => $type->name,
                ];
            }, $types 
        );

        return Response::send( $types );
    }

    public function assign_plans( Validator $validator, WP_REST_Request $request ) {
        $assignments = $this->normalize_plan_assignments( $request );

        if ( empty( $assignments ) ) {
            throw new Exception( esc_html__( "Assignment data is required", 'directorist-pricing-plans' ) );
        }

        /** @var PlanRepository $plan_repository */
        $plan_repository = directorist_pricing_plans_singleton( PlanRepository::class );

        // Validate each assignment
        foreach ( $assignments as $assignment ) {
            if ( ! isset( $assignment['directory_type_id'] ) ) {
                throw new Exception( esc_html__( "Directory type ID is required", 'directorist-pricing-plans' ) );
            }

            if ( ! isset( $assignment['plan_id'] ) ) {
                throw new Exception( esc_html__( "Plan ID is required", 'directorist-pricing-plans' ) );
            }

            if ( ! isset( $assignment['order_status'] ) ) {
                throw new Exception( esc_html__( "Order status is required", 'directorist-pricing-plans' ) );
            }

            if ( ! in_array( $assignment['order_status'], [ Status::PAID, Status::PENDING ] ) ) {
                throw new Exception( esc_html__( "Invalid order status", 'directorist-pricing-plans' ) );
            }

            // Extra safety: Prevent invalid combinations like FREE plan + PENDING status
            $plan_id           = (int) $assignment['plan_id'];
            $directory_type_id = absint( $assignment['directory_type_id'] );

            if ( ! get_term_by( 'id', $directory_type_id, ATBDP_DIRECTORY_TYPE ) ) {
                throw new Exception( esc_html__( "Invalid directory type selected.", 'directorist-pricing-plans' ) );
            }

            $plan = $plan_repository->get_query_builder()
                ->where( "plan.id", $plan_id )
                ->first();

            if ( ! $plan ) {
                throw new Exception( esc_html__( "Invalid plan selected.", 'directorist-pricing-plans' ) );
            }

            if ( absint( $plan->directory_type_id ) !== $directory_type_id ) {
                throw new Exception( esc_html__( "The selected plan does not belong to the selected directory type.", 'directorist-pricing-plans' ) );
            }

            if ( $plan->fee_type === PlanFeeType::FREE && $assignment['order_status'] === Status::PENDING ) {
                throw new Exception(
                    esc_html__(
                        "Free plans cannot be assigned with a pending order status.",
                        'directorist-pricing-plans'
                    )
                );
            }
        }

        $this->unassigned_plan_order_queue->dispatch_queue( $assignments );

        return Response::send(
            [
                "message" => esc_html__( "Your request has been queued. Orders for the associated plans are being generated for the listing owners", 'directorist-pricing-plans' ),
            ]
        );
    }

    public function plan_assignment_index(): array {
        return Response::send(
            [
                "items" => [],
                "total" => 0,
            ]
        );
    }

    public function listing_directory_assignment_index(): array {
        return Response::send(
            [
                "items" => [],
                "total" => 0,
            ]
        );
    }

    public function assign_listing_directory_types( Validator $validator, WP_REST_Request $request ) {
        $validator->validate(
            [
                "directory_type_id" => "required|numeric",
            ]
        );

        if ( ! current_user_can( 'manage_options' ) ) {
            throw new Exception( esc_html__( "You are not allowed to update listings.", 'directorist-pricing-plans' ) );
        }

        $directory_type_id = absint( $request->get_param( "directory_type_id" ) );

        if ( ! get_term_by( 'id', $directory_type_id, ATBDP_DIRECTORY_TYPE ) ) {
            throw new Exception( esc_html__( "Invalid directory type selected.", 'directorist-pricing-plans' ) );
        }

        $listing_ids = $this->get_listing_ids_without_directory_term();
        $assigned    = 0;

        foreach ( $listing_ids as $listing_id ) {
            update_post_meta( $listing_id, '_directory_type', $directory_type_id );
            wp_set_object_terms( $listing_id, $directory_type_id, ATBDP_DIRECTORY_TYPE );
            clean_post_cache( $listing_id );

            $assigned++;
        }

        return Response::send(
            [
                "message"        => esc_html__( "Directory types were assigned successfully.", 'directorist-pricing-plans' ),
                "assigned_count" => $assigned,
            ]
        );
    }

    private function get_listing_ids_without_directory_term(): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe.
            "SELECT post.ID
            FROM {$wpdb->posts} AS post
            WHERE post.post_type = %s
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->term_relationships} AS directory_relationship
                    INNER JOIN {$wpdb->term_taxonomy} AS directory_taxonomy
                        ON directory_relationship.term_taxonomy_id = directory_taxonomy.term_taxonomy_id
                    WHERE directory_relationship.object_id = post.ID
                        AND directory_taxonomy.taxonomy = %s
                )
            ORDER BY post.ID DESC",
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ATBDP_POST_TYPE,
            ATBDP_DIRECTORY_TYPE
        );

        return array_map( 'absint', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above.
    }

    private function normalize_plan_assignments( WP_REST_Request $request ): array {
        $assignments = $request->get_param( "assignments" );

        if ( is_array( $assignments ) ) {
            return $assignments;
        }

        $params      = $request->get_params();
        $assignments = [];

        foreach ( $params as $key => $value ) {
            if ( ! preg_match( '/^directory_(\d+)_plan_id$/', (string) $key, $matches ) ) {
                continue;
            }

            $directory_type_id = absint( $matches[1] );
            $plan_id           = absint( $value );
            $order_status      = $params[ "directory_{$directory_type_id}_order_status" ] ?? '';

            if ( ! $directory_type_id || ! $plan_id || ! $order_status ) {
                continue;
            }

            $assignments[] = [
                "directory_type_id" => $directory_type_id,
                "plan_id"           => $plan_id,
                "order_status"      => sanitize_key( $order_status ),
            ];
        }

        return $assignments;
    }
}
