<?php

namespace DirectoristAnalytics\App\Helpers;

defined( 'ABSPATH' ) || exit;

use stdClass;

/**
 * Allows plugins to use their own update API.
 *
 * @author Easy Digital Downloads
 * @version 1.6.18
 */

class EddUpdaterAnalytics {
    private string $api_url;

    private array $api_data;

    private bool $beta = false;

    private string $name;

    private string $slug;

    private string $version = '';

    private bool $wp_override = false;

    private string $cache_key = '';

    private $health_check_timeout = 5;

    public function __construct( string $api_url, string $plugin_file, array $api_data ) {
        global $edd_plugin_data;

        $this->api_url     = trailingslashit( $api_url );
        $this->api_data    = $api_data;
        $this->name        = plugin_basename( $plugin_file );
        $this->slug        =  basename( $plugin_file, '.php' );
        $this->version     = ! empty( $this->api_data['version'] ) ? $this->api_data['version'] : '';
        $this->wp_override = isset( $this->api_data['wp_override'] ) ? ( bool ) $this->api_data['wp_override'] : false;
        $this->beta        = ! empty( $this->api_data['beta'] ) ? true : false;

        $license         = ! empty( $this->api_data['license'] ) ? $this->api_data['license'] : '';
        $this->cache_key = 'edd_sl_' . md5( serialize( $this->slug . $license . $this->beta ) );

        $edd_plugin_data[ $this->slug ] = $this->api_data;

        /**
         * Fires after the $edd_plugin_data is setup.
         *
         * @since x.x.x
         *
         * @param array $edd_plugin_data Array of EDD SL plugin data.
         */
        do_action( 'post_edd_sl_plugin_updater_setup', $edd_plugin_data );

        // Set up hooks.
        $this->init();
    }

    public function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugins_api_filter' ], 10, 3 );
        remove_action( 'after_plugin_row_' . $this->name, 'wp_plugin_update_row', 10 );
        add_action( 'after_plugin_row_' . $this->name, [ $this, 'show_update_notification' ], 10, 2 );
        add_action( 'admin_init', [ $this, 'show_changelog' ] );
    }

    /**
     * Check for Updates at the defined API endpoint and modify the update array.
     *
     * This function dives into the update API just when WordPress creates its update array,
     * then adds a custom API call and injects the custom plugin data retrieved from the API.
     * It is reassembled from parts of the native WordPress plugin update code.
     * See wp-includes/update.php line 121 for the original wp_update_plugins() function.
     *
     * @param stdClass  $transient_data Update object build by WordPress.
     * @return stdClass Modified update array with custom plugin data.
     */
    public function check_update( stdClass $transient_data ): stdClass {
        global $pagenow;

        if ( ! is_object( $transient_data ) ) {
            $transient_data = new stdClass;
        }

        if ( 'plugins.php' == $pagenow && is_multisite() ) {
            return $transient_data;
        }

        if ( ! empty( $transient_data->response ) && ! empty( $transient_data->response[ $this->name ] ) && false === $this->wp_override ) {
            return $transient_data;
        }

        $version_info = $this->get_cached_version_info();

        if ( false === $version_info ) {
            $version_info = $this->api_request( 'plugin_latest_version', [ 'slug' => $this->slug, 'beta' => $this->beta ] );

            $this->set_version_info_cache( $version_info );

        }

        if ( false !== $version_info && is_object( $version_info ) && isset( $version_info->new_version ) ) {

            if ( version_compare( $this->version, $version_info->new_version, '<' ) ) {

                $transient_data->response[ $this->name ] = $version_info;

                // Make sure the plugin property is set to the plugin's name/location. See issue 1463 on Software Licensing's GitHub repo.
                $transient_data->response[ $this->name ]->plugin = $this->name;

            }

            $transient_data->last_checked           = time();
            $transient_data->checked[ $this->name ] = $this->version;

        }

        return $transient_data;
    }

    /**
     * Show update notification row -- needed for multisite sub sites, because WP won't tell you otherwise!
     */
    public function show_update_notification( string $file, array $plugin ): void {
        if ( is_network_admin() ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        if ( ! is_multisite() ) {
            return;
        }

        if ( $this->name != $file ) {
            return;
        }

        // Remove our filter on the site transient
        remove_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ], 10 );

        $update_cache = get_site_transient( 'update_plugins' );

        $update_cache = is_object( $update_cache ) ? $update_cache : new stdClass();

        if ( empty( $update_cache->response ) || empty( $update_cache->response[ $this->name ] ) ) {

            $version_info = $this->get_cached_version_info();

            if ( false === $version_info ) {
                $version_info = $this->api_request( 'plugin_latest_version', [ 'slug' => $this->slug, 'beta' => $this->beta ] );

                // Since we disabled our filter for the transient, we aren't running our object conversion on banners, sections, or icons. Do this now:
                if ( isset( $version_info->banners ) && ! is_array( $version_info->banners ) ) {
                    $version_info->banners = $this->convert_object_to_array( $version_info->banners );
                }

                if ( isset( $version_info->sections ) && ! is_array( $version_info->sections ) ) {
                    $version_info->sections = $this->convert_object_to_array( $version_info->sections );
                }

                if ( isset( $version_info->icons ) && ! is_array( $version_info->icons ) ) {
                    $version_info->icons = $this->convert_object_to_array( $version_info->icons );
                }

                $this->set_version_info_cache( $version_info );
            }

            if ( ! is_object( $version_info ) ) {
                return;
            }

            if ( version_compare( $this->version, $version_info->new_version, '<' ) ) {

                $update_cache->response[ $this->name ] = $version_info;

            }

            $update_cache->last_checked           = time();
            $update_cache->checked[ $this->name ] = $this->version;

            set_site_transient( 'update_plugins', $update_cache );

        } else {
            $version_info = $update_cache->response[ $this->name ];
        }

        // Restore our filter
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );

        if ( ! empty( $update_cache->response[ $this->name ] ) && version_compare( $this->version, $version_info->new_version, '<' ) ) {

            // build a plugin list row, with update notification
            $wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );
            # <tr class="plugin-update-tr"><td colspan="' . $wp_list_table->get_column_count() . '" class="plugin-update colspanchange">
            echo '<tr class="plugin-update-tr" id="' . esc_attr( $this->slug ) . '-update" data-slug="' . esc_attr( $this->slug ) . '" data-plugin="' . esc_attr( $this->slug . '/' . $file ) . '">';
            echo '<td colspan="3" class="plugin-update colspanchange">';
            echo '<div class="update-message notice inline notice-warning notice-alt">';

            $changelog_link = self_admin_url( 'index.php?edd_sl_action=view_plugin_changelog&plugin=' . esc_attr( $this->name ) . '&slug=' . esc_attr( $this->slug ) . '&TB_iframe=true&width=772&height=911' );

            if ( empty( $version_info->download_link ) ) {
                printf(
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    __( 'There is a new version of %1$s available. %2$sView version %3$s details%4$s.', 'directorist-analytics' ),
                    esc_html( $version_info->name ),
                    '<a target="_blank" class="thickbox" href="' . esc_url( $changelog_link ) . '">',
                    esc_html( $version_info->new_version ),
                    '</a>'
                );
            } else {
                printf(
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    __( 'There is a new version of %1$s available. %2$sView version %3$s details%4$s or %5$supdate now%6$s.', 'directorist-analytics' ),
                    esc_html( $version_info->name ),
                    '<a target="_blank" class="thickbox" href="' . esc_url( $changelog_link ) . '">',
                    esc_html( $version_info->new_version ),
                    '</a>',
                    '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . esc_attr( $this->name ), 'upgrade-plugin_' . esc_attr( $this->name ) ) ) . '">',
                    '</a>'
                );
            }

            // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
            do_action( "in_plugin_update_message-{$file}", $plugin, $version_info );

            echo '</div></td></tr>';
        }
    }

    /**
     * Filters the response for the current WordPress.org Plugin Installation API request.
     *
     * @param false|object|array $result The result object or array. Default false.
     * @param string             $action The type of information being requested from the Plugin Installation API.
     * @param object             $args   Plugin API arguments.
     * @return false|object|array The result object or array. Default false.
     */
    public function plugins_api_filter( $data, string $action, object $args ) {
        if ( $action != 'plugin_information' ) {
            return $data;
        }

        if ( ! isset( $args->slug ) || ( $args->slug != $this->slug ) ) {
            return $data;
        }

        $to_send = [
            'slug'   => $this->slug,
            'is_ssl' => is_ssl(),
            'fields' => [
                'banners' => [],
                'reviews' => false,
                'icons'   => [],
            ]
        ];

        $cache_key = 'edd_api_request_' . md5( serialize( $this->slug . $this->api_data['license'] . $this->beta ) );

        // Get the transient where we store the api request for this plugin for 24 hours
        $edd_api_request_transient = $this->get_cached_version_info( $cache_key );

        // If we have no transient-saved value, run the API, set a fresh transient with the API value, and return that value too right now.
        if ( empty( $edd_api_request_transient ) ) {

            $api_response = $this->api_request( 'plugin_information', $to_send );

            // Expires in 3 hours
            $this->set_version_info_cache( $api_response, $cache_key );

            if ( false !== $api_response ) {
                $data = $api_response;
            }

        } else {
            $data = $edd_api_request_transient;
        }

        // Convert sections into an associative array, since we're getting an object, but Core expects an array.
        if ( isset( $data->sections ) && ! is_array( $data->sections ) ) {
            $data->sections = $this->convert_object_to_array( $data->sections );
        }

        // Convert banners into an associative array, since we're getting an object, but Core expects an array.
        if ( isset( $data->banners ) && ! is_array( $data->banners ) ) {
            $data->banners = $this->convert_object_to_array( $data->banners );
        }

        // Convert icons into an associative array, since we're getting an object, but Core expects an array.
        if ( isset( $data->icons ) && ! is_array( $data->icons ) ) {
            $data->icons = $this->convert_object_to_array( $data->icons );
        }

        if ( ! isset( $data->plugin ) ) {
            $data->plugin = $this->name;
        }

        return $data;
    }

    /**
     * Convert some objects to arrays when injecting data into the update API
     *
     * Some data like sections, banners, and icons are expected to be an associative array, however due to the JSON
     * decoding, they are objects. This method allows us to pass in the object and return an associative array.
     */
    private function convert_object_to_array( stdClass $data ): array {
        $new_data = [];
        foreach ( $data as $key => $value ) {
            $new_data[ $key ] = $value;
        }

        return $new_data;
    }

    /**
     * Disable SSL verification in order to prevent download update failures
     */
    public function http_request_args( array $args, string $url ): array {
        $verify_ssl = $this->verify_ssl();

        if ( strpos( $url, 'https://' ) !== false && strpos( $url, 'edd_action=package_download' ) ) {
            $args['sslverify'] = $verify_ssl;
        }

        return $args;
    }

    /**
     * Calls the API and, if successful, returns the object delivered by the API.
     *
     * @param string $action The requested action.
     * @param array  $data   Parameters for the API action.
     * 
     * @return ?object
     */
    private function api_request( string $action, array $data ): ?object {
        global $edd_plugin_url_available;

        $verify_ssl = $this->verify_ssl();

        // Do a quick status check on this domain if we haven't already checked it.
        $store_hash = md5( $this->api_url );
        
        if ( ! is_array( $edd_plugin_url_available ) || ! isset( $edd_plugin_url_available[ $store_hash ] ) ) {
            $test_url_parts = parse_url( $this->api_url );

            $scheme = ! empty( $test_url_parts['scheme'] ) ? $test_url_parts['scheme']     : 'http';
            $host   = ! empty( $test_url_parts['host'] )   ? $test_url_parts['host']       : '';
            $port   = ! empty( $test_url_parts['port'] )   ? ':' . $test_url_parts['port'] : '';

            if ( empty( $host ) ) {
                $edd_plugin_url_available[ $store_hash ] = false;
            } else {
                $test_url                                = $scheme . '://' . $host . $port;
                $response                                = wp_remote_get( $test_url, [ 'timeout' => $this->health_check_timeout, 'sslverify' => $verify_ssl ] );
                $edd_plugin_url_available[ $store_hash ] = is_wp_error( $response ) ? false : true;
            }
        }

        if ( false === $edd_plugin_url_available[ $store_hash ] ) {
            return null;
        }

        $data = array_merge( $this->api_data, $data );

        if ( $data['slug'] != $this->slug ) {
            return null;
        }

        if ( $this->api_url == trailingslashit( home_url() ) ) {
            return null; // Don't allow a plugin to ping itself
        }

        $api_params = [
            'edd_action' => 'get_version',
            'license'    => ! empty( $data['license'] ) ? $data['license'] : '',
            'item_name'  => isset( $data['item_name'] ) ? $data['item_name'] : false,
            'item_id'    => isset( $data['item_id'] ) ? $data['item_id'] : false,
            'version'    => isset( $data['version'] ) ? $data['version'] : false,
            'slug'       => $data['slug'],
            'author'     => $data['author'],
            'url'        => home_url(),
            'beta'       => ! empty( $data['beta'] ),
        ];

        $request = wp_remote_post( $this->api_url, [ 'timeout' => 15, 'sslverify' => $verify_ssl, 'body' => $api_params ] );

        if ( is_wp_error( $request ) ) {
            return  null;
        }

        $request = json_decode( wp_remote_retrieve_body( $request ) );

        if ( is_null( $request ) ) {
            return null;
        }

        if ( ! isset( $request->sections ) ) {
            return null;
        }

        $request->sections = maybe_unserialize( $request->sections );

        if ( ! empty( $request->sections ) ) {
            foreach ( $request->sections as $key => $section ) {
                $request->$key = (array) $section;
            }
        }

        if ( isset( $request->banners ) ) {
            $request->banners = maybe_unserialize( $request->banners );
        }

        if ( isset( $request->icons ) ) {
            $request->icons = maybe_unserialize( $request->icons );
        }

        return $request;
    }

    public function show_changelog(): void {
        global $edd_plugin_data;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_REQUEST['edd_sl_action'] ) || 'view_plugin_changelog' != $_REQUEST['edd_sl_action'] ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_REQUEST['plugin'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_REQUEST['slug'] ) ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html( __( 'You do not have permission to install plugin updates', 'directorist-analytics' ) ), esc_html( __( 'Error', 'directorist-analytics' ) ), [ 'response' => 403 ] );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $data = $edd_plugin_data[ sanitize_text_field( wp_unslash( $_REQUEST['slug'] ) ) ];
        $beta = ! empty( $data['beta'] ) ? true : false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cache_key    = md5( 'edd_plugin_' . sanitize_key( $_REQUEST['plugin'] ) . '_' . $beta . '_version_info' );
        $version_info = $this->get_cached_version_info( $cache_key );

        if ( false === $version_info ) {
            $api_params = [
                'edd_action' => 'get_version',
                'item_name'  => isset( $data['item_name'] ) ? $data['item_name'] : false,
                'item_id'    => isset( $data['item_id'] ) ? $data['item_id'] : false,
                'slug'       => sanitize_text_field( wp_unslash( $_REQUEST['slug'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                'author'     => $data['author'],
                'url'        => home_url(),
                'beta'       => ! empty( $data['beta'] )
            ];

            $verify_ssl = $this->verify_ssl();
            $request    = wp_remote_post( $this->api_url, [ 'timeout' => 15, 'sslverify' => $verify_ssl, 'body' => $api_params ] );

            if ( ! is_wp_error( $request ) ) {
                $version_info = json_decode( wp_remote_retrieve_body( $request ) );
            }


            if ( ! empty( $version_info ) && isset( $version_info->sections ) ) {
                $version_info->sections = maybe_unserialize( $version_info->sections );
            } else {
                $version_info = false;
            }

            if ( ! empty( $version_info ) ) {
                foreach ( $version_info->sections as $key => $section ) {
                    $version_info->$key = (array) $section;
                }
            }

            $this->set_version_info_cache( $version_info, $cache_key );
        }

        if ( ! empty( $version_info ) && isset( $version_info->sections['changelog'] ) ) {
            echo '<div style="background:#fff;padding:10px;">' . wp_kses_post( $version_info->sections['changelog'] ) . '</div>';
        }

        exit;
    }

    public function get_cached_version_info( $cache_key = '' ) {
        if ( empty( $cache_key ) ) {
            $cache_key = $this->cache_key;
        }

        $cache = get_option( $cache_key );

        if ( empty( $cache['timeout'] ) || time() > $cache['timeout'] ) {
            return false; // Cache is expired
        }

        // We need to turn the icons into an array, thanks to WP Core forcing these into an object at some point.
        $cache['value'] = json_decode( $cache['value'] );
        
        if ( ! empty( $cache['value']->icons ) ) {
            $cache['value']->icons = (array) $cache['value']->icons;
        }

        return $cache['value'];
    }

    public function set_version_info_cache( $value = '', $cache_key = '' ) {
        if ( empty( $cache_key ) ) {
            $cache_key = $this->cache_key;
        }

        $data = [
            'timeout' => strtotime( '+3 hours', time() ),
            'value'   => json_encode( $value )
        ];

        update_option( $cache_key, $data, 'no' );
    }

    /**
     * Returns if the SSL of the store should be verified.
     *
     * @since  1.6.13
     * @return bool
     */
    private function verify_ssl() {
        return (bool) apply_filters( 'edd_sl_api_request_verify_ssl', true, $this );
    }
}