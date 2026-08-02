<?php
/**
 * @package  Directorist - Coupon
 */

if ( ! class_exists( 'SWBDPCEnqueue' ) ) {
    /**
    * All style & script files enqueue handler class
    */
    class SWBDPCEnqueue {
        /**
         * Class constructor
         * Call all hooks which we wanna triger
         */
        public function register() {
            // Admin enqueue hook
            add_action( 'admin_enqueue_scripts', array( $this, 'swbdpc_admin_enqueue_script' ) );
            // Frontend enqueue hook
            add_action( 'wp_enqueue_scripts', array( $this, 'swbdpc_frontend_enqueue_script' ) );

            // Hook remover
            add_action( 'plugins_loaded', array( $this, 'swbdpc_hook_remover' ) );
        }

        /**
         * Register & enqueue all admin scripts & styles
         *
         * @return void
         */
        public function swbdpc_admin_enqueue_script( $page ) {
            // Register admin style
            wp_register_style( 'swbdpc-admin-css', SWBDPC_ADMIN_CSS.'swbdpc-admin.css', [], time() );
            
            // Register admin script
            wp_register_script( 'swbdpc-admin-js', SWBDPC_ADMIN_JS.'swbdpc-admin.js', [], true );            
            
            $screen = get_current_screen();
            
            if ( ( SWBDPC_POST_TYPE == $screen->post_type ) || ( 'at_biz_dir_page_aazztech_settings' === $page ) ) {
                // Enqueue admin style
                wp_enqueue_style( 'swbdpc-admin-css' );
                // Enqueue admin script
                wp_enqueue_script( 'swbdpc-admin-js' );

                wp_localize_script( 'swbdpc-admin-js', 'swbdpc_data', [ 'ajaxurl' => admin_url('admin-ajax.php') ] );
            }
        }
         
        /**
         * Register & enqueue all frontend scripts & styles
         *
         * @return void
         */
        public function swbdpc_frontend_enqueue_script() {
            // Register frontend style
            wp_register_style( 'swbdpc-frontend-css', SWBDPC_FRONTEND_CSS .'swbdpc-frontend.css', [], time() );
            
            // Register frontend ajax
            wp_register_script( 'swbdpc-frontend-ajax', SWBDPC_FRONTEND_JS . 'swbdpc-frontend-ajax.js', array( 'jquery') );

            // Enqueue frontend style
            wp_enqueue_style( 'swbdpc-frontend-css' );
            
            // Enqueue frontend ajax
            wp_enqueue_script( 'swbdpc-frontend-ajax' );

            // Make js variable
            wp_localize_script(
                // Which file we wanna send the value or variable thats file handle
                'swbdpc-frontend-ajax',     // File handle
                // This object name is similar to, Which file we wanna send the value
                'swbdpc_frontend_ajax_object',     // Object name
                array(
                    'ajaxurl'   => admin_url('admin-ajax.php'),
                    'rest_url'  => esc_url_raw( rest_url( 'directorist-coupon/v1' ) ),
                    'rest_nonce'=> wp_create_nonce( 'wp_rest' ),
                )
            );            
        }

        /**
         * Remove hooks when Coupon is disable
         */
        public function swbdpc_hook_remover() {
            // Get Coupon enable option
            $is_coupon_enable = get_directorist_option( 'swbdp_coupon_is_enable', 1 );

            // Authenticate, is Coupon enable or not
            if ( 1 !== ( int ) $is_coupon_enable ) {
                remove_action( 'admin_enqueue_scripts', array( $this, 'swbdpc_admin_enqueue_script' ) );
                remove_action( 'wp_enqueue_scripts', array( $this, 'swbdpc_frontend_enqueue_script' ) );
            }
        }
    }
}