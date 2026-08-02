<?php
/**
 * @package  Directorist - Coupon
 */


if( ! class_exists( 'SWBDPCAdminCallbacks' ) ){
    /**
     * Ads Manager custom post type handler class
     */
    class SWBDPCAdminCallbacks {
        /**
         * SWBDPC_POST_TYPE CPT's coupon details CMB's callback
         * 
         * @return void
         */
        public static function cmb_swbdpccpt_coupon_details_callback( $post ) {
            $l_coup_des             = __( 'Coupon Description', 'directorist-coupon' );
            $l_coup_code            = __( 'Coupon Code', 'directorist-coupon' );
            $l_gen_coup_code        = __( 'Generate coupon code', 'directorist-coupon' );
            $l_coup_type            = __( 'Select Coupon Type', 'directorist-coupon' );
            $l_select_products      = __( 'Select Products', 'directorist-coupon' );
            $l_coup_amount          = __( 'Coupon Amount', 'directorist-coupon' );
            $l_coup_exp_date        = __( 'Expiry Date', 'directorist-coupon' );
            $l_usage_limit          = __( 'Usage Limit Per User', 'directorist-coupon' );
            $l_user_limit           = __( 'User Limit', 'directorist-coupon' );
            $l_coup_template        = __( 'Select Coupon Template', 'directorist-coupon' );
            $placeho_enter_code     = __( 'Enter coupon code', 'directorist-coupon' );
            $placeho_enter_amount   = __( 'e.g. 10', 'directorist-coupon' );
            $placeho_usage_limit    = __( 'e.g. 1', 'directorist-coupon' );

            $pricing_plan_extnsions = "It requires <a class='swbdp__product_link' target='_blank' href='https://directorist.com/product/directorist-pricing-plans'>Directorist - Pricing Plans</a> extension.";

            $coup_types  = array(                
                'percentage_dis'    => __( 'Percentage discount', 'directorist-coupon' ),
                'fixed_cart_dis'    => __( 'Fixed cart discount', 'directorist-coupon' ),
                'fixed_product_dis' => __( 'Fixed product discount', 'directorist-coupon' ),
            );          

            $coup_templates  = array(                
                'temp_one'      => __( 'Template One', 'directorist-coupon' ),
                'temp_two'      => __( 'Template Two', 'directorist-coupon' ),
                'temp_three'    => __( 'Template Three', 'directorist-coupon' ),
                'temp_four'     => __( 'Template Four', 'directorist-coupon' ),
                'temp_five'     => __( 'Template five', 'directorist-coupon' ),
            );

            $get_coup_des       = get_post_meta( $post->ID, 'swbdpc_coupon_descrip', true );
            $get_coup_code      = get_post_meta( $post->ID, 'swbdpc_coupon_code', true );
            $get_coup_type      = get_post_meta( $post->ID, 'swbdpc_coupon_type', true );
            $get_fixed_products = get_post_meta( $post->ID, 'swbdpc_fixed_products', true );
            $get_coup_amount    = get_post_meta( $post->ID, 'swbdpc_coupon_amount', true );
            $get_coup_expiry    = get_post_meta( $post->ID, 'swbdpc_coupon_expiry', true );
            $get_coup_use_lim   = get_post_meta( $post->ID, 'swbdpc_coupon_usage_limit', true );
            $get_coup_user_lim  = get_post_meta( $post->ID, 'swbdpc_coupon_user_limit', true );
            $get_coup_temp      = get_post_meta( $post->ID, 'swbdpc_coupon_template', true );

            $saved_coup_des         = isset( $get_coup_des ) ? sanitize_text_field( $get_coup_des ) : '';
            $saved_coup_code        = isset( $get_coup_code ) ? sanitize_text_field( $get_coup_code ) : '';
            $saved_coup_type        = isset( $get_coup_type ) ? $get_coup_type : '';
            $saved_fixed_products   = SWBDPCHelperFunctions::normalize_plan_ids( isset( $get_fixed_products ) ? $get_fixed_products : array() );
            $saved_coup_amount      = isset( $get_coup_amount ) ? $get_coup_amount : '';
            $saved_coup_expiry      = isset( $get_coup_expiry ) ? $get_coup_expiry : '';
            $saved_coup_use_lim     = isset( $get_coup_use_lim ) ? $get_coup_use_lim : '';
            $saved_coup_user_lim    = isset( $get_coup_user_lim ) ? $get_coup_user_lim : '';
            $saved_coup_temp        = isset( $get_coup_temp ) ? $get_coup_temp : '';

            wp_nonce_field( 'swbdpc_coupon_details_cmb_action', 'swbdpc_coupon_details_cmb_field' );

            ?>
            <table class='form-table'>
                <tbody>
                    <tr>   <!-- START Coupon description input field row -->
                        <th><?php echo $l_coup_des; ?></th>
                        <td>                                
                            <textarea class="widefat" name='swbdpc_coupon_descrip' rows='4'><?php echo $saved_coup_des; ?></textarea>
                        </td>
                    </tr>   <!-- END Coupon description input field row -->                    
                </tbody>
            </table>

            <table class='form-table'>
                <tbody>    
                    <tr>    <!-- START coupon code input field row -->
                        <th><?php echo $l_coup_code; ?></th>
                        <td>  
                            <div class="swbdpc_coup-generator-wrap">
                                <button class='button' id='swbdpc__coup_code_generator'><?php echo $l_gen_coup_code; ?></button>
                                <input type="text" class="widefat" name='swbdpc_coupon_code' id='swbdpc__coupon_code' value="<?php echo $saved_coup_code; ?>" placeholder="<?php echo $placeho_enter_code; ?>">
                            </div>  
                        </td>                        
                        <td>
                            
                        </td>
                    </tr>   <!-- END coupon code input field row -->
                </tbody>
            </table>

            <table class='form-table'>
                <tbody>    

                    <tr>    <!-- START Coupon type input field row -->
                        <th><?php echo $l_coup_type; ?></th> 
                        <td>
                            <?php
                            $dropdown_coup_type = '<option value="0">'.__( 'Select a type', 'directorist-coupon' ).'</option>';
                            foreach( $coup_types as $coup_type_key => $coup_type ){
                                $selected_coup_type = '';
                                if( $coup_type_key == $saved_coup_type ){
                                	$selected_coup_type = "selected";
                                }
                                $dropdown_coup_type .= sprintf( "<option %s value='%s'>%s</option>", $selected_coup_type, $coup_type_key, $coup_type );
                            }
                            ?>
                            <select name="swbdpc_coupon_type" id="swbdpc__coupon_type"><?php echo $dropdown_coup_type; ?></select>
                        </td>
                    </tr>   <!-- END Coupon type input field row -->

                    <tr id="swbdpc__fixed_product_row">    <!-- START select product for fixed product discount input field row -->
                    <?php
                    $plans = self::get_pricing_plans();
                    
                    if( ! empty( $plans ) ) { ?>             
                        <th><?php echo $l_select_products; ?></th> 
                        <td>
                            <?php
                                foreach ( $plans as $plan ) {
                                        $product_name = $plan['title'];
                                        $product_id   = absint( $plan['id'] );
                                        $field_id     = 'swbdpc__fixed_product_' . $product_id;
                                        ?>
                                        <p>
                                            <input type="checkbox" name="swbdpc_fixed_products[]" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $product_id ); ?>" <?php echo in_array( $product_id,  $saved_fixed_products, true ) ? 'checked' : ''; ?>>
                                            <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $product_name ); ?></label>
                                        </p>
                                <?php }
                             ?>
                        </td>
                        <?php 
                    }else{
                        ?>
                        <th></th>
                        <td>    <!-- If don't active Directorist - Pricing Plan the display this message --> 
                            <p><?php _e( $pricing_plan_extnsions , 'directorist-coupon' ); ?></p>
                        </td>
                    <?php } ?>
                    </tr>   <!-- END select product for fixed product discount input field row -->

                    <tr>    <!-- START coupon amount input field row -->
                        <th><?php echo $l_coup_amount; ?></th>
                        <td>
                            <input type="number" name='swbdpc_coupon_amount' id='swbdpc__coupon_amount' value="<?php echo $saved_coup_amount; ?>" placeholder="<?php echo $placeho_enter_amount; ?>">
                        </td>
                    </tr>   <!-- END coupon amount input field row -->

                    <tr>    <!-- START coupon expiry input field row -->
                        <th><?php echo $l_coup_exp_date; ?></th>
                        <td>
                            <input type="date" name='swbdpc_coupon_expiry' id='' value="<?php echo $saved_coup_expiry; ?>">
                        </td>
                    </tr>   <!-- END coupon expiry input field row -->

                    <tr>    <!-- START usage limit row -->
                        <th><?php echo $l_usage_limit; ?></th>
                        <td>
                            <input type="number" name='swbdpc_coupon_usage_limit' id='' value="<?php echo $saved_coup_use_lim; ?>" placeholder="<?php echo $placeho_usage_limit; ?>">
                        </td>
                    </tr>   <!-- END usage limit row -->

                        
                <!--    <tr>  -->  <!-- START usage limit row -->
                <!--        <th><?php echo $l_user_limit; ?></th>
                        <td>
                            <input type="number" name='swbdpc_coupon_user_limit' id='' value="<?php echo $saved_coup_user_lim; ?>" placeholder="<?php echo $placeho_usage_limit; ?>">
                        </td>
                    </tr>  --> <!-- END usage limit row -->

                <!--    <tr>  -->  <!-- START coupon template input field field row -->
                <!--        <th><?php echo $l_coup_template; ?></th> 
                        <td>
                            <?php
                            $dropdown_coup_temp = '<option value="0">'.__( 'Select a template', 'directorist-coupon' ).'</option>';

                            foreach( $coup_templates as $coup_temp_key => $coup_template ){
                                $selected_coup_temp = '';
                                if( $coup_temp_key == $saved_coup_temp ){
                                	$selected_coup_temp = "selected";
                                }

                                $dropdown_coup_temp .= sprintf( "<option %s value='%s'>%s</option>", $selected_coup_temp, $coup_temp_key, $coup_template );
                            }
                            ?>
                            <select name="swbdpc_coupon_template" id=""><?php echo $dropdown_coup_temp; ?></select>
                        </td>
                    </tr> -->  <!-- END coupon template input field field row -->

                </tbody>
            </table>

            <div class="swbdpc_shortcode"> <!-- Start ad's shortcode creator row -->
                <h2><?php esc_html_e( 'Shortcode', 'directorist-coupon' ); ?></h2>
                <p><?php esc_html_e( 'Use following shortcode to display this coupon anywhere:', 'directorist-coupon' ); ?></p>
                <textarea cols="50" rows="1" onClick="this.select();">[directorist_coupons id=<?php echo $post->ID; ?>]</textarea>
                <br/>
                <p><?php esc_html_e( 'If you need to put the shortcode inside php code/template file, use this:', 'directorist-coupon' ); ?></p>
                <textarea cols="63" rows="1" onClick="this.select();"><?php echo '<?php echo do_shortcode( "[directorist_coupons id='; echo $post->ID . "]"; echo '" ); ?>';
            ?></textarea>
            </div>  <!-- END ad's shortcode creator row -->
            <?php
        }

        private static function get_pricing_plans() {
            $plans = array();

            if ( function_exists( 'directorist_pricing_plans_singleton' ) && class_exists( 'DirectoristPricingPlan\App\Repositories\Admin\PlanRepository' ) ) {
                $repository = directorist_pricing_plans_singleton( 'DirectoristPricingPlan\App\Repositories\Admin\PlanRepository' );
                $records    = $repository->get_query_builder()->order_by( 'plan.id', 'desc' )->get();

                if ( is_array( $records ) ) {
                    foreach ( $records as $record ) {
                        if ( empty( $record->id ) || empty( $record->title ) ) {
                            continue;
                        }

                        $plans[] = array(
                            'id'    => absint( $record->id ),
                            'title' => $record->title,
                        );
                    }
                }
            }

            return $plans;
        }

    } // Class closer
} // If statement closer 