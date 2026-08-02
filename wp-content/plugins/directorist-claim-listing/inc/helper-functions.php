<?php
// Prohibit direct script loading.
defined('ABSPATH') || die('No direct script access allowed!');

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;


if (!function_exists('atbdp_get_option')) {

    /**
     * @return array    It returns the role of the users
     */
    function all_rules()
    {
        return apply_filters('dgrc_default_user_roles', array(
            array(
                'value' => 'administrator',
                'label' => __('Administrator', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'editor',
                'label' => __('Editor', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'author',
                'label' => __('Author', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'contributor',
                'label' => __('Contributor', 'directorist-claim-listing'),
            ),
            array(
                'value' => 'subscriber',
                'label' => __('Subscriber', 'directorist-claim-listing'),
            )
        ));
    }


    /**
     * It retrieves an option from the database if it exists and returns false if it is not exist.
     * It is a custom function to get the data of custom setting page
     * @param string $name The name of the option we would like to get. Eg. map_api_key
     * @param string $group The name of the group where the option is saved. eg. general_settings
     * @param mixed $default Default value for the option key if the option does not have value then default will be returned
     * @return mixed    It returns the value of the $name option if it exists in the option $group in the database, false otherwise.
     */

    function atbdp_get_option($name, $group, $default = false)
    {
        // at first get the group of options from the database.
        // then check if the data exists in the array and if it exists then return it
        // if not, then return false
        if (empty($name) || empty($group)) {
            if (!empty($default)) return $default;
            return false;
        } // vail if either $name or option $group is empty
        $options_array = (array)get_option($group);
        if (array_key_exists($name, $options_array)) {
            return $options_array[$name];
        } else {
            if (!empty($default)) return $default;
            return false;
        }
    }
}

if (!function_exists('get_directorist_option')) {

    /**
     * It retrieves an option from the database if it exists and returns false if it is not exist.
     * It is a custom function to get the data of custom setting page
     * @param string $name The name of the option we would like to get. Eg. map_api_key
     * @param mixed $default Default value for the option key if the option does not have value then default will be returned
     * @param bool $force_default Whether to use default value when database return anything other than NULL such as '', false etc
     * @return mixed    It returns the value of the $name option if it exists in the option $group in the database, false otherwise.
     */
    function get_directorist_option($name, $default = false, $force_default = false)
    {
        // at first get the group of options from the database.
        // then check if the data exists in the array and if it exists then return it
        // if not, then return false
        if (empty($name)) {
            return $default;
        }
        // get the option from the database and return it if it is not a null value. Otherwise, return the default value
        $options = (array)get_option('atbdp_option');
        $v = (array_key_exists($name, $options))
            ? $v = $options[sanitize_key($name)]
            : null;

        $newvalue = apply_filters( 'directorist_option', $v, $name );

        if ( $newvalue != $v ) {
           return $newvalue;
        }

        // use default only when the value of the $v is NULL
        if (is_null($v)) {
            return $default;
        }
        if ($force_default) {
            // use the default value even if the value of $v is falsy value returned from the database
            if (empty($v)) {
                return $default;
            }
        }
        return (isset($v)) ? $v : $default; // return the data if it is anything but NULL.
    }
}

if (!function_exists('atbdp_sanitize_array')) {
    /**
     * It sanitize a multi-dimensional array
     * @param array &$array The array of the data to sanitize
     * @return mixed
     */
    function atbdp_sanitize_array(&$array)
    {

        foreach ($array as &$value) {

            if (!is_array($value)) {

                // sanitize if value is not an array
                $value = sanitize_text_field($value);

            } else {

                // go inside this function again
                atbdp_sanitize_array($value);
            }

        }

        return $array;

    }
}

if (!function_exists('is_directoria_active')) {
    /**
     * It checks if the Directorist theme is installed currently.
     * @return bool It returns true if the directorist theme is active currently. False otherwise.
     */
    function is_directoria_active()
    {
        return wp_get_theme()->get_stylesheet() === 'directoria';
    }
}


/// claim listing

if (!function_exists('dcl_claim_status')) {
    function dcl_claim_status()
    {
        $status = array(
            'pending' => __('Pending', 'directorist-claim-listing'),
            'approved' => __('Approved', 'directorist-claim-listing'),
            'declined' => __('Decline', 'directorist-claim-listing'),
        );
        return $status;
    }
}

if (!function_exists('is_pricing_plans_active')) {
    /**
     * Check if the pricing plans extension is active.
     *
     * @return bool
     */
    function is_pricing_plans_active() {
        return function_exists( 'directorist_pricing_plans_singleton' );
    }
}

if (!function_exists('is_pricing_plans_active_with_dcl')) {
    /**
     * Check if pricing plans is active and claim is configured to charge by plan.
     *
     * @return bool
     */
    function is_pricing_plans_active_with_dcl()
    {
        if ( ! is_pricing_plans_active() ) {
            return false;
        }

        return 'pricing_plan' === get_directorist_option( 'claim_charge_by' );
    }
}

/**
 * Send confermation to the listing owner.
 *
 * @return   string    $result    Message based on the result.
 * @since    1.0.0
 *
 */
if (!function_exists('dcl_current_user')) {
    function dcl_current_user()
    {
        // sanitize form values
        $post_id = !empty($_POST["post_id"]) ? (int)$_POST["post_id"] : '';
        $claimer_name = !empty($_POST["claimer_name"]) ? sanitize_text_field($_POST["claimer_name"]) : '';
        $claimer_phone = !empty($_POST["claimer_phone"]) ? $_POST["claimer_phone"] : '';
        $claimer_plan_id = isset($_POST["plan_id"]) ? (int)($_POST["plan_id"]) : '';
        $claimer_details = !empty($_POST["claimer_details"]) ? stripslashes(esc_textarea($_POST["claimer_details"])) : '';

        //lets update some necessery data
        update_post_meta($post_id, '_claimer_name', $claimer_name);
        update_post_meta($post_id, '_claimer_phone', $claimer_phone);
        update_post_meta($post_id, '_claimer_details', $claimer_details);
        update_post_meta($post_id, '_claimer_plans', $claimer_plan_id);

        if (get_directorist_option('disable_email_notification')) return false;
        if (!in_array('new_claim_submitted', get_directorist_option('notify_user', array('new_claim_submitted')))) return false;
        // vars
        $post_author_id = get_post_field('post_author', $post_id);
        $user = get_userdata($post_author_id);
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        $site_email = get_bloginfo('admin_email');
        $currennt_user = wp_get_current_user();
        $current_user_email = $currennt_user->user_email;
        $listing_title = get_the_title($post_id);
        $listing_url = get_permalink($post_id);
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $current_time = current_time('timestamp');
        $contact_email_subject = get_directorist_option('email_sub_new_claim');
        $contact_email_body = get_directorist_option('email_tmpl_new_claim');

        $placeholders = array(
            '==NAME==' => $user->display_name,
            '==USERNAME==' => $user->user_login,
            '==SITE_NAME==' => $site_name,
            '==SITE_LINK==' => sprintf('<a href="%s">%s</a>', $site_url, $site_name),
            '==SITE_URL==' => sprintf('<a href="%s">%s</a>', $site_url, $site_url),
            '==LISTING_TITLE==' => $listing_title,
            '==LISTING_LINK==' => sprintf('<a href="%s">%s</a>', $listing_url, $listing_title),
            '==LISTING_URL==' => sprintf('<a href="%s">%s</a>', $listing_url, $listing_url),
            '==SENDER_NAME==' => $claimer_name,
            '==SENDER_PHONE==' => $claimer_phone,
            '==MESSAGE==' => $claimer_details,
            '==TODAY==' => date_i18n($date_format, $current_time),
            '==NOW==' => date_i18n($date_format . ' ' . $time_format, $current_time)
        );

        $to = $current_user_email;

        $subject = strtr($contact_email_subject, $placeholders);

        $message = strtr($contact_email_body, $placeholders);
        $message = nl2br($message);

        $headers = "From: {$claimer_name} <{$current_user_email}>\r\n";


        $is_mail_send = get_post_meta($post_id, '_claimed_primary_mail', true);
        if (empty($is_mail_send)) {
            $success = directorist()->email->send_mail($to, $subject, $message, $headers);
            if ($success) {
                update_post_meta($post_id, '_claimed_primary_mail', 1);
            }
        }

    }
}
/**
 * Send confermation to the listing owner.
 *
 * @return   string    $result    Message based on the result.
 * @since    1.0.0
 *
 */
if (!function_exists('dcl_email_admin_listing_claim')) {
    function dcl_email_admin_listing_claim()
    {

        // sanitize form values
        $post_id = !empty($_POST["post_id"]) ? (int)$_POST["post_id"] : '';
        $claimer_name = !empty($_POST["claimer_name"]) ? sanitize_text_field($_POST["claimer_name"]) : '';
        $claimer_phone = !empty($_POST["claimer_phone"]) ? ($_POST["claimer_phone"]) : '';
        $claimer_details = !empty($_POST["claimer_details"]) ? stripslashes(esc_textarea($_POST["claimer_details"])) : '';
        //


        if (get_directorist_option('disable_email_notification')) return false;
        if (!in_array('new_claim_submitted', get_directorist_option('notify_admin', array('new_claim_submitted')))) return false;
        // vars
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        $currennt_user = wp_get_current_user();
        $current_user_email = $currennt_user->user_email;
        $listing_title = get_the_title($post_id);
        $listing_url = get_permalink($post_id);
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $current_time = current_time('timestamp');

        $placeholders = array(
            '{site_name}' => $site_name,
            '{site_link}' => sprintf('<a href="%s">%s</a>', $site_url, $site_name),
            '{site_url}' => sprintf('<a href="%s">%s</a>', $site_url, $site_url),
            '{listing_title}' => $listing_title,
            '{listing_link}' => sprintf('<a href="%s">%s</a>', $listing_url, $listing_title),
            '{listing_url}' => sprintf('<a href="%s">%s</a>', $listing_url, $listing_url),
            '{sender_name}' => $claimer_name,
            '{sender_email}' => $current_user_email,
            '{sender_phone}' => $claimer_phone,
            '{message}' => $claimer_details,
            '{today}' => date_i18n($date_format, $current_time),
            '{now}' => date_i18n($date_format . ' ' . $time_format, $current_time)
        );
        $send_emails = ATBDP()->email->get_admin_email_list();
        $to = !empty($send_emails) ? $send_emails : get_bloginfo('admin_email');

        $subject = __('[{site_name}] Contact via "{listing_title}"', 'directorist-claim-listing');
        $subject = strtr($subject, $placeholders);

        $message = __("Dear Administrator,<br /><br />An user on your website {site_name} submit a claim for this listing .<br /><br />Listing URL: {listing_url}<br /><br />Name: {sender_name}<br />Email: {sender_email}<br />Claim Details: {message}<br />Time: {now}", 'directorist-claim-listing');
        $message = strtr($message, $placeholders);

        $headers = "From: {$claimer_name} <{$current_user_email}>\r\n";

        $is_mail_send = get_post_meta($post_id, '_claimed_admin_primary_mail', true);
        if (empty($is_mail_send)) {
            $success = ATBDP()->email->send_mail($to, $subject, $message, $headers);
            if ($success) {
                update_post_meta($post_id, '_claimed_admin_primary_mail', 1);
            }
        }

    }
}


/**
 * @since 1.0.0
 */
if (!function_exists('dcl_need_to_charge_with_plan')) {
    /**
     * Determine if a plan-based claim needs payment.
     *
     * Returns true if no payment is needed (user has active package),
     * false if payment/checkout is required.
     *
     * @return bool
     */
    function dcl_need_to_charge_with_plan()
    {
        $post_id         = (int) $_POST['post_id'];
        $claimer_plan_id = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;
        $claim_charge_by = get_directorist_option( 'claim_charge_by' );
        $charged_by      = get_post_meta( $post_id, '_claim_fee', true );
        $charged_by      = ( $charged_by !== '' ) ? $charged_by : $claim_charge_by;

        if ( ! is_pricing_plans_active() ) {
            return true;
        }

        if ( 'pricing_plan' !== $charged_by ) {
            return true;
        }

        if ( empty( $claimer_plan_id ) ) {
            return false;
        }

        $plan = directorist_get_pricing_plan_by_id( $claimer_plan_id );

        if ( ! $plan ) {
            return false;
        }

        $user_id   = get_current_user_id();

        if ( PlanType::PACKAGE === $plan->type ) {
            $package = directorist_user_package_repository()->get_package_by_plan( $user_id, $claimer_plan_id );

            if ( $package && directorist_is_package_active( $package ) ) {
                update_post_meta( $post_id, directorist_plan_key(), $claimer_plan_id );
                return true;
            }

            return false;
        }

        // PAY_PER_LISTING always needs checkout
        return false;
    }
}
if (!function_exists('dcl_need_to_charge_without_plan')) {
    function dcl_need_to_charge_without_plan()
    {
        $manual_charge = need_claim_to_charge_manually();
        if (!empty($manual_charge)) {
            return false;
        } else {
            return true;
        }
    }
}


/**
 * @since 1.0.0
 */
if (!function_exists('need_claim_to_charge_manually')) {
    function need_claim_to_charge_manually()
    {
        $post_id = (int)$_POST["post_id"];
        $claim_charge_by = get_directorist_option('claim_charge_by');
        $charged_by = get_post_meta($post_id, '_claim_fee', true);
        $charged_by = ($charged_by !== '') ? $charged_by : $claim_charge_by;
        if ('static_fee' === $charged_by) {
            return true;
        } else {
            return false;
        }
    }
}
/**
 * @since 1.0.0
 */
if (!function_exists('non_paid_claim')) {
    function non_paid_claim()
    {
        $post_id = (int)$_POST["post_id"];
        $charged_by = get_post_meta($post_id, '_claim_fee', true);
        $charged_by = $charged_by ? $charged_by : get_directorist_option( 'claim_charge_by', 'free_claim' );
        if ('free_claim' === $charged_by) {
            return true;
        } else {
            return false;
        }
    }
}

/**
 * @since 1.0.0
 */
if (!function_exists('dcl_new_claim')) {
    function dcl_new_claim($listing_id)
    {
        $claim_id = wp_insert_post(array(
            'post_content' => '',
            'post_title' => get_the_title($listing_id),
            'post_status' => 'publish',
            'post_type' => 'dcl_claim_listing',
            'comment_status' => false,
        ));

        $claimer_phone = get_post_meta($listing_id, '_claimer_phone', true);
        $claimer_name = get_post_meta($listing_id, '_claimer_name', true);
        $claimer_detail = get_post_meta($listing_id, '_claimer_details', true);
        $claimer_details = "This is $claimer_name, $claimer_detail";

        update_post_meta($claim_id, '_listing_claimer', get_current_user_id());
        update_post_meta($claim_id, '_claimed_listing', $listing_id);
        update_post_meta($claim_id, '_claim_status', 'pending');
        update_post_meta($claim_id, '_claimer_details', $claimer_details);
        update_post_meta($claim_id, '_claimer_phone', $claimer_phone);

        /**
         * @since 1.3.7
         */
        do_action( 'atbdp_new_claim_inserted', $claim_id );
    }
}

/**
 * @since 1.0.0
 * check is user already submitted claim for this listing
 */

if (!function_exists('dcl_tract_duplicate_claim')) {
    function dcl_tract_duplicate_claim($claimer, $listing)
    {
        $claims = new WP_Query(array(
            'post_type' => 'dcl_claim_listing',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_listing_claimer',
                    'value' => $claimer,
                ),
                array(
                    'key' => '_claimed_listing',
                    'value' => $listing,
                ),
                array(
                    'key' => '_claim_status',
                    'value' => 'pending',
                )
            )
        ));
        $claim_meta = array();
        foreach ($claims->posts as $key => $val) {
            $claim_meta[] = !empty($val) ? $val : array();
        }
        return ($claim_meta) ? $claim_meta : false;
    }
}

// dcl_get_template
function dcl_get_template( $template_file, $args = array() ) {
    if ( is_array( $args ) ) {
        extract( $args );
    }

    $theme_template  = '/directorist-claim-listing/' . $template_file . '.php';
    $plugin_template = DCL_TEMPLATES_DIR . $template_file . '.php';

    if ( file_exists( get_stylesheet_directory() . $theme_template ) ) {
        $file = get_stylesheet_directory() . $theme_template;
    } elseif ( file_exists( get_template_directory() . $theme_template ) ) {
        $file = get_template_directory() . $theme_template;
    } else {
        $file = $plugin_template;
    }


    if ( file_exists( $file ) ) {
        include $file;
    }
}

/**
 * Check if the given nonce field contains a verified nonce.
 *
 * @since 7.0.6.2
 *
 * @return boolen
 */
function directorist_claim_verify_nonce( $nonce_field = 'nonce' ) {
    $nonce = ! empty( $_REQUEST[ $nonce_field ] ) ? $_REQUEST[ $nonce_field ] : '';
    return wp_verify_nonce( $nonce, 'directorist_claim_nonce' );
}

/**
 * Check if the given nonce field contains a verified nonce.
 *
 * @since 7.0.6.2
 *
 * @return boolean
 */
function directorist_is_claimable_with_plan() {
    if ( ! is_pricing_plans_active() ) {
        return false;
    }

    $plans = directorist_pricing_plan_repository()
        ->get_query_builder()
        ->where( 'is_published', 1 )
        ->limit( 1 )
        ->get();

    return ! empty( $plans );
}

function directorist_is_listing_created_by_admin( $listing_id ) {
    $author_id = get_post_field( 'post_author', $listing_id );

    if ( ! $author_id ) {
        return false;
    }

    $author = get_user_by( 'id', $author_id );

    if ( !$author || empty( $author->roles ) ) {
        return false;
    }

    return in_array('administrator', (array) $author->roles);
}