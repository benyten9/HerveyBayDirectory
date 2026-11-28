<?php
/**
 * Plugin Name: Directorist AI
 * Plugin URI: https://directorist.com/product/directorist-ai
 * Description: AI listing creation, directory imports, visitor chat and native layout assistance for Directorist.
 * Update URI: https://directorist.com/product/directorist-ai
 * Version: 1.0.1
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Requires Plugins: directorist
 * Author: wpWax
 * Author URI: https://directorist.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: directorist-ai
 * Domain Path: /languages
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \DirectoristAIPro\Compatibility\PluginIdentity::class, false ) ) {
	require_once __DIR__ . '/inc/compatibility/PluginIdentity.php';
}
$entry_file = defined( 'DIRECTORIST_AI_LEGACY_ENTRY' ) && dirname( DIRECTORIST_AI_LEGACY_ENTRY ) === __DIR__ ? DIRECTORIST_AI_LEGACY_ENTRY : __FILE__;
if ( ! \DirectoristAIPro\Compatibility\PluginIdentity::bootstrap( __FILE__, $entry_file ) ) {
	return;
}


define( 'DIRECTORIST_AI_PRO_FILE', $entry_file );
define( 'DIRECTORIST_AI_FILE', $entry_file );
define( 'DIRECTORIST_AI_PRO_VERSION', '1.0.1' );
define( 'DIRECTORIST_AI_VERSION', DIRECTORIST_AI_PRO_VERSION );

// Public product identity is pinned when packaging; site owners enter only a license.
$product_config = __DIR__ . '/product-config.php';
if ( file_exists( $product_config ) ) {
	require_once $product_config;
}

$scheduler = __DIR__ . '/vendor-action-scheduler/action-scheduler.php';
if ( ! file_exists( $scheduler ) ) {
	$scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}
if ( file_exists( $scheduler ) ) {
	require_once $scheduler;
}

if ( PHP_VERSION_ID < 80000 ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Directorist AI requires PHP 8.0 or later.', 'directorist-ai' ) . '</p></div>';
			}
		}
	);
	return;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Directorist AI dependencies are missing. Install a complete plugin build.', 'directorist-ai' ) . '</p></div>';
			}
		}
	);
	return;
}

require_once $autoload;

Installer::get_instance();

if ( did_action( 'plugins_loaded' ) ) {
	Core\Plugin::get_instance();
} else {
	add_action( 'plugins_loaded', array( Core\Plugin::class, 'get_instance' ), 30 );
}
