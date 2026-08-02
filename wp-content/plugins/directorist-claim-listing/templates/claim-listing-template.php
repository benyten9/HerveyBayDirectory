<?php
    $listing_id           = get_the_ID();
    $admin_only_claimable = get_directorist_option( 'admin_only_claimable', false );
    
    if ( ! empty( $admin_only_claimable ) && ! directorist_is_listing_created_by_admin( $listing_id ) ) return; // vBail if not created by admin
    if (!get_directorist_option('enable_claim_listing', 1)) return; // vail if the business hour is not enabled
    $claim_header = get_directorist_option('claim_widget_title', esc_html__('Is this your business?', 'directorist-claim-listing'));
    $claim_description = get_directorist_option('claim_widget_description', esc_html__('Claim listing is the best way to manage and protect your business.', 'directorist-claim-listing'));
    $claim_now = get_directorist_option('claim_now', esc_html__('Claim Now!', 'directorist-claim-listing'));
    $claimed_by_admin = get_post_meta($listing_id, '_claimed_by_admin', true);
    $claim_fee = get_post_meta($listing_id, '_claim_fee', true);
    if ($claimed_by_admin || ('claim_approved' === $claim_fee)) return;
    ?>
    <div class="directorist-claim-listing-wrapper <?php echo esc_html( $field_data['custom_block_classes'] ); ?>">
        <?php if (is_user_logged_in()) { ?>
            <div class="directorist-card directorist-claim-listing">
                <div class="directorist-card__header">
                    <h3 class="directorist-card__header__title">
                    <span class="directorist-card__header-icon"><?php directorist_icon( $field_data['icon'] ); ?></span>
                    <span class="directorist-card__header-text">
                        <?php echo esc_html( $field_data['label'] ); ?>
                    </span>
                    </h3>
                </div>
                <div class="directorist-card__body">
                    <h4 class="directorist-claim-listing__title"><?php _e("$claim_header", 'directorist-claim-listing') ?></h4>
                    <p class="directorist-claim-listing__description"><?php _e("$claim_description", 'directorist-claim-listing') ?></p>
                    <a href="#" class=" directorist-btn directorist-btn-modal directorist-btn-modal-js" data-directorist_target="directorist-claim-listing-modal" ><?php _e("$claim_now", 'directorist-claim-listing') ?></a>
                </div>
            </div>
        <?php } else { ?>
            <div class="directorist-card directorist-claim-listing">
                <div class="directorist-card__header">
                    <h3 class="directorist-card__header__title">
                        <span class="directorist-card__header-icon"><?php directorist_icon( $field_data['icon'] ); ?></span>
                        <span class="directorist-card__header-text">
                            <?php echo esc_html( $field_data['label'] ); ?>
                        </span>
                    </h3>
                </div>

                <div class="directorist-card__body">
                    <h4 class="directorist-claim-listing__title"><?php _e("$claim_header", 'directorist-claim-listing') ?></h4>
                    <p class="directorist-claim-listing__description"><?php _e("$claim_description", 'directorist-claim-listing') ?></p>
                    <a href="#" class="directorist-claim-listing__login-alert  directorist-btn directorist-btn-modal directorist-btn-modal-js"><?php _e("$claim_now", 'directorist-claim-listing') ?></a>
                    <div class="directorist-claim-listing__login-notice directorist_notice directorist-alert directorist-alert-info" role="alert">
                        <?php
                        directorist_icon( 'fas fa-info-circle' );
                        // get the custom registration page id from the db and create a permalink
                        $reg_link_custom = ATBDP_Permalink::get_registration_page_link();
                        //if we have custom registration page, use it, else use the default registration url.
                        $reg_link = !empty($reg_link_custom) ? $reg_link_custom : wp_registration_url();

                        $login_url = '<a href="' . ATBDP_Permalink::get_login_page_link() . '">' . __('Login', 'directorist-claim-listing') . '</a>';
                        $register_url = '<a href="' . esc_url($reg_link) . '">' . __('Register', 'directorist-claim-listing') . '</a>';

                        printf(__('You need to %s or %s to claim this listing', 'directorist-claim-listing'), $login_url, $register_url);
                        ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <input type="hidden" id="directorist__post-id" value="<?php echo get_the_ID(); ?>"/>
    </div>
    <div class="directorist-modal directorist-modal-js directorist-fade directorist-claim-listing-modal directorist-claimer">
        <div class="directorist-modal__dialog directorist-modal__dialog-lg">
            <div class="directorist-modal__content">
                <form id="directorist-claimer__form" class="directorist-claimer__form">
                    <div class="directorist-modal__header">
                        <h3 class="directorist-modal-title" id="directorist-claim-label"><?php _e('Claim This Listing', 'directorist-claim-listing'); ?></h3>
                        <a href="#" class="directorist-modal-close directorist-modal-close-js"><span aria-hidden="true">&times;</span></a>
                    </div>
                    <div class="directorist-modal__body">
                        <div class="directorist-form-group">
                            <label for="directorist-claimer__name" class="directorist-claimer__name"><?php _e('Full Name', 'directorist-claim-listing'); ?> <span class="directorist-claimer__star-red">*</span></label>
                            <input type="text" class="directorist-form-element  lol" id="directorist-claimer__name" name="claimer_name" autocomplete="name" placeholder="<?php _e('Full Name', 'directorist-claim-listing'); ?>" required>
                        </div>
                        <div class="directorist-form-group">
                            <label for="directorist-claimer__phone" class="directorist-claimer__phone"><?php _e('Phone', 'directorist-claim-listing'); ?> <span class="directorist-claimer__star-red">*</span></label>
                            <input type="tel" class="directorist-form-element" id="directorist-claimer__phone" name="claimer_phone" autocomplete="tel" placeholder="<?php _e('111-111-235', 'directorist-claim-listing'); ?>" required>
                        </div>
                        <div class="directorist-form-group">
                            <label for="directorist-claimer__details" class="directorist-claimer__details"><?php _e('Verification Details', 'directorist-claim-listing'); ?> <span class="directorist-claimer__star-red">*</span></label>
                            <textarea class="directorist-form-element" id="directorist-claimer__details" name="claimer_details" autocomplete="off" rows="3" placeholder="<?php _e('Details description about your business', 'directorist-claim-listing'); ?>..." required></textarea>
                        </div>
                        <div class="directorist-form-group directorist-pricing-plan">
                            <?php include DCL_TEMPLATES_DIR . 'partials/plan-selector.php'; ?>
                        </div>
                        <div id="directorist-claimer__submit-notification"></div>
                        <div id="directorist-claimer__warning-notification"></div>
                    </div>

                    <div class="directorist-modal__footer">
                        <button type="submit" class="directorist-btn"><?php esc_html_e('Submit', 'directorist-claim-listing'); ?></button>
                        <span><?php directorist_icon( 'fas fa-lock' ); ?><?php esc_html_e('Secure Claim Process', 'directorist-claim-listing'); ?></span>
                    </div>
                </form>
            </div>
        </div>
    </div>