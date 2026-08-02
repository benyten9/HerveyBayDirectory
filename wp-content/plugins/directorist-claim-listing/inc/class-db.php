<?php
if( ! isset( $_POST['post_type'] )) {
    return $post_id;
}


// If this is an autosave, our form has not been submitted, so we don't want to do anything
if( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
    return $post_id;
}

// Check the logged in user has permission to edit this post
if( ! current_user_can( 'edit_post', $post_id ) ) {
    return $post_id;
}

// Check if "dcl_claim_details_nonce" nonce is set
if( isset( $_POST['dcl_claim_details_nonce'] ) ) {


    // Verify that the nonce is valid
    if( wp_verify_nonce( $_POST['dcl_claim_details_nonce'], 'dcl_save_claim_details' ) ) {

        // OK to save meta data
        $listing_claimer =  !empty($_POST['listing_claimer'])?$_POST['listing_claimer']:'';
        update_post_meta($post_id, '_listing_claimer', $listing_claimer);

        $claimed_listing =  !empty($_POST['claimed_listing'])?$_POST['claimed_listing']:'';
        update_post_meta($post_id, '_claimed_listing', $claimed_listing);

        $claim_status = isset($_POST['claim_status'])?sanitize_text_field($_POST['claim_status']):'';
        update_post_meta($post_id, '_claim_status', $claim_status);

        $claimer_details = isset($_POST['claimer_details'])?sanitize_text_field($_POST['claimer_details']):'';
        update_post_meta($post_id, '_claimer_details', $claimer_details);

        $claimer_phone = isset($_POST['claimer_phone'])?($_POST['claimer_phone']):'';
        update_post_meta($post_id, '_claimer_phone', $claimer_phone);



        //lets update the claimer as the listing author
        if ('approved' === $claim_status){
            global $wpdb;
            $prefix = $wpdb->prefix;

            $update_data   = array(
                'post_author' => $listing_claimer
            );
            $where         = array(
                'ID' => $claimed_listing
            );
            $update_format = array(
                '%s'
            );
            $wpdb->update($prefix . 'posts', $update_data, $where, $update_format);
            $claimer_plans = get_post_meta( $claimed_listing, '_claimer_plans', true );
            if ( ! empty( $claimer_plans ) ) {
                $plan_key = function_exists( 'directorist_plan_key' ) ? directorist_plan_key() : '_plan_id';
                update_post_meta( $claimed_listing, $plan_key, $claimer_plans );
                update_post_meta( $claimed_listing, '_claimer_plans', 0 );
            }
            $need_featured = get_post_meta($claimed_listing, '_need_featured', true);
            if ($need_featured){
                update_post_meta($claimed_listing, '_featured', 1);
            }
            //update claim status for this listing
            update_post_meta($claimed_listing, '_claimed_by_admin', 1);
            update_post_meta($claimed_listing, '_claim_fee', 'claim_approved');

            if (get_directorist_option('disable_email_notification')) return false;
            if(! in_array( 'claim_confirmation', get_directorist_option('notify_user', array('claim_confirmation')) )) return false;
            //send confermation email to claimer
            $user                   = get_userdata( $listing_claimer );
            $site_name              = get_bloginfo( 'name' );
            $site_url               = get_bloginfo( 'url' );
            $site_email		        = get_bloginfo( 'admin_email' );
            $current_user_email     = $user->user_email;
            $listing_title          = get_the_title( $claimed_listing );
            $listing_url            = get_permalink( $claimed_listing );
            $date_format            = get_option( 'date_format' );
            $time_format            = get_option( 'time_format' );
            $current_time           = current_time( 'timestamp' );
            $contact_email_subject  = get_directorist_option('email_sub_approved_claim');
            $contact_email_body     = get_directorist_option('email_tmpl_approved_claim');
            $placeholders = array(
                '==NAME=='            => $user->display_name,
                '==USERNAME=='        => $user->user_login,
                '==SITE_NAME=='       => $site_name,
                '==SITE_LINK=='       => sprintf( '<a href="%s">%s</a>', $site_url, $site_name ),
                '==SITE_URL=='        => sprintf( '<a href="%s">%s</a>', $site_url, $site_url ),
                '==LISTING_TITLE=='   => $listing_title,
                '==LISTING_LINK=='    => sprintf( '<a href="%s">%s</a>', $listing_url, $listing_title ),
                '==LISTING_URL=='     => sprintf( '<a href="%s">%s</a>', $listing_url, $listing_url ),
                '==SENDER_NAME=='     => $site_name,
                '==TODAY=='           => date_i18n( $date_format, $current_time ),
                '==NOW=='             => date_i18n( $date_format . ' ' . $time_format, $current_time )
            );

            $to      = $current_user_email;

            $subject = strtr( $contact_email_subject, $placeholders );

            $message = strtr( $contact_email_body, $placeholders );
            $message = nl2br( $message );

            $headers  = "From: {$site_name} <{$current_user_email}>\r\n";

            // return true or false, based on the result

            $is_mail_send = get_post_meta($claimed_listing, '_claimed_ok_mail', true);
            if (empty($is_mail_send)){
                $success = ATBDP()->email->send_mail( $to, $subject, $message, $headers );
                if ($success){
                    update_post_meta($claimed_listing, '_claimed_ok_mail', 1);
                }
            }

        }
        if ('approved' !== $claim_status){
            //update claim status for this listing
            update_post_meta($claimed_listing, '_claimed_by_admin', '');
            if (get_directorist_option('disable_email_notification')) return false;
            if(! in_array( 'claim_confirmation', get_directorist_option('notify_user', array()) )) return false;
            $user                   = get_userdata( $listing_claimer );
            $site_name              = get_bloginfo( 'name' );
            $site_url               = get_bloginfo( 'url' );
            $site_email		        = get_bloginfo( 'admin_email' );
            $current_user_email     = $user->user_email;
            $listing_title          = get_the_title( $claimed_listing );
            $listing_url            = get_permalink( $claimed_listing );
            $date_format            = get_option( 'date_format' );
            $time_format            = get_option( 'time_format' );
            $current_time           = current_time( 'timestamp' );
            $contact_email_subject  = get_directorist_option('email_sub_declined_claim');
            $contact_email_body     = get_directorist_option('email_tmpl_declined_claim');
            $placeholders = array(
                '==NAME=='            => $user->display_name,
                '==USERNAME=='        => $user->user_login,
                '==SITE_NAME=='       => $site_name,
                '==SITE_LINK=='       => sprintf( '<a href="%s">%s</a>', $site_url, $site_name ),
                '==SITE_URL=='        => sprintf( '<a href="%s">%s</a>', $site_url, $site_url ),
                '==LISTING_TITLE=='   => $listing_title,
                '==LISTING_LINK=='    => sprintf( '<a href="%s">%s</a>', $listing_url, $listing_title ),
                '==LISTING_URL=='     => sprintf( '<a href="%s">%s</a>', $listing_url, $listing_url ),
                '==SENDER_NAME=='     => $site_name,
                '==TODAY=='           => date_i18n( $date_format, $current_time ),
                '==NOW=='             => date_i18n( $date_format . ' ' . $time_format, $current_time )
            );

            $to      = $current_user_email;

            $subject = strtr( $contact_email_subject, $placeholders );

            $message = strtr( $contact_email_body, $placeholders );
            $message = nl2br( $message );

            $headers  = "From: {$site_name} <{$current_user_email}>\r\n";

            $is_mail_send = get_post_meta($claimed_listing, '_claimed_error_mail', true);
            if (empty($is_mail_send)){
                $success = ATBDP()->email->send_mail( $to, $subject, $message, $headers );
                if ($success){
                    update_post_meta($claimed_listing, '_claimed_error_mail', 1);
                }
            }
        }
    }
}
return $post_id;