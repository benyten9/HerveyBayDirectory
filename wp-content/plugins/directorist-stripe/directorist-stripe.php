<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\App;

/**
 * Plugin Name:       Directorist - Stripe Payment Gateway
 * Description:       Accept payments securely using Stripe, ensuring a smooth checkout experience.
 * Version:           3.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      6.2
 * Author:            wpWax
 * Author URI:        https://wpwax.com
 * Plugin URI:        https://github.com/sovware/directorist-stripe
 * License:           GPL v3 or later
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       directorist-stripe
 * Domain Path:       /languages
 */

require_once __DIR__ . '/vendor/vendor-src/autoload.php';
require_once __DIR__ . '/app/Helpers/helper.php';
require_once __DIR__ . '/app/Helpers/EDDPluginUpdaterStripe.php';

final class DirectoristStripe {
    public static DirectoristStripe $instance;

    public static function instance(): DirectoristStripe {
        if ( empty( self::$instance ) ) {
            self::$instance = new self;
            self::$instance->setup_constants();
        }
        return self::$instance;
    }

    public function load() {
        $application = App::instance();

        $application->boot( __FILE__, __DIR__ );

        /**
         * Fires once activated plugins have loaded.
         *
         */
        add_action(
            'plugins_loaded', function () use ( $application ): void {

                do_action( 'before_load_directorist_stripe' );

                $application->load();

                do_action( 'after_load_directorist_stripe' );
            }
        );
    }

    private function setup_constants() {
        if ( ! defined( 'DIRECTORIST_STRIPE_FILE' ) ) {
            define( 'DIRECTORIST_STRIPE_FILE', __FILE__ );
        }
    }
}

DirectoristStripe::instance()->load();
