<?php
/**
 * Plugin Name:       DoubleScale | Self-Hosted CRM – Sales, Marketing, Booking, Helpdesk, Automation, MCP & More
 * Plugin URI:        https://www.doublescale.io/
 * Description:       Self-hosted CRM with sales, marketing, booking, helpdesk, tasks & projects, automations plus a built-in MCP server for AI clients. One plugin.
 * Version:           1.3.21
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
