<?php

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\WpMVC\App;
use DirectoristAnalytics\Database\Setup;
use DirectoristAnalytics\App\Helpers\EddUpdaterAnalytics;

/**
 * Plugin Name:       Directorist Analytics
 * Description:       This plugin is used to track the analytics of the Directorist plugin.
 * Version:           1.0.1
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Tested up to:      6.8
 * Author:            wpWax
 * Author URI:        https://wpwax.com
 * License:           GPL v3 or later
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       directorist-advanced-review
 * Domain Path:       /languages
 * Requires Plugins:  directorist
 */

require_once __DIR__ . '/vendor/vendor-src/autoload.php';
require_once __DIR__ . '/app/Helpers/helper.php';
require_once __DIR__ . '/app/Helpers/edd-updater.php';
require_once __DIR__ . '/app/MCP/bootstrap.php';

final class DirectoristAnalytics
{
    public static DirectoristAnalytics $instance;

    public static function instance(): DirectoristAnalytics {
        if ( empty( self::$instance ) ) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function load() {
        // Run Activation Tasks
        register_activation_hook(
            __FILE__, function() {
                ( new Setup )->execute();
            } 
        );

        $application = App::instance();

        $application->boot( __FILE__, __DIR__ );

        if ( is_admin() ) {
            add_action( 'init', [ $this, 'update' ] );
        }

        /**
         * Fires once activated plugins have loaded.
         *
         */
        add_action(
            'plugins_loaded', function () use ( $application ): void {

                do_action( 'directorist_analytics_before_load' );

                $application->load();

                do_action( 'directorist_analytics_after_load' );
            }
        );
    }

    public function update(): void {

        $subscription_data = get_user_meta( get_current_user_id(), '_plugins_available_in_subscriptions', true );
        $license_key       = is_array( $subscription_data ) ? ( $subscription_data['directorist-analytics']['license'] ?? '' ) : '';
        
        $api_data = [
            'version' => directorist_analytics_version(),
            'license' => sanitize_text_field( $license_key ),
            'item_id' => 369611,
            'author'  => 'wpwax',
            'url'     => home_url(),
            'beta'    => false,
        ];
        try {
            new EddUpdaterAnalytics( 'https://directorist.com/', __FILE__, $api_data );
        } catch ( \Exception $e ) {
            error_log( 'Directorist Analytics: Update controller initialization failed - ' . $e->getMessage() );
        }
    }
}

DirectoristAnalytics::instance()->load();
