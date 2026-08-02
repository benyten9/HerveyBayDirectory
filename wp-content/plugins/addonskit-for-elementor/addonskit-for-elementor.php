<?php
/**
 * Plugin Name: Directorist AddonsKit for Elementor
 * Description: Complete Elementor Widgets for Directorist.
 * Author: wpWax
 * Author URI: https://wpwax.com
 * Version: 1.3.0
 * Requires Plugins: directorist, elementor
 * Elementor tested up to: 3.30
 * License: GPL2
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Text Domain: addonskit-for-elementor
 * Domain Path: /languages
 *
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 *
 * This is an add-on for WordPress
 * http://wordpress.org/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 * **********************************************************************
 */

defined( 'ABSPATH' ) || exit;

final class AddonskitForElementor {
	private $version = '1.3.0';

	private $min_php = '7.4';

	private static $instance;

	public $app;

	public static function init() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof AddonskitForElementor ) ) {
			self::$instance = new AddonskitForElementor();
			self::$instance->setup();
		}

		return self::$instance;
	}

	private function setup() {
		register_activation_hook( __FILE__, [$this, 'auto_deactivate'] );

		if ( ! $this->is_supported_php() ) {
			return;
		}

		if ( ! $this->is_directorist_active() ) {
			add_action( 'admin_notices', [$this, 'directorist_required_notice'] );
			return;
		}

		if ( ! defined( 'ATBDP_VERSION' ) || ATBDP_VERSION < '8.0.0' ) {
			add_action( 'admin_notices', [$this, 'directorist_required_version_notice'] );
			return;
		}

		$this->define_constants();
		$this->includes();
		$this->app = \AddonskitForElementor\App::initialize();

		do_action( 'addonskit_for_elementor_loaded' );
	}

	public function is_directorist_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'directorist/directorist-base.php' );
	}

	public function directorist_required_notice() {
		$message = sprintf(
			// translators: %1$s: opening strong tag, %2$s: closing strong tag
			esc_html__('%1$sDirectorist Addons Kit for Elementor%2$s requires %1$sDirectorist%2$s plugin to be installed and activated.', 'addonskit-for-elementor'),
			'<strong>',
			'</strong>'
		);

		printf(
			'<div class="error"><p>%s</p></div>',
			wp_kses($message, [
				'strong' => []
			])
		);
	}

	public function directorist_required_version_notice() {
		$message = sprintf(
			// translators: %1$s: opening strong tag, %2$s: closing strong tag
			esc_html__('The current version of your&nbsp;%1$sDirectorist is not compatible with Directorist Addons Kit for Elementor%2$s. To ensure compatibility and access new features,&nbsp;%1$s update Directorist to version 8.0 or later%2$s.', 'addonskit-for-elementor'),
			'<strong>',
			'</strong>'
		);

		printf(
			'<div class="error"><p>%s</p></div>',
			wp_kses($message, [
				'strong' => [],
				'nbsp' => []
			])
		);
	}

	public function is_supported_php(): bool {
		if ( version_compare( PHP_VERSION, $this->min_php, '<' ) ) {
			return false;
		}

		return true;
	}

	public function auto_deactivate(): void {
		if ( $this->is_supported_php() ) {
			return;
		}

		deactivate_plugins( basename( __FILE__ ) );

		$error = '<h1>' . esc_html__( 'An Error Occurred', 'addonskit-for-elementor' ) . '</h1>';
		
		// translators: %s: PHP version number
		$error .= sprintf(
			'%s%s%s',
			esc_html__( '<h2>Your installed PHP Version is: ', 'addonskit-for-elementor' ),
			esc_html(PHP_VERSION),
			'</h2>'
		);

		$error .= sprintf(
			// translators: %s: minimum required PHP version
			'<p>' . esc_html__( 'The Addonskit For Elementor plugin requires PHP version %s or greater.', 'addonskit-for-elementor' ) . '</p>',
			'<strong>' . esc_html( $this->min_php ) . '</strong>'
		);

		$error .= sprintf(
			'<p>' .
			// translators: %s: link to PHP supported versions
			esc_html__( 'The version of your PHP is %s. You should update your PHP software or contact your host regarding this matter.', 'addonskit-for-elementor' ) .
			'</p>',
			'<a href="http://php.net/supported-versions.php" target="_blank"><strong>' .
			esc_html__( 'unsupported and old', 'addonskit-for-elementor' ) .
			'</strong></a>'
		);

		wp_die(
			wp_kses_post($error),
			esc_html__( 'Plugin Activation Error', 'addonskit-for-elementor' ),
			[
				'response'  => 200,
				'back_link' => true,
			]
		);
	}

	public function is_plugin_installed( $basename ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();

		return isset( $installed_plugins[$basename] );
	}

	/**
	 * Define the constants
	 *
	 * @return void
	 */
	private function define_constants(): void {
		define( 'AKFE_VERSION', $this->version );
		define( 'AKFE_FILE', __FILE__ );
		define( 'AKFE_PATH', dirname( AKFE_FILE ) );
		define( 'AKFE_ELEMENTS', AKFE_PATH . 'app/Elements' );
		define( 'AKFE_URL', plugins_url( '', AKFE_FILE ) );
		define( 'AKFE_ASSETS', AKFE_URL . '/assets' );
	}

	/**
	 * Include the required files
	 *
	 * @return void
	 */
	private function includes() {
		include __DIR__ . '/vendor/autoload.php';
	}
}

/**
 * Init the AddonskitForElementor plugin
 *
 * @return AddonskitForElementor the plugin object
 */
function AddonskitForElementor(): void {
	add_action( 'plugins_loaded', ['AddonskitForElementor', 'init'] );
}

// kick it off
AddonskitForElementor();
