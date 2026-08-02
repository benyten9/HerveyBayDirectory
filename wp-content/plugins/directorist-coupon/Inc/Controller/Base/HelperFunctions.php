<?php
/**
 * @package  Directorist - Coupon
 */

use Directorist\Enums\Order\DiscountType;

if ( ! class_exists( 'SWBDPCHelperFunctions' ) ) {
    /**
     * All helper functions handler class
     */
    class SWBDPCHelperFunctions {
        /**
         * Verify valid input fields or not
         * 
         * @return bool
         */
        public static function is_secured( $nonce_field, $nonce_action, $post_id ) {
            $nonce = isset( $_POST[$nonce_field] ) ? $_POST[$nonce_field] : '';

            if( '' == $nonce ){
                return false;
            }

            if( ! wp_verify_nonce( $nonce, $nonce_action ) ){
                return false;
            }

            if( ! current_user_can( 'edit_post', $post_id ) ){
                return false;
            }

            if( wp_is_post_autosave( $post_id ) ){
                return false;
            }

            if( wp_is_post_revision( $post_id ) ){
                return false;
            }

            return true;
        }

        /**
         * Calculate coupon amount.
         */
        public static function get_coupon_discount( $coupon_id, $checkout_total, $plan_id = NULL ) {
            $discount = 0; // Initialize discount to avoid undefined behavior
            $coupon_rate = (float) get_post_meta( $coupon_id, 'swbdpc_coupon_amount', true );
            $coupon_type = get_post_meta( $coupon_id, 'swbdpc_coupon_type', true );
            $checkout_total = (float) $checkout_total; // Use float to handle precise calculations

            if ( 'percentage_dis' === $coupon_type ) {
                $discount = $checkout_total > 0 ? ( $coupon_rate * $checkout_total ) / 100 : 0;
            } elseif ( 'fixed_cart_dis' === $coupon_type ) {
                $discount = $checkout_total > 0 ? min( $coupon_rate, $checkout_total ) : 0; // Ensure discount doesn't exceed total
            } elseif ( 'fixed_product_dis' === $coupon_type && ! empty( $plan_id ) ) {
                $discount = $checkout_total > 0 ? min( $coupon_rate, $checkout_total ) : 0; // Ensure discount doesn't exceed total
            }

            return $discount;
        }

        public static function find_coupon_by_code( $coupon_code ) {
            $coupon_code = sanitize_text_field( wp_unslash( $coupon_code ) );

            if ( '' === $coupon_code ) {
                return null;
            }

            $coupon_query = new WP_Query(
                array(
                    'post_type'      => SWBDPC_POST_TYPE,
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => 'swbdpc_coupon_code',
                            'value'   => $coupon_code,
                            'compare' => '=',
                        ),
                    ),
                )
            );

            $coupon_id = ! empty( $coupon_query->posts[0] ) ? (int) $coupon_query->posts[0] : 0;
            wp_reset_postdata();

            return $coupon_id ?: null;
        }

        public static function validate_coupon( $coupon_code, $amount, $plan_id = 0, $user_id = 0 ) {
            $coupon_code = sanitize_text_field( wp_unslash( $coupon_code ) );
            $amount      = (float) $amount;
            $plan_id     = absint( $plan_id );
            $user_id     = absint( $user_id );

            if ( '' === $coupon_code ) {
                return new WP_Error( 'directorist_coupon_empty', __( 'Please enter coupon code!', 'directorist-coupon' ), array( 'status' => 400 ) );
            }

            if ( $amount <= 0 ) {
                return new WP_Error( 'directorist_coupon_invalid_amount', __( 'Invalid checkout amount.', 'directorist-coupon' ), array( 'status' => 400 ) );
            }

            $coupon_id = self::find_coupon_by_code( $coupon_code );

            if ( ! $coupon_id ) {
                return new WP_Error( 'directorist_coupon_invalid', __( 'Invalid coupon code!', 'directorist-coupon' ), array( 'status' => 404 ) );
            }

            $expiry = get_post_meta( $coupon_id, 'swbdpc_coupon_expiry', true );

            if ( ! empty( $expiry ) && $expiry < current_time( 'Y-m-d' ) ) {
                return new WP_Error( 'directorist_coupon_expired', __( 'Expired coupon!', 'directorist-coupon' ), array( 'status' => 400 ) );
            }

            $coupon_rate = (float) get_post_meta( $coupon_id, 'swbdpc_coupon_amount', true );
            $coupon_type = get_post_meta( $coupon_id, 'swbdpc_coupon_type', true );

            if ( $coupon_rate <= 0 ) {
                return new WP_Error( 'directorist_coupon_invalid_discount', __( 'Invalid coupon discount amount.', 'directorist-coupon' ), array( 'status' => 400 ) );
            }

            $discount_type = null;
            $discount_rate = $coupon_rate;
            $discount      = null;

            if ( 'percentage_dis' === $coupon_type ) {
                $discount_type = DiscountType::PERCENT;
                $discount      = ( $coupon_rate * $amount ) / 100;
            } elseif ( 'fixed_cart_dis' === $coupon_type ) {
                $discount_type = DiscountType::FLAT;
                $discount      = $coupon_rate;
            } elseif ( 'fixed_product_dis' === $coupon_type ) {
                $fixed_products = get_post_meta( $coupon_id, 'swbdpc_fixed_products', true );
                $fixed_products = self::normalize_plan_ids( $fixed_products );

                if ( ! $plan_id || ! in_array( $plan_id, $fixed_products, true ) ) {
                    return new WP_Error( 'directorist_coupon_plan_invalid', __( 'This coupon is not applicable for this plan!', 'directorist-coupon' ), array( 'status' => 400 ) );
                }

                $discount_type = DiscountType::FLAT;
                $discount      = $coupon_rate;
            }

            if ( null === $discount || null === $discount_type ) {
                return new WP_Error( 'directorist_coupon_invalid_discount', __( 'Invalid coupon discount amount.', 'directorist-coupon' ), array( 'status' => 400 ) );
            }

            $usage_limit = (int) get_post_meta( $coupon_id, 'swbdpc_coupon_usage_limit', true );

            if ( $usage_limit > 0 && $user_id > 0 && self::get_user_coupon_usage_count( $coupon_code, $user_id ) >= $usage_limit ) {
                return new WP_Error( 'directorist_coupon_usage_limit', __( 'Coupon usage limit over!', 'directorist-coupon' ), array( 'status' => 400 ) );
            }

            return array(
                'coupon_id'       => $coupon_id,
                'coupon_code'     => get_post_meta( $coupon_id, 'swbdpc_coupon_code', true ),
                'discount_rate'   => $discount_rate,
                'discount_type'   => $discount_type,
                'discount_amount' => round( min( $discount, $amount ), 2 ),
            );
        }

        public static function normalize_plan_ids( $plans ) {
            if ( empty( $plans ) ) {
                return array();
            }

            if ( ! is_array( $plans ) ) {
                $plans = array( $plans );
            }

            $plan_ids = array();

            foreach ( $plans as $plan ) {
                if ( is_numeric( $plan ) ) {
                    $plan_ids[] = absint( $plan );
                    continue;
                }

                $resolved_id = self::resolve_plan_title_to_id( $plan );

                if ( $resolved_id ) {
                    $plan_ids[] = $resolved_id;
                }
            }

            return array_values( array_unique( array_filter( $plan_ids ) ) );
        }

        public static function resolve_plan_title_to_id( $title ) {
            $title = sanitize_text_field( $title );

            if ( '' === $title ) {
                return 0;
            }

            if ( function_exists( 'directorist_pricing_plans_singleton' ) && class_exists( 'DirectoristPricingPlan\App\Repositories\Admin\PlanRepository' ) ) {
                $repository = directorist_pricing_plans_singleton( 'DirectoristPricingPlan\App\Repositories\Admin\PlanRepository' );
                $plans      = $repository->get_query_builder()->where( 'plan.title', $title )->get();

                if ( is_array( $plans ) && 1 === count( $plans ) && ! empty( $plans[0]->id ) ) {
                    return absint( $plans[0]->id );
                }
            }

            return 0;
        }

        public static function get_user_coupon_usage_count( $coupon_code, $user_id ) {
            global $wpdb;

            $table_name = $wpdb->prefix . 'directorist_orders';

            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND coupon_code = %s",
                    absint( $user_id ),
                    sanitize_text_field( $coupon_code )
                )
            );
        }
    }
}