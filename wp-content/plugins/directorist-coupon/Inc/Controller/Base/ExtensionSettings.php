<?php
/**
 * @package  Directorist - Coupon
 */


if( ! class_exists( 'SWBDPCExtensionSettings' ) ){
    /**
     * Plugin's Settings handler class
     */
    class SWBDPCExtensionSettings
    {
        /**
        * Class constructor
        * Call all hooks which we wanna triger
        */
        public function register()
        {   
            // Directorist - coupon extension's enable option hook
            add_filter( 'atbdp_extension_settings_fields', array( $this, 'add_swbdp_coupon_enable_settings_field' ) );
            // push license settings
            add_filter('atbdp_license_settings_controls', array( $this, 'coupon_license_settings_controls'), 10, 1);
            add_filter('atbdp_extension_license_settings_init', array( $this, 'atbdp_extension_settings'));

            // licence activation
            add_action('wp_ajax_atbdp_coupon_license_activation', array( $this, 'atbdp_coupon_license_activation'));
            // license deactivation
            add_action('wp_ajax_atbdp_coupon_license_deactivation', array( $this, 'atbdp_coupon_license_deactivation'));
            
            // Hook remover
            add_action( 'plugins_loaded', array( $this, 'swbdpc_hook_remover' ) );
        }

        public function atbdp_extension_settings( $default ) {
            return true;
        }

        public function atbdp_coupon_license_deactivation()
        {
            $license = !empty($_POST['coupon_license']) ? trim($_POST['coupon_license']) : '';
            $options = get_option('atbdp_option');
            $options['coupon_license'] = $license;
            update_option('atbdp_option', $options);
            update_option('directorist_coupon_license', $license);
            $data = array();
            if (!empty($license)) {
                // data to send in our API request
                $api_params = array(
                    'edd_action' => 'deactivate_license',
                    'license' => $license,
                    'item_id' => ATBDP_PAYPAL_POST_ID, // The ID of the item in EDD
                    'url' => home_url()
                );
                // Call the custom API.
                $response = wp_remote_post(ATBDP_AUTHOR_URL, array('timeout' => 15, 'sslverify' => false, 'body' => $api_params));
                // make sure the response came back okay
                if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {

                    $data['msg'] = (is_wp_error($response) && !empty($response->get_error_message())) ? $response->get_error_message() : __('An error occurred, please try again.', 'directorist-coupon');
                    $data['status'] = false;

                } else {
                    $license_data = json_decode(wp_remote_retrieve_body($response));
                    if (!$license_data) {
                        $data['status'] = false;
                        $data['msg'] = __('Response not found!', 'directorist-coupon');
                        wp_send_json($data);
                        die();
                    }
                    update_option('directorist_coupon_license_status', $license_data->license);
                    if (false === $license_data->success) {
                        switch ($license_data->error) {
                            case 'expired' :
                                $data['msg'] = sprintf(
                                    __('Your license key expired on %s.', 'directorist-coupon'),
                                    date_i18n(get_option('date_format'), strtotime($license_data->expires, current_time('timestamp')))
                                );
                                $data['status'] = false;
                                break;

                            case 'revoked' :
                                $data['status'] = false;
                                $data['msg'] = __('Your license key has been disabled.', 'directorist-coupon');
                                break;

                            case 'missing' :

                                $data['msg'] = __('Invalid license.', 'directorist-coupon');
                                $data['status'] = false;
                                break;

                            case 'invalid' :
                            case 'site_inactive' :

                                $data['msg'] = __('Your license is not active for this URL.', 'directorist-coupon');
                                $data['status'] = false;
                                break;

                            case 'item_name_mismatch' :

                                $data['msg'] = sprintf(__('This appears to be an invalid license key for %s.', 'directorist-coupon'), 'Directorist - PayPal');
                                $data['status'] = false;
                                break;

                            case 'no_activations_left':

                                $data['msg'] = __('Your license key has reached its activation limit.', 'directorist-coupon');
                                $data['status'] = false;
                                break;

                            default :
                                $data['msg'] = __('An error occurred, please try again.', 'directorist-coupon');
                                $data['status'] = false;
                                break;
                        }

                    } else {
                        $data['status'] = true;
                        $data['msg'] = __('License deactivated successfully!', 'directorist-coupon');
                    }

                }
            } else {
                $data['status'] = false;
                $data['msg'] = __('License not found!', 'directorist-coupon');
            }
            wp_send_json($data);
            die();
        }


        public function atbdp_coupon_license_activation()
        {
            $license = !empty($_POST['coupon_license']) ? trim($_POST['coupon_license']) : '';
            $options = get_option('atbdp_option');
            $options['coupon_license'] = $license;
            update_option('atbdp_option', $options);
            update_option('directorist_coupon_license', $license);
            $data = array();
            if (!empty($license)) {
                // data to send in our API request
                $api_params = array(
                    'edd_action' => 'activate_license',
                    'license' => $license,
                    'item_id' => ATBDP_PAYPAL_POST_ID, // The ID of the item in EDD
                    'url' => home_url()
                );
                // Call the custom API.
                $response = wp_remote_post(ATBDP_AUTHOR_URL, array('timeout' => 15, 'sslverify' => false, 'body' => $api_params));
                // make sure the response came back okay
                if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {

                    $data['msg'] = (is_wp_error($response) && !empty($response->get_error_message())) ? $response->get_error_message() : __('An error occurred, please try again.', 'directorist-coupon');
                    $data['status'] = false;

                } else {

                    $license_data = json_decode(wp_remote_retrieve_body($response));
                    if (!$license_data) {
                        $data['status'] = false;
                        $data['msg'] = __('Response not found!', 'directorist-coupon');
                        wp_send_json($data);
                        die();
                    }
                    update_option('directorist_coupon_license_status', $license_data->license);
                    if (false === $license_data->success) {
                        switch ($license_data->error) {
                            case 'expired' :
                                $data['msg'] = sprintf(
                                    __('Your license key expired on %s.', 'directorist-coupon'),
                                    date_i18n(get_option('date_format'), strtotime($license_data->expires, current_time('timestamp')))
                                );
                                $data['status'] = false;
                                break;

                            case 'revoked' :
                                $data['status'] = false;
                                $data['msg'] = __('Your license key has been disabled.', 'directorist-coupon');
                                break;

                            case 'missing' :

                                $data['msg'] = __('Invalid license.', 'directorist-coupon');
                                $data['status'] = false;
                                break;

                            case 'invalid' :
                            case 'site_inactive' :

                                $data['msg'] = __('Your license is not active for this URL.', 'directorist-coupon');
                                $data['status'] = false;
                                break;

                            case 'item_name_mismatch' :

                                $data['msg'] = sprintf(__('This appears to be an invalid license key for %s.', 'directorist-coupon'), 'Directorist - PayPal');
                                $data['status'] = false;
                                break;

                            case 'no_activations_left':

                                $data['msg'] = __('Your license key has reached its activation limit.', 'directorist-coupon');
                                $data['status'] = false;
                                break;

                            default :
                                $data['msg'] = __('An error occurred, please try again.', 'directorist-coupon');
                                $data['status'] = false;
                                break;
                        }

                    } else {
                        $data['status'] = true;
                        $data['msg'] = __('License activated successfully!', 'directorist-coupon');
                    }

                }
            } else {
                $data['status'] = false;
                $data['msg'] = __('License not found!', 'directorist-coupon');
            }
            wp_send_json($data);
            die();
        }


        public function coupon_license_settings_controls($default)
        {
            
            $status = get_option('directorist_coupon_license_status');
            if (!empty($status) && ($status !== false && $status == 'valid')) {
                $action = array(
                    'type' => 'toggle',
                    'name' => 'coupon-deactivated',
                    'label' => __('Action', 'directorist-coupon'),
                    'validation' => 'numeric',
                );
            } else {
                $action = array(
                    'type' => 'toggle',
                    'name' => 'coupon-activated',
                    'label' => __('Action', 'directorist-coupon'),
                    'validation' => 'numeric',
                );
            }
            $new = apply_filters('atbdp_coupon_extension_controls', array(
                'type' => 'section',
                'title' => __('Ads Manager', 'directorist-coupon'),
                'description' => __('You can active your Ads Manager extension license
                 here.', 'directorist-coupon'),
                'fields' => apply_filters('atbdp_coupon_license_settings_field', array(
                    array(
                        'type' => 'textbox',
                        'name' => 'coupon_license',
                        'label' => __('License', 'directorist-coupon'),
                        'description' => __('Enter your Ads Manager extension license', 'directorist-coupon'),
                        'default' => '',
                    ),
                    $action
                )),

            ));
            $settings = apply_filters('atbdp_licence_menu_for_coupon', true);
            if($settings){
                array_push($default, $new);
            }
            return $default;
        }

        /**
         * Add Directorist - coupon extension's enable option in extension general settings in Directory plugin
         * 
         * @param array
         * @return array
         */
        public function add_swbdp_coupon_enable_settings_field( $extn_enable_field )
        {
            $add_extn_enable_field = array(
                'type'          => 'toggle',
                'name'          => 'swbdp_coupon_is_enable',
                'label'         => __( 'Enable Coupon', 'directorist-coupon'),  // In extn general section
                'description'   => __( '', 'directorist-coupon' ), 
                'default'       => 1
            );
            array_push( $extn_enable_field, $add_extn_enable_field );        

            return $extn_enable_field;
        }
        


        /**
         * Settings hook remover function
         */
        public function swbdpc_hook_remover()
        {
            // Get Coupon enable option
            $is_coupon_enable = get_directorist_option( 'swbdp_coupon_is_enable', 1 );

            // Authenticate, is Coupon enable or not
            if( 1 !== (int)$is_coupon_enable ){
                remove_filter( 'atbdp_extension_settings_fields', array( $this, 'add_swbdp_coupon_enable_settings_field' ) );
                remove_filter( 'atbdp_license_settings_controls', array( $this, 'coupon_license_settings_controls' ), 10, 1 );
                remove_filter( 'atbdp_extension_license_settings_init', array( $this, 'atbdp_extension_settings' ) );
                remove_action( 'wp_ajax_atbdp_coupon_license_activation', array( $this, 'atbdp_coupon_license_activation' ) );
                remove_action( 'wp_ajax_atbdp_coupon_license_deactivation', array( $this, 'atbdp_coupon_license_deactivation' ) );
            }
        }

    }// End class
}