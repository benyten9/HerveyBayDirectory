<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\App;

/**
 * Plugin Name:       Directorist - Stripe Payment Gateway
 * Description:       Accept payments securely using Stripe, ensuring a smooth checkout experience.
 * Version:           3.0.1
 * Requires Plugins:  directorist
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
    private const MINIMUM_DIRECTORIST_VERSION = '8.8.0';

    public static DirectoristStripe $instance;

    public static function instance(): DirectoristStripe {
        if ( empty( self::$instance ) ) {
            self::$instance = new self;
            self::$instance->setup_constants();
        }
        return self::$instance;
    }

    public function load() {
        /**
         * Fires once activated plugins have loaded.
         *
         */
        add_action(
            'plugins_loaded', function (): void {
                // Ensure main Directorist plugin is loaded.
                if ( ! class_exists( 'Directorist_Base' ) ) {
                    $this->add_dependency_notice();
                    return;
                }

                // Ensure minimum required Directorist version.
                $current_version = defined( 'ATBDP_VERSION' ) ? ATBDP_VERSION : '0';

                if ( version_compare( $current_version, self::MINIMUM_DIRECTORIST_VERSION, '<' ) || ! $this->has_required_directorist_api() ) {
                    $this->add_dependency_notice( $current_version );
                    return;
                }

                $application = App::instance();
                $application->boot( __FILE__, __DIR__ );

                do_action( 'before_load_directorist_stripe' );

                $application->load();

                do_action( 'after_load_directorist_stripe' );
            }
        );
    }

    private function has_required_directorist_api(): bool {
        return class_exists( 'Directorist\\PaymentProcessors\\Payment' )
            && class_exists( 'Directorist\\Repositories\\PaymentRepository' )
            && class_exists( 'Directorist\\DTO\\Order\\DTO' )
            && interface_exists( 'Directorist\\Contracts\\PaymentInterface' );
    }

    private function add_dependency_notice( string $current_version = '0' ): void {
        add_action(
            'admin_notices', function () use ( $current_version ): void {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    wp_kses_post(
                        sprintf(
                            /* translators: 1: required version, 2: current version */
                            __( '<strong>Directorist Stripe Payment Gateway</strong> requires a complete installation of Directorist version <strong>%1$s</strong> or higher. The detected version is <strong>%2$s</strong>. Please update or reinstall Directorist to use this plugin.', 'directorist-stripe' ),
                            esc_html( self::MINIMUM_DIRECTORIST_VERSION ),
                            esc_html( $current_version )
                        )
                    )
                );
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
