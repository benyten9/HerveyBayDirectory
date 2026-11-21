<?php
/*
Plugin Name: Perfmatters MU
Plugin URI: https://perfmatters.io/
Description: Perfmatters is a lightweight performance plugin developed to speed up your WordPress site.
Version: 2.7.1
Author: forgemedia
Author URI: https://forgemedia.io/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: perfmatters
Domain Path: /languages
*/

if(!defined('ABSPATH')) {
	exit;
}

add_filter('option_active_plugins', 'perfmatters_mu_disable_plugins', 1);
add_filter('pre_update_option_active_plugins', 'perfmatters_mu_protect_write', PHP_INT_MAX);
// Stays for the whole request. Rewrite flushes are saved on wp_loaded, after plugins_loaded.
add_filter('pre_update_option_rewrite_rules', 'perfmatters_mu_protect_rewrite_rules', PHP_INT_MAX, 2);

function perfmatters_mu_disable_plugins($plugins) {

    // Another mu-plugin can read active_plugins while mu-plugins are still loading,
    // before vars.php. Don't filter or cache that read; the real plugin load comes later.
    if(!function_exists('wp_is_mobile')) {
        return $plugins;
    }

    //admin check
    if(is_admin()) {
        return $plugins;
    }

    //only filter GET requests
    if((!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') && !isset($_GET['perfmatters'])) {
        return $plugins;
    }

    //dont filter if its a rest or ajax request
    if((defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI) || (function_exists('wp_is_json_request') && wp_is_json_request() && !pmsm_mu_prefer_html_request()) || wp_doing_ajax() || wp_doing_cron()) {
        return $plugins;
    }

    //manual wp-json check
    if(stripos(trailingslashit($_SERVER['REQUEST_URI']), '/wp-json/') !== false) {
        return $plugins;
    }

    //manual ajax check
    if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        return $plugins;
    }

    //base plugin active check
    if(is_array($plugins) && !in_array('perfmatters/perfmatters.php', $plugins)) {
        return $plugins;
    }

    //page builder check
    if(pmsm_mu_is_page_builder()) {
        return $plugins;
    }

    //make sure script manager is enabled
    $perfmatters_tools = get_option('perfmatters_tools');
    if(empty($perfmatters_tools['script_manager'])) {
        return $plugins;
    }

    //make sure MU is enabled
    $pmsm_settings = get_option('perfmatters_script_manager_settings');
    if(empty($pmsm_settings['mu_mode'])) {
        return $plugins;
    }

    //wp login check
    $perfmatters_options = get_option('perfmatters_options');
    if((!empty($GLOBALS['pagenow']) && $GLOBALS['pagenow'] == 'wp-login.php') || (!empty($perfmatters_options['login_url']) && !empty($GLOBALS['_SERVER']['REQUEST_URI']) && trim($GLOBALS['_SERVER']['REQUEST_URI'], '/') == $perfmatters_options['login_url'])) {
        return $plugins;
    }

    //script manager is being viewed
    if(isset($_GET['perfmatters'])) {

        //store active plugins for script manager UI in case they get disabled completely
        global $pmsm_active_plugins;
        $pmsm_active_plugins = $plugins;

        //don't filter plugins if script manager is up
        return $plugins;
    }

    if(isset($_GET['perfmattersoff']) || !empty($perfmatters_tools['safe_mode'])) {
        return $plugins;
    }

    //testing mode check (cookie-based — do not load pluggable early)
    if(!empty($pmsm_settings['testing_mode']) && !perfmatters_mu_current_user_can_manage_options()) {
        return $plugins;
    }

    //check for manual override
    if(!empty($_GET['mu_mode']) && $_GET['mu_mode'] == 'off') {
        return $plugins;
    }

    //make sure mu hasn't run already
    global $mu_run_flag;
    global $mu_plugins;
    if($mu_run_flag) {
        return $mu_plugins;
    }

    //get script manager configuration
    $pmsm = get_option('perfmatters_script_manager');

    //we have some plugins that are disabled
    if(!empty($pmsm['disabled']['plugins'])) {

        // Rebuilding rewrite_rules while plugins are missing drops their routes, and
        // WordPress keeps that set until the next full flush. Load everyone when the
        // stored rules are missing so the rebuild includes every plugin.
        // Plain permalinks never store rules (rewrite_rules() returns an empty array
        // when permalink_structure is empty), so that is not a rebuild.
        $rewrite_rules = get_option('rewrite_rules');
        if(empty($rewrite_rules) && get_option('permalink_structure')) {
            $mu_run_flag = true;
            $mu_plugins = $plugins;
            return $plugins;
        }

        //track plugins removed for this request so a write cannot persist the filtered list
        global $pmsm_hidden_plugins;
        $pmsm_hidden_plugins = array();

        //attempt to get post id
        $currentID = perfmatters_mu_get_current_ID();

        //assign disable/enable arrays
        $disabled = $pmsm['disabled']['plugins'];
        $enabled = !empty($pmsm['enabled']['plugins']) ? $pmsm['enabled']['plugins'] : '';

        //loop through disabled plugins
        foreach($disabled as $handle => $data) {

            //current plugin disable is set
            if(!empty($data['everywhere']) 
                || (!empty($data['current']) && in_array($currentID, $data['current'])) 
                || pmsm_mu_check_post_types($data, $currentID) 
                || pmsm_mu_check_user_status($data) 
                || pmsm_mu_check_device_type($data) 
                || (!empty($data['regex']) && preg_match($data['regex'], home_url(add_query_arg(array(), $_SERVER['REQUEST_URI']))))
            ) {

                if(!empty($enabled[$handle])) {

                    //enable current url check
                    if(!empty($enabled[$handle]['current']) && in_array($currentID, $enabled[$handle]['current'])) {
                        continue;
                    }

                    //user status check
                    if(pmsm_mu_check_user_status($enabled[$handle])) {
                        continue;
                    }

                    //device type check
                    if(pmsm_mu_check_device_type($enabled[$handle])) {
                        continue;
                    }

                    //enable regex
                    if(!empty($enabled[$handle]['regex'])) {
                        if(preg_match($enabled[$handle]['regex'], home_url(add_query_arg(array(), $_SERVER['REQUEST_URI'])))) {
                            continue;
                        }
                    }

                    //home page as post type
                    if(pmsm_mu_check_post_types($enabled[$handle], $currentID)) {
                        continue;
                    }
                }

                //remove plugin from list
                // Match the directory (woocommerce/), not a longer folder that shares the prefix
                // (woocommerce-memberships/). Single-file plugins are stored as the file name.
                $handle_quoted = preg_quote($handle, '/');
                if(str_ends_with($handle, '.php')) {
                    $plugin_pattern = '/^' . $handle_quoted . '$/';
                }
                else {
                    $plugin_pattern = '/^' . $handle_quoted . '\//';
                }
                $m_array = preg_grep($plugin_pattern, $plugins);
                if(!empty($m_array) && is_array($m_array)) {
                    $remove_key = key($m_array);
                    if(isset($plugins[$remove_key])) {
                        $pmsm_hidden_plugins[] = $plugins[$remove_key];
                        unset($plugins[$remove_key]);
                    }
                }
            }
        }
    }

    $mu_run_flag = true;
    $mu_plugins = $plugins;

    return $plugins;
}

/**
 * Prevent filtered active_plugins lists from being written to the database.
 *
 * Plugins like Freemius call get_option( 'active_plugins' ) at include time
 * (while our read filter is still active), reorder the list, and update_option()
 * the result. Without this guard, MU-hidden plugins are permanently deactivated.
 */
function perfmatters_mu_protect_write($value) {
    global $pmsm_hidden_plugins;

    if(!is_array($value) || empty($pmsm_hidden_plugins) || !is_array($pmsm_hidden_plugins)) {
        return $value;
    }

    // Nothing on the front end can legitimately deactivate a plugin we hid from
    // the reader, so put them back and keep the caller's ordering.
    return array_values(array_merge($value, array_diff($pmsm_hidden_plugins, $value)));
}

/**
 * Refuse to save rewrite rules built while plugins are hidden.
 *
 * Returning the previous value makes update_option() no-op, so a flush on a
 * filtered request cannot replace a complete set. This filter is not removed
 * on plugins_loaded: WooCommerce and core both write the option on wp_loaded.
 */
function perfmatters_mu_protect_rewrite_rules($value, $old_value) {
    global $pmsm_hidden_plugins;

    if(empty($pmsm_hidden_plugins) || !is_array($pmsm_hidden_plugins)) {
        return $value;
    }

    return $old_value;
}

//remove our filters after plugins have loaded
function perfmatters_mu_remove_filters() {
    remove_filter('option_active_plugins', 'perfmatters_mu_disable_plugins', 1, 1);
    remove_filter('pre_update_option_active_plugins', 'perfmatters_mu_protect_write', PHP_INT_MAX);
}
add_action('plugins_loaded', 'perfmatters_mu_remove_filters', 1);

//attempt to get the current id for mu mode
function perfmatters_mu_get_current_ID() {

    //load necessary parts for url_to_postid
    global $wp_rewrite;
    global $wp;
    $wp_rewrite = new WP_Rewrite();
    $wp = new WP();
    
    //attempt to get post id from url
    $currentID = (int) perfmatters_url_to_postid(home_url($_SERVER['REQUEST_URI']));

    //id wasn't found
    if($currentID === 0) {

        //check for home url match
        $request = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

        // Search, feeds, and other query vars share the front path but are not the front page.
        if(trailingslashit(home_url()) !== trailingslashit($request) || perfmatters_mu_request_overrides_front()) {
            $shop_id = perfmatters_mu_shop_page_id_for_request();
            $currentID = $shop_id ? $shop_id : '';
        }
    }

    //clean up
    unset($wp_rewrite);
    unset($wp);

    return $currentID;
}

/**
 * Statuses a Current URL rule can target.
 *
 * Private posts and pages have permalinks, and the Script Manager offers
 * Current URL on them. A publish-only lookup never resolves that ID.
 */
function perfmatters_mu_post_statuses() {
    return array('publish', 'private');
}

/**
 * Resolve a slug to an ID, preferring a published post over a private one.
 *
 * Publish is tried first so a public page keeps its ID when a private post
 * in another type reuses the slug. Private is only used when nothing public matches.
 */
function perfmatters_mu_post_id_by_name($name, $post_type) {
    if(!is_string($name) || $name === '') {
        return 0;
    }

    foreach(perfmatters_mu_post_statuses() as $status) {
        $posts = get_posts(array(
            'name'             => $name,
            'post_type'        => $post_type,
            'post_status'      => $status,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ));

        if(!empty($posts)) {
            return (int) $posts[0];
        }
    }

    return 0;
}

/**
 * Whether this front-page path is actually another view, such as search or a feed.
 *
 * Uses WP::$public_query_vars so the list stays with core. Tracking args are not
 * in that list, so they keep the front page rules. Pagination stays on the front
 * page too. p, page_id, and attachment_id are resolved earlier.
 */
function perfmatters_mu_request_overrides_front() {
    global $wp;

    $public_query_vars = ($wp instanceof WP) ? $wp->public_query_vars : (new WP())->public_query_vars;
    $ignored = array('paged', 'page', 'p', 'page_id', 'attachment_id');

    foreach($public_query_vars as $var) {
        if(in_array($var, $ignored, true)) {
            continue;
        }
        if(isset($_GET[$var]) && $_GET[$var] !== '' && $_GET[$var] !== array()) {
            return true;
        }
    }

    return false;
}

/**
 * Path of the current request relative to the site, without the query string.
 */
function perfmatters_mu_request_path() {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $uri = explode('?', $uri, 2)[0];
    $uri = trim($uri, '/');

    $home_path = parse_url(home_url(), PHP_URL_PATH);
    if(is_string($home_path)) {
        $home_path = trim($home_path, '/');
        if($home_path !== '' && ($uri === $home_path || str_starts_with($uri, $home_path . '/'))) {
            $uri = trim(substr($uri, strlen($home_path)), '/');
        }
    }

    return $uri;
}

/**
 * Shop page ID when this request is the WooCommerce shop, including /page/2/.
 *
 * The shop permalink is a product archive, so url_to_postid() returns nothing.
 * The Script Manager still offers Current URL with woocommerce_shop_page_id on
 * paginated shop URLs, because is_shop() is true for the whole archive.
 * Read the option directly because WooCommerce itself may be disabled.
 */
function perfmatters_mu_shop_page_id_for_request() {
    $shop_id = (int) get_option('woocommerce_shop_page_id');
    if($shop_id <= 0) {
        return 0;
    }

    $shop = get_post($shop_id);
    if(!$shop instanceof WP_Post) {
        return 0;
    }

    $shop_path = trim((string) get_page_uri($shop), '/');
    if($shop_path === '') {
        return 0;
    }

    $request_path = perfmatters_mu_request_path();
    if($request_path === $shop_path) {
        return $shop_id;
    }

    // Paginated shop: /shop/page/2/. A child page such as /shop/about does not match.
    global $wp_rewrite;
    $pagination_base = ($wp_rewrite instanceof WP_Rewrite && is_string($wp_rewrite->pagination_base) && $wp_rewrite->pagination_base !== '') ? $wp_rewrite->pagination_base : 'page';
    $paged_pattern = '#^' . preg_quote($shop_path, '#') . '/' . preg_quote($pagination_base, '#') . '/[0-9]+$#';
    if(preg_match($paged_pattern, $request_path)) {
        return $shop_id;
    }

    return 0;
}

//check if current post type is set in option
function pmsm_mu_check_post_types($option, $currentID = '') {
    if($currentID === 0) {
        if(get_option('show_on_front') == 'page' && !empty($option['post_types']) && in_array('page', $option['post_types'])) {
            return true;
        }
    }
    elseif(!empty($currentID)) {
        
        //grab post details
        $post = get_post($currentID);

        //post type enable check
        if(!empty($post->post_type) && !empty($option['post_types']) && in_array($post->post_type, $option['post_types'])) {
            return true;
        }
    }
    return false;
}

//check if current user status is set
function pmsm_mu_check_user_status($option) {
    if(!empty($option['user_status'])) {
        $status = perfmatters_mu_is_user_logged_in();
        if(($status && $option['user_status'] == 'loggedin') || (!$status && $option['user_status'] == 'loggedout')) {
            return true;
        }
    }
    return false;
}

/**
 * Parse the logged-in auth cookie without loading pluggable.php.
 *
 * @return array{username: string, expiration: int, token: string, hmac: string}|false
 */
function perfmatters_mu_parse_logged_in_cookie() {
    if(!defined('LOGGED_IN_COOKIE')) {
        wp_cookie_constants();
    }

    if(empty($_COOKIE[LOGGED_IN_COOKIE]) || !is_string($_COOKIE[LOGGED_IN_COOKIE])) {
        return false;
    }

    $cookie_elements = explode('|', $_COOKIE[LOGGED_IN_COOKIE]);
    if(count($cookie_elements) !== 4) {
        return false;
    }

    return array(
        'username'   => $cookie_elements[0],
        'expiration' => (int) $cookie_elements[1],
        'token'      => $cookie_elements[2],
        'hmac'       => $cookie_elements[3],
    );
}

/**
 * Lightweight logged-in check for MU Mode (no pluggable.php).
 * Cookie presence + unexpired is enough for Script Manager user_status rules.
 */
function perfmatters_mu_is_user_logged_in() {
    static $logged_in = null;

    if($logged_in !== null) {
        return $logged_in;
    }

    $cookie = perfmatters_mu_parse_logged_in_cookie();
    if(!$cookie || $cookie['expiration'] < time()) {
        $logged_in = false;
        return $logged_in;
    }

    $logged_in = true;
    return $logged_in;
}

/**
 * Lightweight manage_options check for Testing Mode (no pluggable.php).
 * Validates the logged-in cookie HMAC when salts are available, then checks caps.
 */
function perfmatters_mu_current_user_can_manage_options() {
    static $can_manage = null;

    if($can_manage !== null) {
        return $can_manage;
    }

    $can_manage = false;

    $cookie = perfmatters_mu_parse_logged_in_cookie();
    if(!$cookie || $cookie['expiration'] < time() || $cookie['username'] === '') {
        return $can_manage;
    }

    // WP_User::get_data_by avoids get_user_by() from pluggable.php.
    $userdata = WP_User::get_data_by('login', $cookie['username']);
    if(!$userdata) {
        return $can_manage;
    }

    // Validate cookie hash when logged-in salts are defined (normal wp-config).
    if(defined('LOGGED_IN_KEY') && LOGGED_IN_KEY && defined('LOGGED_IN_SALT') && LOGGED_IN_SALT) {
        if(str_starts_with($userdata->user_pass, '$P$') || str_starts_with($userdata->user_pass, '$2y$')) {
            $pass_frag = substr($userdata->user_pass, 8, 4);
        }
        else {
            $pass_frag = substr($userdata->user_pass, -4);
        }

        $salt = LOGGED_IN_KEY . LOGGED_IN_SALT;
        $key  = hash_hmac('md5', $cookie['username'] . '|' . $pass_frag . '|' . $cookie['expiration'] . '|' . $cookie['token'], $salt);
        $hash = hash_hmac('sha256', $cookie['username'] . '|' . $cookie['expiration'] . '|' . $cookie['token'], $key);

        if(!hash_equals($hash, $cookie['hmac'])) {
            return $can_manage;
        }
    }

    $user = new WP_User($userdata);
    $can_manage = $user->exists() && $user->has_cap('manage_options');

    return $can_manage;
}

//check if current device type is set
function pmsm_mu_check_device_type($option) {
    if(!empty($option['device_type'])) {
        $mobile = wp_is_mobile();
        if(($mobile && $option['device_type'] == 'mobile') || (!$mobile && $option['device_type'] == 'desktop')) {
            return true;
        }
    }
    return false;
}

//check if page builder is being used
function pmsm_mu_is_page_builder() {
    $page_builders = apply_filters('perfmatters_page_builders', array(
        'elementor-preview', //elementor
        'fl_builder', //beaver builder
        'et_fb', //divi
        'ct_builder', //oxygen
        'tve', //thrive
        'app', //flatsome
        'uxb_iframe',
        'fb-edit', //fusion builder
        'builder',
        'bricks', //bricks
        'brizy-edit', //brizy
        'brizy-edit-iframe',
        'is-editor-iframe',
        'vc_editable', //wp bakery
        'op3editor', //optimizepress
        'cs_preview_state' //cornerstone
    ));

    if(!empty($page_builders)) {
        foreach($page_builders as $page_builder) {
            if(isset($_REQUEST[$page_builder])) {
                return true;
            }
        }
    }

    return false;
}

//check if html/xhtml is the preferred request
function pmsm_mu_prefer_html_request() {

    //check accept header
    if(empty($_SERVER['HTTP_ACCEPT'])) {
        return false;
    }

    //get content types set in header
    $content_types = explode(',', $_SERVER['HTTP_ACCEPT']);
    $html_preference = 0;
    $xhtml_preference = 0;
    $highest_preference = 0;

    //loop through accepted types
    foreach($content_types as $type) {

        //split parts
        $type_parts = explode(';', trim($type));
        $mime_type = $type_parts[0];

        //default quality factor of 1 if not set
        $q = 1.0;
        if(isset($type_parts[1]) && strpos($type_parts[1], 'q=') === 0) {
            $q = floatval(substr($type_parts[1], 2));
        }

        //update highest preference
        if($q > $highest_preference) {
            $highest_preference = $q;
        }

        //check mime type
        if($mime_type === 'text/html') {
            $html_preference = $q;
        }
        elseif($mime_type === 'application/xhtml+xml') {
            $xhtml_preference = $q;
        }
    }

    // Return true if text/html or application/xhtml+xml has the highest preference
    return ($html_preference === $highest_preference || $xhtml_preference === $highest_preference);
}

//custom url_to_postid() replacement - modified from https://gist.github.com/Webcreations907/ce5b77565dfb9a208738
function perfmatters_url_to_postid($url) {

    // WordPress honors p, page_id, and attachment_id. `post` is an admin query
    // arg and is ignored on the front end, so matching it applied another URL's
    // rules to the permalink that was actually served.
    if ( preg_match( '#[?&](p|page_id|attachment_id)=(\d+)#', $url, $values ) ) {
        $id = absint( $values[2] );
        if ( $id ) {
            return $id;
        }
    }

    // Check to see if we are using rewrite rules
    $rewrite = get_option('rewrite_rules');
    global $wp_rewrite;
    //$rewrite = $wp_rewrite->wp_rewrite_rules();

    // Not using rewrite rules, and 'p=N' and 'page_id=N' methods failed, so we're out of options
    if ( empty( $rewrite ) ) {
        if ( isset( $_GET ) && ! empty( $_GET ) ) {

            /************************************************************************
            * ADDED: Trys checks URL for ?posttype=postname
            *************************************************************************/

            // Assign $url to $tempURL just incase. :)
            $tempUrl = $url;

            // Get rid of the #anchor
            $url_split = explode( '#', $tempUrl );
            $tempUrl = $url_split[0];

            // Get rid of URL ?query=string
            $url_query = explode( '&', $tempUrl );
            $tempUrl = $url_query[0];

            // Get rid of ? mark
            $url_query = explode( '?', $tempUrl);

            if(isset($url_query[1]) && !empty($url_query[1]) && strpos( $url_query[1], '=' )){

                $url_query = explode( '=', $url_query[1] );

                if(isset($url_query[0]) && isset($url_query[1])){

                     $args = array(
                        'name'        => $url_query[1],
                        'post_type'   => $url_query[0],
                        'post_status' => perfmatters_mu_post_statuses(),
                        'showposts'   => 1,
                    );
                    if ( $post = get_posts( $args ) ) {
                       return $post[0]->ID;
                    }
                }
            }

            /************************************************************************
            * END ADD
            *************************************************************************/
            
            // Add custom rules for non-rewrite URLs
            foreach ( $GLOBALS['wp_post_types'] as $key => $value ) {
                if ( isset( $_GET[ $key ] ) && ! empty( $_GET[ $key ] ) ) {
                    $args = array(
                        'name'        => $_GET[ $key ],
                        'post_type'   => $key,
                        'post_status' => perfmatters_mu_post_statuses(),
                        'showposts'   => 1,
                    );
                    if ( $post = get_posts( $args ) ) {
                        return $post[0]->ID;
                    }
                }
            }
        }
    }

    // Get rid of the #anchor
    $url_split = explode( '#', $url );
    $url       = $url_split[0];

    // Get rid of URL ?query=string
    $url_query = explode( '?', $url );
    $url       = $url_query[0];

    // Add 'www.' if it is absent and should be there
    if ( false !== strpos( home_url(), '://www.' ) && false === strpos( $url, '://www.' ) ) {
        $url = str_replace( '://', '://www.', $url );
    }

    // Strip 'www.' if it is present and shouldn't be
    if ( false === strpos( home_url(), '://www.' ) ) {
        $url = str_replace( '://www.', '://', $url );
    }

    $is_front_url = ( trim( $url, '/' ) === home_url() );

    // ?s= and ?feed= use the front path after the query string is stripped.
    // Return before rewrite matching so the empty-path front rule cannot claim them.
    if ( $is_front_url && perfmatters_mu_request_overrides_front() ) {
        return 0;
    }

    if ( $is_front_url && 'page' === get_option( 'show_on_front' ) ) {
        $page_on_front = get_option( 'page_on_front' );

        if ( $page_on_front && get_post( $page_on_front ) instanceof WP_Post ) {
            return (int) $page_on_front;
        }
    }

    // Strip 'index.php/' if we're not using path info permalinks
    if ( isset( $wp_rewrite ) && ! $wp_rewrite->using_index_permalinks() ) {
        $url = str_replace( 'index.php/', '', $url );
    }

    if ( false !== strpos( $url, home_url() ) ) {
        // Chop off http://domain.com
        $url = str_replace( home_url(), '', $url );
    } else {
        // Chop off /path/to/blog
        $home_path = parse_url( home_url() );
        $home_path = isset( $home_path['path'] ) ? $home_path['path'] : '';
        $url       = str_replace( $home_path, '', $url );
    }

    // Trim leading and lagging slashes
    $url = trim( $url, '/' );

    $request = $url;
    if ( empty( $request ) && ( ! isset( $_GET ) || empty( $_GET ) ) ) {
        return get_option( 'page_on_front' );
    }

    // Look for matches.
    $request_match = $request;

    foreach ( (array) $rewrite as $match => $query ) {

        // If the requesting file is the anchor of the match, prepend it
        // to the path info.
        if ( ! empty( $url ) && ( $url != $request ) && ( strpos( $match, $url ) === 0 ) ) {
            $request_match = $url . '/' . $request;
        }

        if ( preg_match( "#^$match#", $request_match, $matches ) ) {

            if ( $wp_rewrite->use_verbose_page_rules && preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch ) ) {
                // This is a verbose page match, let's check to be sure about it.
                $page = get_page_by_path( $matches[ $varmatch[1] ] );
                if ( ! $page ) {
                    continue;
                }
 
                $post_status_obj = get_post_status_object( $page->post_status );
                if (is_object($post_status_obj) && ! $post_status_obj->public && ! $post_status_obj->protected
                    && ! $post_status_obj->private && $post_status_obj->exclude_from_search ) {
                    continue;
                }
            }

            // Got a match.
            // Trim the query of everything up to the '?'.
            $query = preg_replace( "!^.+\?!", '', $query );

            // Substitute the substring matches into the query.
            $query = addslashes( WP_MatchesMapRegex::apply( $query, $matches ) );

            // Filter out non-public query vars
            global $wp;
            parse_str( $query, $query_vars );
            $query = array();
            foreach ( (array) $query_vars as $key => $value ) {
                // Skip empty mapped vars (ex: page= from optional rewrite groups).
                // Empty page alone makes WP_Query resolve to the front page.
                if ( $value === '' || $value === null ) {
                    continue;
                }
                if ( in_array( $key, $wp->public_query_vars ) ) {
                    $query[ $key ] = $value;
                }
            }

            /************************************************************************
            * ADDED: $GLOBALS['wp_post_types'] doesn't seem to have custom postypes
            * Trying below to find posttypes in $rewrite rules
            *************************************************************************/

            // PostType Array
            $custom_post_type = false;
            $post_types = array();
            foreach ($rewrite as $key => $value) {

                if(!is_string($value)) {
                    continue;
                }

                if(preg_match('/post_type=([^&]+)/i', $value, $matched)){

                    if(isset($matched[1]) && !in_array($matched[1], $post_types)){
                         
                        $post_types[] = $matched[1];
                    }
                }
            }

            foreach ((array) $query_vars as $key => $value) {
                if(in_array($key, $post_types)){

                    $custom_post_type = true;

                    $query['post_type'] = $key;
                    $query['postname'] = $value;
                }
            }

            /************************************************************************
            * END ADD
            *************************************************************************/

            // MU Mode runs before CPTs register, so custom query vars (ex: ht_kb)
            // are not in public_query_vars / wp_post_types yet. Default WP pattern
            // is query_var name === post type name — try those directly.
            $reserved_query_vars = array_flip( $wp->public_query_vars );
            foreach ( (array) $query_vars as $key => $value ) {
                if ( isset( $reserved_query_vars[ $key ] ) || ! is_string( $value ) || $value === '' ) {
                    continue;
                }

                $posts = get_posts( array(
                    'name'           => $value,
                    'post_type'      => $key,
                    'post_status'    => perfmatters_mu_post_statuses(),
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ) );

                if ( ! empty( $posts ) ) {
                    return (int) $posts[0];
                }
            }

            // Taken from class-wp.php
            $post_type_query_vars = array();
            if(!empty($GLOBALS['wp_post_types'])) {
                foreach ( $GLOBALS['wp_post_types'] as $post_type => $t ) {
                    if ( $t->query_var ) {
                        $post_type_query_vars[ $t->query_var ] = $post_type;
                    }
                }
            }

            foreach ( $wp->public_query_vars as $wpvar ) {
                if ( isset( $wp->extra_query_vars[ $wpvar ] ) ) {
                    $query[ $wpvar ] = $wp->extra_query_vars[ $wpvar ];
                } elseif ( isset( $_POST[ $wpvar ] ) ) {
                    $query[ $wpvar ] = $_POST[ $wpvar ];
                } elseif ( isset( $_GET[ $wpvar ] ) ) {
                    $query[ $wpvar ] = $_GET[ $wpvar ];
                } elseif ( ! empty( $query_vars[ $wpvar ] ) ) {
                    $query[ $wpvar ] = $query_vars[ $wpvar ];
                }


                if ( ! empty( $query[ $wpvar ] ) ) {
                    if ( ! is_array( $query[ $wpvar ] ) ) {
                        $query[ $wpvar ] = (string) $query[ $wpvar ];
                    } else {
                        foreach ( $query[ $wpvar ] as $vkey => $v ) {
                            if ( ! is_object( $v ) ) {
                                $query[ $wpvar ][ $vkey ] = (string) $v;
                            }
                        }
                    }

                    if ( isset( $post_type_query_vars[ $wpvar ] ) ) {
                        $query['post_type'] = $post_type_query_vars[ $wpvar ];
                        $query['name']      = $query[ $wpvar ];
                    }
                }
            }

            // Do the query
            if ( isset( $query['pagename'] ) && ! empty( $query['pagename'] ) ) {
                // post and page, so a custom /%postname%/ permalink still resolves.
                // Publish wins when a private post in the other type shares the slug.
                $post_id = perfmatters_mu_post_id_by_name( $query['pagename'], array( 'post', 'page' ) );
                if ( $post_id ) {
                    return $post_id;
                }
            }

            // Script Manager only needs a singular post/page ID. Archive matches
            // (author, date, tax, search, etc.) never provide one — and WP_Query
            // calls pluggable APIs for some of them (ex: get_user_by for author_name)
            // before pluggable.php has loaded.
            $singular_vars = array( 'p', 'page_id', 'attachment_id', 'name', 'pagename', 'postname' );
            $is_singular_query = false;
            foreach ( $singular_vars as $singular_var ) {
                if ( ! empty( $query[ $singular_var ] ) ) {
                    $is_singular_query = true;
                    break;
                }
            }
            if ( ! $is_singular_query ) {
                return 0;
            }

            // Whitelist only singular identity vars. Passing the full rewrite/GET
            // query can pull in author_name, s, preview, etc. — those call
            // pluggable APIs (get_user_by, is_user_logged_in, current_user_can)
            // before pluggable.php has loaded.
            $wp_query_args = array(
                'post_status'    => perfmatters_mu_post_statuses(),
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            );

            foreach ( array( 'p', 'page_id', 'attachment_id', 'name', 'pagename', 'post_type' ) as $var ) {
                if ( ! empty( $query[ $var ] ) ) {
                    $wp_query_args[ $var ] = $query[ $var ];
                }
            }

            if ( ! empty( $query['postname'] ) && empty( $wp_query_args['name'] ) ) {
                $wp_query_args['name'] = $query['postname'];
            }

            $query = new WP_Query( $wp_query_args );

            if ( ! empty( $query->posts ) && $query->is_singular ) {
                return $query->post->ID;
            } else {

                /************************************************************************
                * Added:
                * $query->is_singular seems to not be set on custom post types, trying
                * below. Made it this far without return, worth a try? :)
                *************************************************************************/
                
                if(!empty( $query->posts ) && isset($query->post->ID) && $custom_post_type == true){

                    return $query->post->ID;
                }
                
                /************************************************************************
                * Will match posttype when query_var => true, will not work with
                * custom query_var set ie query_var => 'acme_books'.
                *************************************************************************/

                if(isset($post_types)) {

                    foreach($rewrite as $key => $value) {

                        if(!is_string($value)) {
                            continue;
                        }

                        if(preg_match('/\?([^&]+)=([^&]+)/i', $value, $matched)) {

                            if(isset($matched[1]) && !in_array($matched[1], $post_types) && array_key_exists($matched[1], $query_vars)) {

                                $post_types[] = $matched[1];

                                $args = array(
                                    'name'        => $query_vars[$matched[1]],
                                    'post_type'   => $matched[1],
                                    'post_status' => perfmatters_mu_post_statuses(),
                                    'showposts'   => 1,
                                );

                                if($post = get_posts($args)) {
                                    return $post[0]->ID;
                                }
                            }
                            elseif(isset($matched[1]) && in_array($matched[1], $post_types) && !empty($query_vars['name'])) {

                                $args = array(
                                    'name'        => $query_vars['name'],
                                    'post_type'   => $matched[1],
                                    'post_status' => perfmatters_mu_post_statuses(),
                                    'showposts'   => 1,
                                );

                                if($post = get_posts($args)) {
                                    return $post[0]->ID;
                                }
                            }
                        }
                    }
                }

                /************************************************************************
                * END ADD
                *************************************************************************/

                return 0;
            }
        }
    }

    return 0;
}