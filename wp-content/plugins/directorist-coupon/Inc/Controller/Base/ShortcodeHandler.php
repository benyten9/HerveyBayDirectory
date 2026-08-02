<?php
/**
 * @package  Directorist - Coupon
 */

if( ! class_exists( 'SWBDPCShortcodeHandler' ) ){
    /**
     * Coupon shortcode handler class
     */
    class SWBDPCShortcodeHandler
    {
        /**
        * Class constructor
        * Call all hooks which we wanna triger
        */
        public function register()
        {
            // Coupon's shortcode creator hook
            add_shortcode( 'directorist_coupons', array( $this, 'directorist_coupon_shortcode' ) );

            // Hook remover
            add_action( 'plugins_loaded', array( $this, 'swbdpc_hook_remover' ) );
        }


        /**
         * Create shortcode output
         *
         * @param $attr shortcode attributes, $content shortcode content
         *
         * @return shortcode content
         */
        public function directorist_coupon_shortcode( $atts, $content )
        {
            ob_start();

            extract(
                shortcode_atts(
                    array(
                        'id' => '',
                    ),
                    $atts
                )
            );
            $coup_ids = explode( ',', $id );
            $ids = !empty( $coup_ids[0] ) ? $coup_ids : array();
            $args = array(
                'post_type'           => SWBDPC_POST_TYPE,
                'post_status'         => 'publish',
                'posts_per_page'      => -1,
            );

            if( !empty( $ids ) ) {
                $args['post__in'] = $ids;
            }
            $coupons = new WP_Query( $args );
            foreach( $coupons->posts as $post ){
                $coupon_id = $post->ID;

                if( !empty( $coupon_id ) ){
                    $coup_code  = get_post_meta( $coupon_id, 'swbdpc_coupon_code', true );
                    $coup_des   = get_post_meta( $coupon_id, 'swbdpc_coupon_descrip', true );

                    $coup_code  = isset( $coup_code ) ? sanitize_text_field( $coup_code ) : '';
                    $coup_des   = isset( $coup_des ) ? sanitize_text_field( $coup_des ) : '';
                    ?>
                    <div class="directorist-coupon directorist-coupon--card">
                        <div class="directorist-coupon__thumb">
                            <?php echo get_the_post_thumbnail( $coupon_id ); ?>
                        </div>
                        <div class="directorist-coupon__content">
                            <div class="directorist-coupon__countdown"></div>
                            <!-- <span class="directorist-coupon__countdown__data">Jan 5, 2023 15:37:25</span> --> <!--Do not change text format -->
                            <h2 class="directorist-coupon__title"><?php echo get_the_title( $coupon_id ); ?></h2>
                            <div class="directorist-coupon__description"><p><?php echo $coup_des; ?></p></div>
                            <div class="directorist-coupon__code">
                                <div class="directorist-coupon__code-input">
                                    <input type="text" value="<?php echo $coup_code; ?>" class="directorist-coupon__code__text">
                                    <button class="directorist-coupon__code__copy-btn"><?php _e( 'Copy', 'directorist-coupon' )?></button>
                                </div>
                                <span class="directorist-coupon__code__copy-notice"><?php _e( 'Coupon copied!', 'directorist-coupon' )?></span>
                            </div>
                        </div>
                    </div><!-- ends: .directorist-coupon -->
                    <?php
                }
            }
            $content = ob_get_contents();
            ob_get_clean();

            return $content;
        }


        /**
         * Remove hooks when Ads Manager is disable
         */
        public function swbdpc_hook_remover()
        {
            // Get Coupon enable option
            $is_coupon_enable = get_directorist_option( 'swbdp_coupon_is_enable', 1 );

            // Authenticate, is Coupon enable or not
            if( 1 !== ( int )$is_coupon_enable ){
                remove_shortcode( 'directorist_coupons' );
            }
        }

    }// End class
}