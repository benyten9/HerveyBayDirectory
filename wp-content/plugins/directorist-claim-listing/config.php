<?php
// Plugin version.
if ( ! defined( 'DCL_VERSION' ) ) {define( 'DCL_VERSION', dcl_get_version_from_file_content( DCL_FILE ) );}
// Plugin Folder Path.
if ( ! defined( 'DCL_DIR' ) ) { define( 'DCL_DIR', plugin_dir_path( DCL_FILE ) ); }
// Plugin Folder URL.
if ( ! defined( 'DCL_URL' ) ) { define( 'DCL_URL', plugin_dir_url( DCL_FILE ) ); }
// Plugin Root File.
if ( ! defined( 'DCL_BASE' ) ) { define( 'DCL_BASE', plugin_basename( DCL_FILE ) ); }
// Plugin Includes Path
if ( !defined('DCL_INC_DIR') ) { define('DCL_INC_DIR', DCL_DIR.'inc/'); }
// Plugin Assets Path
if ( !defined('DCL_ASSETS') ) { define('DCL_ASSETS', DCL_URL.'assets/'); }
// Plugin Template Path
if ( !defined('DCL_TEMPLATES_DIR') ) { define('DCL_TEMPLATES_DIR', DCL_DIR.'templates/'); }
// Plugin Language File Path
if ( !defined('DCL_LANG_DIR') ) { define('DCL_LANG_DIR', dirname(plugin_basename( DCL_FILE ) ) . '/languages'); }
// Plugin Name
if ( !defined('DCL_NAME') ) { define('DCL_NAME', 'Directorist - Pricing Plans'); }
// Plugin Post Type
if ( !defined('ATBDP_POST_TYPE') ) { define('ATBDP_POST_TYPE', 'at_biz_dir'); }

if ( !defined('ATBDP_PRICING_PLANS_POST_TYPE') ) { define('ATBDP_PRICING_PLANS_POST_TYPE', 'ATBDP_Pricing_Plans'); }

// plugin author url
if (!defined('ATBDP_AUTHOR_URL')) {
    define('ATBDP_AUTHOR_URL', 'https://directorist.com');
}
// post id from download post type (edd)
if (!defined('ATBDP_CLAIM_POST_ID')) {
    define('ATBDP_CLAIM_POST_ID', 13786 );
}