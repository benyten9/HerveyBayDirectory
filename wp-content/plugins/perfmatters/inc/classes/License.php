<?php
namespace Perfmatters;

if(!defined('ABSPATH')) {
	exit;
}

class License {

    //init
    public static function init() {
        add_action('init', array('Perfmatters\License', 'constant_check'));
    }

    //activate license
    public static function activate($license_key = null, $network = false) {

        //grab existing license data
        $license = $license_key ?? (self::get_key_constant() ?? (is_multisite() || $network ? get_site_option('perfmatters_edd_license_key') : get_option('perfmatters_edd_license_key')));

        if(!empty($license)) {

            $api_params = array(
                'edd_action'=> 'activate_license',
                'license' 	=> $license,
                'item_name' => urlencode(PERFMATTERS_ITEM_NAME),
                'url'       => home_url()
            );

            //EDD API request
            $response = wp_remote_post(PERFMATTERS_STORE_URL, array('timeout' => 15, 'sslverify' => true, 'body' => $api_params));

            if(is_wp_error($response)) {
                return false;
            }

            //decode the license data
            $license_data = json_decode(wp_remote_retrieve_body($response));

            //always clear cached check data after an activation attempt
            self::clear_check_cache($license, $network);

            //license is valid
            if(!empty($license_data->license) && $license_data->license == 'valid') {

                //update stored option
                if(is_multisite() || $network) {
                    update_site_option('perfmatters_edd_license_status', $license_data->license);
                    return true;
                }
                else {
                    update_option('perfmatters_edd_license_status', $license_data->license, false);
                    return true;
                }
            }
        }

        return false;
    }

    //deactivate license
    public static function deactivate($license_key = null, $network = false) {

        //grab existing license data
        $license = $license_key ?? (self::get_key_constant() ?? (is_multisite() || $network ? get_site_option('perfmatters_edd_license_key') : get_option('perfmatters_edd_license_key')));

        if(!empty($license)) {

            $api_params = array(
                'edd_action'=> 'deactivate_license',
                'license' 	=> $license,
                'item_name' => urlencode(PERFMATTERS_ITEM_NAME),
                'url'       => home_url()
            );

            //EDD API request
            $response = wp_remote_post(PERFMATTERS_STORE_URL, array('timeout' => 15, 'sslverify' => true, 'body' => $api_params));

            if(is_wp_error($response)) {
                return false;
            }

            //decode the license data
            $license_data = json_decode(wp_remote_retrieve_body($response));

            //always clear cached check data after a deactivation attempt
            self::clear_check_cache($license, $network);

            //license is deactivated
            if($license_data->license == 'deactivated') {

                //update license option
                if(is_multisite() || $network) {
                    delete_site_option('perfmatters_edd_license_status');
                    return true;
                }
                else {
                    delete_option('perfmatters_edd_license_status');
                    return true;
                }
            }
        }

        return false;
    }

    //check license
    public static function check($license_key = null, $network = false, $force = false) {

        //grab existing license data
        $license = $license_key ?? (self::get_key_constant() ?? (is_multisite() || $network ? get_site_option('perfmatters_edd_license_key') : get_option('perfmatters_edd_license_key')));

        if(!empty($license)) {

            //return cached license data when available
            if(!$force) {
                $cached = self::get_cached_check($license, $network);
                if($cached !== false) {
                    return $cached;
                }
            }

            $api_params = array(
                'edd_action' => 'check_license',
                'license' => $license,
                'item_name' => urlencode(PERFMATTERS_ITEM_NAME),
                'url'       => home_url()
            );

            // Call the custom API.
            $response = wp_remote_post(PERFMATTERS_STORE_URL, array('timeout' => 15, 'sslverify' => true, 'body' => $api_params));

            //make sure the response came back okay
            if(is_wp_error($response)) {
                return false;
            }

            //decode the license data
            $license_data = json_decode(wp_remote_retrieve_body($response));

            //update license option
            if(is_multisite() || $network) {
                update_site_option('perfmatters_edd_license_status', $license_data->license);
            }
            else {
                update_option('perfmatters_edd_license_status', $license_data->license, false);
            }

            //cache successful check responses for 24 hours
            if(!empty($license_data)) {
                self::set_cached_check($license, $license_data, $network);
            }
            
            //return license data for use
            return($license_data);
        }

        return false;
    }

    //check for license key constant
    public static function constant_check() {

        //only check in admin
        if(is_admin()) {

            $license_key_constant = self::get_key_constant();
            $license_key_constant_stored = is_multisite() ? get_site_option('perfmatters_edd_license_key_constant') : get_option('perfmatters_edd_license_key_constant');

            //license constant defined
            if($license_key_constant !== false) {

                //mismatch on stored constant
                if($license_key_constant != $license_key_constant_stored) {

                    //deactivate previous stored key
                    if(!empty($license_key_constant_stored )) {
                        self::deactivate($license_key_constant_stored);
                    }

                    //store new key
                    if(is_multisite()) {
                        update_site_option('perfmatters_edd_license_key_constant', $license_key_constant);
                    }
                    else {
                        update_option('perfmatters_edd_license_key_constant', $license_key_constant, false);
                    }

                    //active new key
                    self::activate($license_key_constant);
                }

            }
            else {

                //constant still stored but no definition
                if($license_key_constant_stored != '') {

                    //deactivate previous stored key
                    self::deactivate($license_key_constant_stored);

                    //remove stored key
                    if(is_multisite()) {
                        delete_site_option('perfmatters_edd_license_key_constant');
                    }
                    else {
                        delete_option('perfmatters_edd_license_key_constant');
                    }
                }
            }
        }
    }

    //get license key constant
    public static function get_key_constant() {
        if(defined('PERFMATTERS_LICENSE_KEY')) {
            return trim(PERFMATTERS_LICENSE_KEY);
        }
    }

    //build cache key for license check responses
    private static function get_check_cache_key($license) {
        return 'perfmatters_license_check_' . md5($license . '|' . home_url());
    }

    //get cached license check response
    private static function get_cached_check($license, $network = false) {
        $cache_key = self::get_check_cache_key($license);
        $cached = (is_multisite() || $network) ? get_site_transient($cache_key) : get_transient($cache_key);

        if(empty($cached) || !is_object($cached)) {
            return false;
        }

        return $cached;
    }

    //store license check response
    private static function set_cached_check($license, $license_data, $network = false) {
        $cache_key = self::get_check_cache_key($license);

        if(is_multisite() || $network) {
            set_site_transient($cache_key, $license_data, DAY_IN_SECONDS);
        }
        else {
            set_transient($cache_key, $license_data, DAY_IN_SECONDS);
        }
    }

    //clear cached license check response
    private static function clear_check_cache($license = null, $network = false) {
        $license = $license ?? (self::get_key_constant() ?? (is_multisite() || $network ? get_site_option('perfmatters_edd_license_key') : get_option('perfmatters_edd_license_key')));

        if(empty($license)) {
            return;
        }

        $cache_key = self::get_check_cache_key($license);

        if(is_multisite() || $network) {
            delete_site_transient($cache_key);
        }
        else {
            delete_transient($cache_key);
        }
    }
}
