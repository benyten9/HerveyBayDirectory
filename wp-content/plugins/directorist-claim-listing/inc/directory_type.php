<?php
/*
 * Class: Business Directory Multiple Image = ATPP
 * */
if (!class_exists('Claim_Post_Type_Manager')) :
    class Claim_Post_Type_Manager
    {
        public function __construct()
        {
            add_filter('atbdp_single_listing_other_fields_widget', array($this, 'atbdp_form_builder_widgets'));
            add_filter( 'directorist_single_section_template', array( $this, 'directorist_single_section_template' ), 10, 2 );
            add_filter('atbdp_listing_type_settings_field_list', array($this, 'atbdp_listing_type_settings_field_list'));
            add_action('atbdp_all_listings_badge_template', array($this, 'atbdp_all_listings_badge_template'));


            if( is_admin() ) {
                add_action( 'add_meta_boxes_' . ATBDP_POST_TYPE, array( $this, 'atbdp_meta_box' ) );
                add_action( 'save_post', array( $this , 'dcl_save_metabox' ), 10, 2);
            }
        }

        public function atbdp_all_listings_badge_template( $field ) {
            $listing_id = get_the_ID();
            $claimed = get_post_meta( $listing_id, '_claimed_by_admin', true );
            if( !empty( $claimed ) ){
                switch ($field['widget_key']) {
                    case 'verified_badge':
                        if (!get_directorist_option('enable_claim_listing', 1)) return; // vail if the business hour is not enabled
                        if (!get_directorist_option('verified_badge', 1)) return; // vail if the business hour is not enabled
                        $verified_text = get_directorist_option('verified_text', esc_html__('Claimed', 'directorist-claim-listing'));
                        $claimed_by_admin = get_post_meta($listing_id, '_claimed_by_admin', true);
                        $class = directorist_legacy_mode() ? 'directorist-claimed atbdp_info_list' : 'directorist-claimed directorist-info-item';
                        if (!empty($claimed_by_admin)) {
                                $field_data = [
                                    'class' => $class,
                                    'verified_text' => $verified_text,
                                    'hover_text' => __('Verified by it\'s Owner', 'directorist-claim-listing'),
                                ];
                             DCL_Base()->load_template('verified-badge', array('field_data' => $field_data));
                        }
                    break;
                }  
            }
        }

        public function atbdp_listing_type_settings_field_list( $fields ){

            foreach( $fields as $key => $value ) {
                // setup widgets
                $hours_widget = [
                    'type' => "badge",
                    'id' => "verified_badge",
                    'label' => "Verified",
                    'icon' => "uil uil-text-fields",
                    'hook' => "atbdp_verified_badge",
                    'options' => [],
                  ];
               
                if( 'listings_card_grid_view' === $key  ) {
                    // register widget
                    $fields[$key]['card_templates']['grid_view_with_thumbnail']['widgets']['verified_badge'] = $hours_widget;
                    $fields[$key]['card_templates']['grid_view_without_thumbnail']['widgets']['verified_badge'] = $hours_widget;

                    // grid with preview image
                      array_push( $fields[$key]['card_templates']['grid_view_with_thumbnail']['layout']['thumbnail']['top_right']['acceptedWidgets'], 'verified_badge' );
                      array_push( $fields[$key]['card_templates']['grid_view_with_thumbnail']['layout']['thumbnail']['top_left']['acceptedWidgets'], 'verified_badge' );
                      array_push( $fields[$key]['card_templates']['grid_view_with_thumbnail']['layout']['thumbnail']['bottom_right']['acceptedWidgets'], 'verified_badge' );
                      array_push( $fields[$key]['card_templates']['grid_view_with_thumbnail']['layout']['thumbnail']['bottom_left']['acceptedWidgets'], 'verified_badge' );
                      array_push( $fields[$key]['card_templates']['grid_view_with_thumbnail']['layout']['body']['top']['acceptedWidgets'], 'verified_badge' );
                      
                      // grid without preview image
                      array_push( $fields[$key]['card_templates']['grid_view_without_thumbnail']['layout']['body']['quick_info']['acceptedWidgets'], 'verified_badge' );
                    }
                    
                    if( 'listings_card_list_view' === $key ) {
                        // register widget
                        $fields[$key]['card_templates']['list_view_with_thumbnail']['widgets']['verified_badge'] = $hours_widget;
                        $fields[$key]['card_templates']['list_view_without_thumbnail']['widgets']['verified_badge'] = $hours_widget;
                        
                        // grid with preview image
                        array_push( $fields[$key]['card_templates']['list_view_with_thumbnail']['layout']['thumbnail']['top_right']['acceptedWidgets'], 'verified_badge' );
                        array_push( $fields[$key]['card_templates']['list_view_with_thumbnail']['layout']['body']['top']['acceptedWidgets'], 'verified_badge' );
                        array_push( $fields[$key]['card_templates']['list_view_with_thumbnail']['layout']['body']['right']['acceptedWidgets'], 'verified_badge' );

                        // grid without preview image
                        array_push( $fields[$key]['card_templates']['list_view_without_thumbnail']['layout']['body']['top']['acceptedWidgets'], 'verified_badge' );
                        array_push( $fields[$key]['card_templates']['list_view_without_thumbnail']['layout']['body']['right']['acceptedWidgets'], 'verified_badge' );
                }

            }
            return $fields;
        }

        public function atbdp_meta_box() {
            if ( ! get_directorist_option( 'enable_claim_listing', 1 ) ) return; // vail if the business hour is not enabled
            add_meta_box( '_listing_claim',
                __( 'Claim Details', 'directorist-claim-listing' ),
                array( $this, 'dcl_admin_claim' ),
                ATBDP_POST_TYPE,
                'side', 'high' );
        }

        public function dcl_admin_claim($post)
        {
            $url = admin_url() . 'edit.php?post_type=at_biz_dir&page=atbdp-extension';
            $current_val = get_post_meta($post->ID, '_claim_fee', true);
            $claim_charge = get_post_meta($post->ID, '_claim_charge', true);
            $claimed_by_admin = get_post_meta($post->ID, '_claimed_by_admin', true);
            // Add a nonce field so we can check for it later
            wp_nonce_field('dcl_save_listing_claim_details', 'dcl_listing_claim_details_nonce');
            ?>
            <div>
                <input id="claimed_by_admin" type="checkbox" name="claimed_by_admin"
                       value="1" <?php checked('1', $claimed_by_admin) ?>>
                <strong><label
                            for="claimed_by_admin"><?php _e('Mark as Claimed', 'directorist-claim-listing') ?></label></strong>
            </div>
            <div class="directorist-admin-claim" id="pricing_plans">
                <?php 
                if( directorist_is_claimable_with_plan() ) {
                    ?>
                    <div>
                        <input id="claim_with_pricing" type="radio" name="claim_fee"
                           value="pricing_plan" <?php checked('pricing_plan', $current_val) ?>>
                        <label for="claim_with_pricing"><?php _e('Charge claimer with <a href="' . $url . '" target="_blank">Pricing Plans</a>', 'directorist-claim-listing') ?></label>
                    </div>
                    <?php
                }
                ?>
                
                <div>
                    <input id="free_claim" type="radio" name="claim_fee"
                           value="free_claim" <?php checked('free_claim', $current_val) ?>>
                    <label for="free_claim"><?php _e('Claim for Free', 'directorist-claim-listing') ?></label>
                </div>
                <div>
                    <input id="clain_with_fee" type="radio" name="claim_fee"
                           value="static_fee" <?php checked('static_fee', $current_val) ?>>
                    <label for="clain_with_fee"><?php _e('Set a claim fee', 'directorist-claim-listing') ?></label>
                </div>
                <input type="number" value="<?php echo !empty($claim_charge) ? $claim_charge : ''; ?>" name="claim_charge"
                       min="0">
            </div>
            <?php
        }

        public function dcl_save_metabox($post_id, $post)
        {

            if (!isset($_POST['post_type'])) {
                return $post_id;
            }

// If this is an autosave, our form has not been submitted, so we don't want to do anything
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return $post_id;
            }

// Check the logged in user has permission to edit this post
            if (!current_user_can('edit_post', $post_id)) {
                return $post_id;
            }
            if (isset($_POST['dcl_listing_claim_details_nonce'])) {

                // Verify that the nonce is valid
                if (wp_verify_nonce($_POST['dcl_listing_claim_details_nonce'], 'dcl_save_listing_claim_details')) {
                    $claim_fee = isset($_POST['claim_fee']) ? esc_attr($_POST['claim_fee']) : '';
                    $claimed_by_admin = isset($_POST['claimed_by_admin']) ? esc_attr($_POST['claimed_by_admin']) : '';
                    $claim_charge = isset($_POST['claim_charge']) ? (int)$_POST['claim_charge'] : '';
                    update_post_meta($post_id, '_claim_fee', $claim_fee);
                    update_post_meta($post_id, '_claim_charge', $claim_charge);
                    update_post_meta($post_id, '_claimed_by_admin', $claimed_by_admin);
                }
            }

        }

        public function directorist_single_section_template( $template, $field_data ) {
           
            if( 'claim_listing' === $field_data['widget_name'] ) {
                $template .= DCL_Base()->load_template( 'claim-listing-template', [ 'field_data' => $field_data ] );
            }

            return $template;
        }

        public function atbdp_form_builder_widgets( $widgets )
        {
            $widgets['claim_listing'] = [
                'type' => 'section',
                'label' => 'Claim',
                'icon' => 'la la-edit',
                'options' => [
                    'custom_block_id' => [
                        'type'       => 'text',
                        'label'      => 'Custom block ID',
                        'value'      => '',
                        'field_type' => 'advanced',
                    ],
                    'custom_block_classes' => [
                        'type'       => 'text',
                        'label'      => 'Custom block Classes',
                        'value'      => '',
                        'field_type' => 'advanced',
                    ],
                ],  
            ];
            return $widgets;
        }
      

    }
endif;