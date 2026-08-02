<?php
/**
 * @package  Directorist - Coupon
 */


if( ! class_exists( 'SWBDPCCustomWpListTable' ) ){
    /**
     * WP List Table customizer class
     */
    class SWBDPCCustomWpListTable
    {
        /**
         * Class constructor
         * Call all hooks which we wanna triger
         */
        public function register()
        {
            // Custom columns handler hook
            add_filter( 'manage_'.SWBDPC_POST_TYPE.'_posts_columns',  array( $this, 'swbdpc_add_custom_column' ) );
            // Custom columns data handler hook
            add_action( 'manage_posts_custom_column', array( $this, 'swbdpc_display_custom_column_data' ) );

            // Hook remover
            add_action( 'plugins_loaded', array( $this, 'swbdpc_hook_remover' ) );
        }



        /**
         * Add new columns to the post table
         * 
         * @param Array $columns - Current columns on the list post
         * 
         * @return all columns
         */
        public function swbdpc_add_custom_column( $columns )
        {
            $column_name = array( 
                'swbdpc_coupon_code'       => __( 'Coupon Code', 'directorist-coupon' ),
                'swbdpc_discount_amount'   => __( 'Discount Amount', 'directorist-coupon' ),
                'swbdpc_coupon_type'       => __( 'Coupon Type', 'directorist-coupon' ),
                'swbdpc_usage_limit'       => __( 'Usage Limit Per User', 'directorist-coupon' ),
                //'swbdpc_user_limit'        => __( 'User Limit', 'directorist-coupon' ),
                'swbdpc_expire_on'         => __( 'Expire on', 'directorist-coupon' ),
            );

            // Add new columns
            $columns = array_slice( $columns, 0, 2, true ) + $column_name + array_slice( $columns, 2, NULL, true );
            // Remove author column
            unset( $columns['author'] );

            return $columns;        
        }



        /**
         * Display the column data in added new column
         * 
         * @param  $column added column
         * 
         * @return void
         */
        public function swbdpc_display_custom_column_data( $column )
        {        
            global $post;

            switch ( $column ){
                // Show Coupon Code data in Coupon code column
                case 'swbdpc_coupon_code':
                    $coupon_code = get_post_meta( $post->ID, 'swbdpc_coupon_code', true );

                    if( isset( $coupon_code ) && !empty( $coupon_code ) ){
                        echo $coupon_code;
                    }else{
                        _e( 'Empty !', 'directorist-coupon' );
                    }

                    break;
                // Show Discount Amount data in Discount Amount column
                case 'swbdpc_discount_amount':
                    $coupon_amount = get_post_meta( $post->ID, 'swbdpc_coupon_amount', true );

                    if( isset( $coupon_amount ) && !empty( $coupon_amount ) ){
                        echo $coupon_amount;
                    }else{
                        _e( 'Empty !', 'directorist-coupon' );
                    }

                    break;    
                // Show Coupon Type data in Coupon Type column                        
                case 'swbdpc_coupon_type':
                    $coupon_type = get_post_meta( $post->ID, 'swbdpc_coupon_type', true );
                    if( isset( $coupon_type ) && !empty( $coupon_type ) ){
                        $str_replaced = str_replace( '_', ' ', $coupon_type );
                        echo ucwords( $str_replaced );
                    }else{
                        _e( 'Empty !', 'directorist-coupon' );
                    }

                    break;    
                // Show Usage Limit data in Usage Limit column
                case 'swbdpc_usage_limit':
                    $usage_limit = get_post_meta( $post->ID, 'swbdpc_coupon_usage_limit', true );

                    if( isset( $usage_limit ) && !empty( $usage_limit ) ){
                        echo $usage_limit;
                    }else{
                        _e( 'Empty !', 'directorist-coupon' );
                    }

                    break;
                // Show User Limit data in User Limit column
            /*    case 'swbdpc_user_limit':
                    $user_limit = get_post_meta( $post->ID, 'swbdpc_coupon_user_limit', true );

                    if( isset( $user_limit ) && !empty( $user_limit ) ){
                        echo $user_limit;
                    }else{
                        _e( 'Empty !', 'directorist-coupon' );
                    }

                    break;
            */    // Show Expire on data in Expire on column
                case 'swbdpc_expire_on':
                    $coupon_expiry = get_post_meta( $post->ID, 'swbdpc_coupon_expiry', true );

                    if( isset( $coupon_expiry ) && !empty( $coupon_expiry ) ){
                        echo $coupon_expiry;
                    }else{
                        _e( 'Empty !', 'directorist-coupon' );
                    }

                    break;
            }
        }



        /**
         * Remove hooks when Coupon is disable
         */
        public function swbdpc_hook_remover()
        {
            // Get Coupon enable option
            $is_coupon_enable = get_directorist_option( 'swbdp_coupon_is_enable', 1 );

            // Authenticate, is Coupon enable or not
            if( 1 !== ( int )$is_coupon_enable ){
                remove_filter( 'manage_'.SWBDPC_POST_TYPE.'_posts_columns',  array( $this, 'swbdpc_add_custom_column' ) );
                remove_action( 'manage_posts_custom_column', array( $this, 'swbdpc_display_custom_column_data' ) );
            }
        }


    } // Class closer
} // If statement closer