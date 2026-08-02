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
            $validation = $this->validate_coupon_from_request( $request );

            if ( is_wp_error( $validation ) ) {
                return $validation;
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

            $validation = $this->validate_coupon_from_request( $request, $checkout_type );

            if ( is_wp_error( $validation ) ) {
                throw new Exception( $validation->get_error_message(), 400 );
            }

            $dto->set_coupon_code( $validation['coupon_code'] );
            $dto->set_coupon_discount( (float) $validation['discount_rate'] );
            $dto->set_coupon_discount_type( $validation['discount_type'] );

            if ( $request->has_param( 'order_id' ) ) {
                $order = directorist_get_order_by_id( $request->get_param( 'order_id' ) );

                if ( $order ) {
                    $dto->set_id( $order->id );
                    $repository = directorist_order_repository();
                    $repository->silent_update( $dto );
                }
            }
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
            $amount        = $this->get_checkout_subtotal( $checkout_type, $request );
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