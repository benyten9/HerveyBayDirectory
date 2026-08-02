<?php
/**
 * @package Directorist Claim Listing
 * */
if (!class_exists('DCL_Enqueuer')):
class DCL_Enqueuer {


    public function __construct() {
        // best hook to enqueue scripts for front-end is 'template_redirect'
        // 'Professional WordPress Plugin Development' by Brad Williams
        add_action( 'wp_enqueue_scripts', array( $this, 'front_end_enqueue_scripts' ) );

    }

    /**
     * It loads all scripts for front end if the current post type is our custom post type
     * @param bool $force [optional] whether to load the style in the front end forcibly(even if the post type is not our custom post). It is needed for enqueueing file from a inside the short code call
     */
    public function front_end_enqueue_scripts($force=false) {
        global $typenow, $post;
        // enqueue the style and the scripts on the page when the post type is our registered post type.
        if ( (is_object($post) && 'at_biz_dir' == $post->post_type) || $force) {
                //wp_register_script('owl_carousel', DCL_ASSETS . 'js/owl.carousel.min.js', array('jquery'), DCL_VERSION, true);
                //wp_register_style('owl_carousel_style', DCL_ASSETS . 'css/owl.carousel.css', false, DCL_VERSION);
                wp_register_script('dcl_main_js', DCL_ASSETS . 'js/main.js', array('jquery'), DCL_VERSION, true);


                //wp_enqueue_style('owl_carousel_style');
                //wp_enqueue_script('owl_carousel');
                wp_enqueue_script('dcl_main_js');
                wp_enqueue_style('dcl_main_css');
                $data = array(
                    'ajaxurl'           => admin_url('admin-ajax.php'),
                    'nonce'             => wp_create_nonce( 'directorist_claim_nonce' ),
                );
                wp_localize_script( 'dcl_main_js', 'dcl_main', $data );

        }
    }
}



endif;