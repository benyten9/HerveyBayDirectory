<?php
/**
 * Plugin Name:       Directorist - Coupon
 * Plugin URI:        https://wpwax.com
 * Description:       Allow discounts and promotional deals with an easy-to-use coupon system.
 * Version:           2.5.0
 * Requires at least: 5.2
 * Author:            wpWax
 * Author URI:        https://wpwax.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       directorist-coupon
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'swbdpc_get_version_from_file_content' ) ) {
    function swbdpc_get_version_from_file_content( $file_path = '' ) {
        $version = '';

        if ( ! file_exists( $file_path ) ) {
            return $version;
        }

        $content = file_get_contents( $file_path );

        if ( preg_match( '/\*[\s\t]+?version:[\s\t]+?([^\s]+)/i', $content, $v ) ) {
            $version = $v[1];
        }

        return $version;
    }
}

if ( ! function_exists( 'swbdpc_define_plugin_constants' ) ) {
    function swbdpc_define_plugin_constants() {
        $constants = [
            'SWBDPC_PLUGIN_VERSION'       => swbdpc_get_version_from_file_content( __FILE__ ),
            'SWBDPC_POST_TYPE'            => 'swbdp-coupon',
            'SWBDPC_BASE_FILE'            => __FILE__,
            'SWBDPC_PLUGIN_DIR_PATH'      => dirname( __FILE__ ) . '/',
            'SWBDPC_PLUGIN_DIR_URL'       => plugin_dir_url( __FILE__ ),
            'SWBDPC_PLUGIN_DIRNAME'       => dirname( plugin_basename( __FILE__ ) ),
            'SWBDPC_PLUGIN_TEMPLATES_DIR' => dirname( __FILE__ ) . '/Inc/View/',
        ];

        foreach ( $constants as $constant => $value ) {
            if ( ! defined( $constant ) ) {
                define( $constant, $value );
            }
        }

        $url_constants = [
            'SWBDPC_ADMIN_CSS'    => SWBDPC_PLUGIN_DIR_URL . 'assets/admin/css/',
            'SWBDPC_ADMIN_JS'     => SWBDPC_PLUGIN_DIR_URL . 'assets/admin/js/',
            'SWBDPC_FRONTEND_CSS' => SWBDPC_PLUGIN_DIR_URL . 'assets/frontend/css/',
            'SWBDPC_FRONTEND_JS'  => SWBDPC_PLUGIN_DIR_URL . 'assets/frontend/js/',
        ];

        foreach ( $url_constants as $constant => $value ) {
            if ( ! defined( $constant ) ) {
                define( $constant, $value );
            }
        }

        if ( ! defined( 'ATBDP_AUTHOR_URL' ) ) {
            define( 'ATBDP_AUTHOR_URL', 'https://directorist.com' );
        }

        if ( ! defined( 'ATBDP_COUPON_POST_ID' ) ) {
            define( 'ATBDP_COUPON_POST_ID', 32345 );
        }
    }
}

swbdpc_define_plugin_constants();

if ( ! class_exists( 'SWBDPCoupon' ) ) {
    /**
     * Plugin's main class
     */
    final class SWBDPCoupon {
        private static $instance;

        /**
         * Class constructor
         * 
         * @return object SWBDPCoupon 
         */
        public static function instance() {
            // Authenticate, is instance created or not
            if ( ! isset( self::$instance ) && ! ( self::$instance instanceof SWBDPCoupon ) ){
                self::$instance = new SWBDPCoupon();
                self::$instance->define_constant();
                self::$instance->instance_plugin_classes();

				add_action( 'admin_init', [ self::$instance, 'update_controller' ] );
            }

            return self::$instance;
        }
		
		public function update_controller() {
            $data = get_user_meta( get_current_user_id(), '_plugins_available_in_subscriptions', true );
            $license_key = ! empty( $data['directorist-coupon'] ) ? $data['directorist-coupon']['license'] : '';
			
			// setup the updater
            new EDD_SL_Plugin_Updater( ATBDP_AUTHOR_URL, __FILE__, [
                'version'   => SWBDPC_PLUGIN_VERSION, // current version number
                'license'   => $license_key, // license key ( used get_option above to retrieve from DB )
                'item_id'   => ATBDP_COUPON_POST_ID, // id of this plugin
                'author'    => 'wpWax', // author of this plugin
                'url'       => home_url(),
                'beta'      => false // set to true if you wish customers to receive update notifications of beta releases
            ] );
        }

        public static function get_version_from_file_content( $file_path = '' ) {
            return swbdpc_get_version_from_file_content( $file_path );
        }

        public static function get_version_from_content( $content = '' ) {
            $version = '';
    
            if ( preg_match('/\*[\s\t]+?version:[\s\t]+?([^\s]+)/i', $content, $v) ) {
                $version = $v[1];
            }
    
            return $version;
        }
        
        /**
        * Define the required plugin constant
        *
        * @return void
        */
        public function define_constant() {
            swbdpc_define_plugin_constants();
        }

        /**
         * Iintialize all other classes of this plugin
         *
         * @return void
         */
        public function instance_plugin_classes() {
            if ( ! class_exists('EDD_SL_Plugin_Updater') ) {
                // load our custom updater if it doesn't already exist
                include( dirname(__FILE__) . '/Inc/Controller/Admin/EDD_SL_Plugin_Updater.php' );
            }

            if ( file_exists( SWBDPC_PLUGIN_DIR_PATH . 'includes.php' ) ) {
                // Require all class's files
                require_once( SWBDPC_PLUGIN_DIR_PATH . 'includes.php' );
                
                SWBDPCIncludeClasses::includes();
            
                $classes_name = [
                    'SWBDPCEnqueue',
                    'SWBDPCActivate',
                    'SWBDPCouponCPT',
                    'SWBDPCouponHandler',
                    'SWBDPCDynamicStyle',
                    'SWBDPCHelperFunctions',
                    'SWBDPCShortcodeHandler',
                    'SWBDPCExtensionSettings',
                    'SWBDPCCustomWpListTable',
                ];

                foreach( $classes_name as $class_name ){
                    if( method_exists( $class_name, 'register' ) ){
                        $object = new $class_name;
                        $object->register();
                    }                
                }
            }    
        }
    }

    /**
     * Initializes the main plugin
     *
     * @return \SWBDPCoupon class
     */
    function SWBDPCoupon() {
        return SWBDPCoupon::instance();        
    }

    /**
     * Handle plugin activation
     *
     * @return void
     */
    function swbdp_coupon_plugin_activate() {
        $core_version_requirment = directorist_coupon_core_version_requirment();
        $current_core_version    = $core_version_requirment['current_version'];
        $required_core_version   = $core_version_requirment['required_version'];
                
        if ( ! directorist_coupon_is_compaitable( $current_core_version, $required_core_version ) ) {
            return;
        }

        // Get Directorist - Coupon plugin activation time
        $installed = get_option( 'swbdp_coupon_installed' );
        
        if ( ! $installed ) {
            update_option( 'swbdp_coupon_installed', time() );
        }

        // Store current version of this plugin.
        update_option( 'swbdp_coupon_version', SWBDPC_PLUGIN_VERSION );
    }

    register_activation_hook( __FILE__, 'swbdp_coupon_plugin_activate' );

    function directorist_coupon_incompatibility_notice( string $required_plugin, string $required_version, string $current_version ) {
        add_action(
                'admin_notices', function () use ( $required_plugin, $required_version, $current_version ): void {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        wp_kses_post(
                            sprintf(
                                /* translators: 1: required plugin, 2: required version, 3: current version */
                                __( '<strong>Directorist - Coupon</strong> requires %1$s version <strong>%2$s</strong> or higher. You are running version <strong>%3$s</strong>. Please update %1$s to use this plugin.', 'directorist-coupon' ),
                                esc_html( $required_plugin ),
                                esc_html( $required_version ),
                                esc_html( $current_version ),
                            )
                        )
                    );
                }
            );
    }

    function directorist_coupon_core_version_requirment() {
        return [
            'required_version' => '8.8.0',
            'current_version'  => defined( 'ATBDP_VERSION' ) ? ATBDP_VERSION : '0',
        ];
    }

    function directorist_coupon_is_compaitable( $current_version, $required_version ) {
        return version_compare( $current_version, $required_version, '<' ) ? false : true;
    }

    add_action( 'plugins_loaded', function() {
        $core_version_requirment = directorist_coupon_core_version_requirment();
        $current_core_version    = $core_version_requirment['current_version'];
        $required_core_version   = $core_version_requirment['required_version'];
                
        if ( ! directorist_coupon_is_compaitable( $current_core_version, $required_core_version ) ) {
            directorist_coupon_incompatibility_notice( 'Directorist', $required_core_version, $current_core_version );
        }

        SWBDPCoupon();
    } );  
}
