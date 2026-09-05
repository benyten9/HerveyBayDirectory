<?php
/**
 * Load DoubleScale Pro Composer autoload for PHPUnit tests that exercise Pro REST/PDF classes.
 *
 * @package DoubleScale\Tests
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register Pro plugin constants and Composer autoload once per process.
 */
function doublescale_phpunit_ensure_pro_autoload(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}

	$free_root = defined( 'DOUBLESCALE_PLUGIN_DIR' )
		? rtrim( (string) DOUBLESCALE_PLUGIN_DIR, '/\\' )
		: dirname( __DIR__ );
	$pro_root  = dirname( $free_root ) . '/doublescale-pro';

	if ( ! is_dir( $pro_root ) ) {
		return;
	}

	if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
		define( 'DOUBLESCALE_PRO_PLUGIN_DIR', rtrim( $pro_root, '/\\' ) . '/' );
	}
	if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
		define( 'DOUBLESCALE_PRO_PLUGIN_URL', 'http://example.test/wp-content/plugins/doublescale-pro/' );
	}
	if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_PATH' ) ) {
		define( 'DOUBLESCALE_PRO_PLUGIN_PATH', 'doublescale-pro/doublescale-pro.php' );
	}
	if ( ! defined( 'DOUBLESCALE_PRO_VERSION' ) ) {
		define( 'DOUBLESCALE_PRO_VERSION', '0.0.0-phpunit' );
	}

	$pro_autoload = $pro_root . '/vendor/autoload.php';
	if ( is_file( $pro_autoload ) ) {
		require_once $pro_autoload;
	}

	$loaded = true;
}
