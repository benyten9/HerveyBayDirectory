<?php
/**
 * Plugin Name:       DoubleScale | Self-Hosted CRM & Business Platform (Alternative to HubSpot & GoHighLevel)
 * Plugin URI:        https://www.doublescale.io/
 * Description:       DoubleScale | Self-Hosted CRM & Business Platform (Alternative to HubSpot & GoHighLevel)
 * Version:           1.2.19
 * Author:            vixgrowy
 * Author URI:        https://www.vixgrow.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       doublescale
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * @package DoubleScale
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'DOUBLESCALE_FREE_PLUGIN_LOADED' ) ) {
	return;
}
define( 'DOUBLESCALE_FREE_PLUGIN_LOADED', true );

require_once __DIR__ . '/includes/Lifecycle.php';

\DoubleScale\Lifecycle::boot( __FILE__ );
