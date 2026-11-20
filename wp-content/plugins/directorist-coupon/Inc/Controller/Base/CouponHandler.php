<?php
/**
 * @package  Directorist - Coupon
 */

use Directorist\DTO\Order\DTO as OrderDTO;

if ( ! class_exists( 'SWBDPCouponHandler' ) ) {
    /**
    * Coupon handler class
    */
    class SWBDPCouponHandler {
        public function register() {
            add_action( 'atbdp_before_checkout_table', array( $this, 'swbdpc_coupon_code_input_field' ) );
            add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
            add_action( 'directorist_checkout_create_order', array( $this, 'swbdpc_apply_discount_new_checkout' ), 9, 3 );
            add_action( 'directorist_before_order_create', array( $this, 'revalidate_new_order' ), 5 );
        }

        public function register_rest_routes() {
            register_rest_route(
                'directorist-coupon/v1',
                '/coupons/validate',
                array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array( $this, 'validate_coupon_rest' ),
                        'permission_callback' => array( $this, 'rest_permission_check' ),
                        'args'                => array(
                            'directorist_order_coupon' => array(
                                'type'              => 'string',
                                'maxLength'         => 128,
                                'sanitize_callback' => 'sanitize_text_field',
                                'required'          => true,
                            ),
                        ),
                    ),
                )
            );
        }

        public function rest_permission_check() {
            return is_user_logged_in();
        }

        public function validate_coupon_rest( WP_REST_Request $request ) {
            try {
                \DirectoristCoupon\MCP\RateLimit::consume();
            } catch ( \RuntimeException $error ) {
                return method_exists( $error, 'as_error' ) ? $error->as_error() : new WP_Error( 'directorist_coupon_rate_limit', $error->getMessage(), array( 'status' => $error->getCode() ) );
            }
            $validation = $this->validate_coupon_from_request( $request );

            if ( is_wp_error( $validation ) ) {
                return new WP_Error( 'directorist_coupon_unavailable', 'Invalid or unavailable coupon code.', array( 'status' => 400 ) );
            }

            $rate_label = 'percent' === $validation['discount_type']
                ? sprintf( __( 'Discount ( %s%% )', 'directorist-coupon' ), $this->format_rate( $validation['discount_rate'] ) )
                : __( 'Discount', 'directorist-coupon' );

            return rest_ensure_response(
                array(
                    'coupon_code'             => $validation['coupon_code'],
                    'discount_rate'           => $validation['discount_rate'],
                    'discount_type'           => $validation['discount_type'],
                    'discount_amount'         => $validation['discount_amount'],
                    'discount_amount_display' => function_exists( 'directorist_price' ) ? directorist_price( $validation['discount_amount'] ) : number_format_i18n( $validation['discount_amount'], 2 ),
                    'discount_label'          => $rate_label,
                    'message'                 => __( 'Successfully applied!', 'directorist-coupon' ),
                )
            );
        }

        /**
         * Apply discount to new checkout system
         */
        public function swbdpc_apply_discount_new_checkout( OrderDTO $dto, $checkout_type, $request ) {
            $coupon_code = $request->get_param( 'directorist_order_coupon' );

            if ( empty( $coupon_code ) ) {
                return;
            }

            SWBDPCCouponService::lock();
            if ( $request->has_param( 'order_id' ) ) {
                throw new Exception( 'Apply coupons to a new checkout; existing order snapshots cannot be rewritten.', 400 );
            }

            $validation = $this->validate_coupon_from_request( $request, $checkout_type );

            if ( is_wp_error( $validation ) ) {
                throw new Exception( $validation->get_error_message(), 400 );
            }

            $dto->set_coupon_code( $validation['coupon_code'] );
            $dto->set_coupon_discount( (float) $validation['discount_rate'] );
            $dto->set_coupon_discount_type( $validation['discount_type'] );
        }

        /** Serialize final usage validation through insertion, retaining native all-status counts. */
        public function revalidate_new_order( $dto ) {
            if ( ! $dto instanceof OrderDTO || ! $dto->is_initialized( 'coupon_code' ) || ! $dto->get_coupon_code() ) { return; }
            SWBDPCCouponService::lock();
            if ( $dto->get_user_id() < 1 ) { throw new Exception( 'An authenticated buyer is required for coupons.', 400 ); }
            $plan_id = $dto->is_initialized( 'ref_type' ) && 'pricing_plan' === $dto->get_ref_type() ? (int) $dto->get_ref() : 0;
            $validation = SWBDPCHelperFunctions::validate_coupon( $dto->get_coupon_code(), $dto->get_sub_total(), $plan_id, $dto->get_user_id() );
            if ( is_wp_error( $validation ) ) { throw new Exception( $validation->get_error_message(), 400 ); }
            $dto->set_coupon_code( $validation['coupon_code'] )->set_coupon_discount( (float) $validation['discount_rate'] )->set_coupon_discount_type( $validation['discount_type'] );
        }

        /**
         * This function contain coupon code input field
         * 
         * @return void
         */
        public function swbdpc_coupon_code_input_field() {           
            $coup_in_field_file_path = SWBDPC_PLUGIN_TEMPLATES_DIR . 'coupon-input-field.php';
            
            if ( file_exists( $coup_in_field_file_path ) ) {
                // Require coupon input field template
                include $coup_in_field_file_path;
            }
        }

        private function validate_coupon_from_request( WP_REST_Request $request, $checkout_type = null ) {
            $checkout_type = $checkout_type ?: $request->get_param( 'checkout_type' );
            if ( $request->has_param( 'order_id' ) ) {
                return new WP_Error( 'directorist_coupon_context', 'Coupons require a new checkout context.' );
            }
            if ( 'plan' === $checkout_type ) {
                $plan = SWBDPCCouponService::plan( (int) $request->get_param( 'plan_id' ) );
                if ( is_wp_error( $plan ) ) { return $plan; }
                $listing_id = (int) $request->get_param( 'listing_id' );
                if ( $listing_id ) {
                    $listing = get_post( $listing_id );
                    if ( ! $listing || ATBDP_POST_TYPE !== $listing->post_type || (int) $listing->post_author !== get_current_user_id() || in_array( $listing->post_status, array( 'trash', 'auto-draft' ), true ) ) {
                        return new WP_Error( 'directorist_coupon_context', 'Checkout context is unavailable.' );
                    }
                }
                try {
                    do_action( 'directorist_checkout_validation', $checkout_type, $request );
                    $amount = $this->get_checkout_subtotal( $checkout_type, $request );
                } catch ( Exception $error ) {
                    return new WP_Error( 'directorist_coupon_context', 'Checkout context is unavailable.' );
                }
            } elseif ( 'featured_listing' === $checkout_type ) {
                $listing = get_post( (int) $request->get_param( 'listing_id' ) );
                if ( ! $listing || ATBDP_POST_TYPE !== $listing->post_type || (int) $listing->post_author !== get_current_user_id() || 'trash' === $listing->post_status ) {
                    return new WP_Error( 'directorist_coupon_context', 'Checkout context is unavailable.' );
                }
                $amount = $this->get_checkout_subtotal( $checkout_type, $request );
            } else {
                return new WP_Error( 'directorist_coupon_context', 'A supported server-calculated checkout context is required.' );
            }
            $plan_id       = $request->get_param( 'plan_id' );
            $coupon_code   = $request->get_param( 'directorist_order_coupon' );

            return SWBDPCHelperFunctions::validate_coupon( $coupon_code, $amount, $plan_id, get_current_user_id() );
        }

        private function get_checkout_subtotal( $checkout_type, WP_REST_Request $request ) {
            if ( empty( $checkout_type ) ) {
                return 0;
            }

            return round( apply_filters( 'directorist_checkout_subtotal', 0, $checkout_type, $request ), 2 );
        }

        private function format_rate( $rate ) {
            $rate = (float) $rate;

            if ( floor( $rate ) === $rate ) {
                return (string) (int) $rate;
            }

            return rtrim( rtrim( number_format_i18n( $rate, 2 ), '0' ), '.' );
        }
    }
}
