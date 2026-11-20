<?php
/**
 * @package  Directorist - Coupon
 */


if ( ! class_exists( 'SWBDPCouponCPT' ) ) {
    /**
     * Coupon custom post type handler class
     */
    class SWBDPCouponCPT {        
        public $custom_meta_field;

        /**
         * Class constructor
         * Call all hooks which we wanna triger
         */
        public function register() {
            // Get Custom meta box's callback functions 
            $this->custom_meta_field = new SWBDPCAdminCallbacks();

            // Coupon CPT register hook
            add_action( 'init', array( $this, 'register_swbdp_coupon_custom_post_type' ) );
            // Coupon custom metabox
            add_action( 'admin_menu', array( $this, 'add_swbdp_coupon_cpt_custom_metabox'), 999 );
            // This hook change the CPT Title placeholder
            add_filter( 'enter_title_here', array( $this, 'change_swbdp_coupon_cpt_title_placeholder') );

            // For save custom meta box data
            add_action( 'save_post', array( $this, 'save_cmb_swbdpc_coupon_details_data' ) );
            
            // Hook remover
            add_action( 'plugins_loaded', array( $this, 'swbdpc_hook_remover' ) );
        }


        /**
         * Coupon CPT register function
         * 
         * @return void
         */
        public static function register_swbdp_coupon_custom_post_type() {
            // Coupon CPT's labeles
            $labels = array(
                'name'                  => _x( 'Coupons', 'directorist-coupon' ),
                'singular_name'         => _x( 'Coupon', 'directorist-coupon' ),
                'menu_name'             => _x( 'Coupons', 'directorist-coupon' ),
                'name_admin_bar'        => _x( 'Coupon', 'directorist-coupon' ),
                'add_new'               => __( 'Add New Coupon', 'directorist-coupon' ),
                'add_new_item'          => __( 'Add New Coupon', 'directorist-coupon' ),
                'new_item'              => __( 'New Coupon', 'directorist-coupon' ),
                'edit_item'             => __( 'Edit Coupon', 'directorist-coupon' ),
                'view_item'             => __( 'View Coupon', 'directorist-coupon' ),
                'all_items'             => __( 'Coupons', 'directorist-coupon' ),
                'search_items'          => __( 'Search Coupon', 'directorist-coupon' ),
                'parent_item_colon'     => __( 'Parent Coupon:', 'directorist-coupon' ),
                'not_found'             => __( 'No Coupon found.', 'directorist-coupon' ),
                'not_found_in_trash'    => __( 'No Coupon found in Trash.', 'directorist-coupon' ),
                'featured_image'        => _x( 'Coupon Cover Image', 'directorist-coupon' ),
                'set_featured_image'    => _x( 'Set cover image', 'directorist-coupon' ),
                'remove_featured_image' => _x( 'Remove cover image', 'directorist-coupon' ),
                'use_featured_image'    => _x( 'Use as cover image', 'directorist-coupon' ),
                'insert_into_item'      => _x( 'Insert into coupon', 'directorist-coupon' ),                
            );
        
            // Coupon CPT's arguments
            $args = array(
                'labels'                => $labels,
                'public'                => true,
                'publicly_queryable'    => true,
                'show_ui'               => true,
                'show_in_menu'          => 'edit.php?post_type='. ATBDP_POST_TYPE,
                'query_var'             => true,
                'rewrite'               => array( 'slug' => SWBDPC_POST_TYPE ),
                'capability_type'       => 'post',
                'has_archive'           => true,
                'hierarchical'          => false,
                'supports'              => array( 'title', 'author', 'thumbnail' ),
            );
         
            // Register Coupon CPT
            register_post_type( SWBDPC_POST_TYPE, $args );
        }

        /**
         * Custom meta box for Coupon post type
         *
         * @return void
         */
        public function add_swbdp_coupon_cpt_custom_metabox() {
            // Select Ad type metabox
        	add_meta_box(
        	    'swbdpc-coupon-details',                                                // Section id
                __( 'Coupon Details', 'directorist-coupon' ),                           // Section name
                array( $this->custom_meta_field, 'cmb_swbdpccpt_coupon_details_callback' ),  // Callback
                array( SWBDPC_POST_TYPE ),                                          // Where show Section
                'normal',                                                           // For position
                'high'                                                              // For position                
            );
        }



        /**
         * Ad title's placeholder changer function
         */
        public function change_swbdp_coupon_cpt_title_placeholder( $placeholder ) {
            // Authenticate, is admin or not
            if( is_admin() ){
                $screen = get_current_screen();

                if( SWBDPC_POST_TYPE == $screen->post_type ) {
                    $placeholder = __( 'Enter coupon title', 'directorist-coupon' );
                }
                return $placeholder;
            }
        }

        /**
         * For save, coupon details custom meta box data
         * 
         * @return void
         */
        public function save_cmb_swbdpc_coupon_details_data( $post_id ) {
            if ( ! SWBDPCHelperFunctions::is_secured( 'swbdpc_coupon_details_cmb_field','swbdpc_coupon_details_cmb_action', $post_id ) ) {
                return;
            }

            if ( SWBDPC_POST_TYPE !== get_post_type( $post_id ) ) {
                return;
            }
            $result = SWBDPCCouponService::save( $post_id, SWBDPCCouponService::submitted() );
            if ( is_wp_error( $result ) ) {
                wp_die( esc_html( $result->get_error_message() ), 'Invalid coupon', [ 'response' => 400, 'back_link' => true ] );
            }
        }

        /**
         * Hook remover function
         */
        public function swbdpc_hook_remover() {
            // Get Coupon enable option
            $is_ads_manager_enable = get_directorist_option( 'swbdp_coupon_is_enable', 1 );

            // Authenticate, is Coupon enable or not
            if ( 1 !== (int) $is_ads_manager_enable ) {
                remove_action( 'init', array( $this, 'register_swbdp_coupon_custom_post_type' ) );
                remove_action( 'admin_menu', array( $this, 'add_swbdp_coupon_cpt_custom_metabox'), 999 );
                remove_filter( 'enter_title_here', array( $this, 'change_swbdp_coupon_cpt_title_placeholder') );

                remove_action( 'save_post', array( $this, 'save_cmb_swbdpc_coupon_details_data' ) );
            }
        }

    }// End class
}