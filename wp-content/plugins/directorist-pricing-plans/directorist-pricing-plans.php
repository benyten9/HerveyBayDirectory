<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\Database\Setup;
use DirectoristPricingPlan\WpMVC\App;

/**
 * Plugin Name:       Directorist - Pricing Plans
 * Description:       Allow you to monetize your directory by creating and selling unlimited subscription plans.
 * Version:           4.0.1
 * Requires Plugins:  directorist
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      6.2
 * Author:            wpWax
 * Author URI:        https://wpwax.com
 * Plugin URI:        https://directorist.com/product/directorist-pricing-plans
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * License:           GPL v3 or later
 * Text Domain:       directorist-pricing-plans
 * Domain Path:       /languages
 */

require_once __DIR__ . '/vendor/vendor-src/autoload.php';
require_once __DIR__ . '/app/Helpers/helper.php';
require_once __DIR__ . '/app/Helpers/old-helpers.php';
require_once __DIR__ . '/app/Helpers/EDDPluginUpdaterPricingPlan.php';

final class DirectoristPricingPlan {
    public static DirectoristPricingPlan $instance;

    public static function instance(): DirectoristPricingPlan {
        if ( empty( self::$instance ) ) {
            self::$instance = new self;
            self::$instance->setup_constants();
        }
        return self::$instance;
    }

    public function load() {
        register_activation_hook(
            __FILE__, function() {
                $setup = new Setup();
                $setup->execute();
                $setup->maybe_create_default_plans_for_fresh_install();

                $executed_migrations = get_option( 'directorist_pricing_plans_migrations', [] );

                if ( ! is_array( $executed_migrations ) ) {
                    $executed_migrations = [];
                }

                if ( ! in_array( 'v4-migration', $executed_migrations, true ) ) {
                    $executed_migrations[] = 'v4-migration';
                    update_option( 'directorist_pricing_plans_migrations', $executed_migrations, false );
                }
            }
        );

        $application = App::instance();

        $application->boot( __FILE__, __DIR__ );

        /**
         * Fires once activated plugins have loaded.
         *
         */
        add_action(
            'plugins_loaded', function () use ( $application ): void {
                // Ensure main Directorist plugin is loaded
                if ( ! class_exists( 'Directorist_Base' ) ) {
                    return;
                }

                // Ensure minimum required Directorist version
                $required_version = '8.8.0';
                $current_version  = defined( 'ATBDP_VERSION' ) ? ATBDP_VERSION : '0';

                if ( version_compare( $current_version, $required_version, '<' ) ) {
                    add_action(
                        'admin_notices', function () use ( $required_version, $current_version ): void {
                            printf(
                                '<div class="notice notice-error"><p>%s</p></div>',
                                wp_kses_post(
                                    sprintf(
                                        /* translators: 1: required version, 2: current version */
                                        __( '<strong>Directorist Pricing Plans</strong> requires Directorist version <strong>%1$s</strong> or higher. You are running version <strong>%2$s</strong>. Please update Directorist to use this plugin.', 'directorist-pricing-plans' ),
                                        esc_html( $required_version ),
                                        esc_html( $current_version )
                                    )
                                )
                            );
                        }
                    );
                    return;
                }

                do_action( 'before_load_directorist_pricing_plans' );

                $application->load();

                do_action( 'after_load_directorist_pricing_plans' );
            }
        );
    }

    private function setup_constants() {
        if ( ! defined( 'DIRECTORIST_PRICING_PLANS_FILE' ) ) {
            define( 'DIRECTORIST_PRICING_PLANS_FILE', __FILE__ );
        }
    }
}

DirectoristPricingPlan::instance()->load();
