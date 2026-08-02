<?php
/**
 * @package  Directorist - Coupon
 */


if( ! class_exists( 'SWBDPCActivate' ) ){
    /**
     * Plugin active & initial loaded handler class
     */
    class SWBDPCActivate
    {
        /**
        * Class constructor
        * Call all hooks which we wanna triger
        */
        public function register()
        {
            // Textdomain loded hook
            add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );

            // Hook remover
            add_action( 'plugins_loaded', array( $this, 'swbdpc_hook_remover' ) );
        }


        /**
         * Loaded plugin textdomain.
         */
        public function load_plugin_textdomain() 
        {
            // Textdomain loded method
            load_plugin_textdomain( 'directorist-coupon', false, SWBDPC_PLUGIN_DIRNAME. '/languages' );
        }


        /**
         * Hook remover function
         */
        public function swbdpc_hook_remover()
        {
            // Get Coupon enable option
            $is_ads_manager_enable = get_directorist_option( 'swbdp_coupon_is_enable', 1 );
            // Authenticate, is Coupon enable or not
            if( 1 !== (int)$is_ads_manager_enable ){
                remove_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
            }
        }

    }// End class
}