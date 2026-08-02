<?php
/**
 * Class: Store
 *
 * Manages addon catalog, install and activate via AJAX.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Website;

defined( 'ABSPATH' ) || exit;

use Automatic_Upgrader_Skin;
use Plugin_Upgrader;

/**
 * Store class.
 *
 * @since 1.0.0
 */
class Store {

	/**
	 * Addon definitions.
	 *
	 * @var array
	 */
	private $addons = array();

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->define_addons();

		add_action( 'wp_ajax_doublescale_addon_install', array( $this, 'ajax_install' ) );
		add_action( 'wp_ajax_doublescale_addon_activate', array( $this, 'ajax_activate' ) );
	}

	/**
	 * Define the addon catalog.
	 *
	 * @return void
	 */
	private function define_addons() {
		$plugins_dir = trailingslashit( dirname( dirname( DOUBLESCALE_PLUGIN_FILE ) ) );

		$addons = array(
			// Always list Zapier so Integrations can show an AddonCard even when the
			// doublescale-zapier plugin is not activated (free or Pro without the addon).
			'zapier'        => array(
				'name'        => 'Zapier',
				'slug'        => 'zapier',
				'description' => __( 'Connect Plugin with Zapier to automate your CRM with 5000+ apps.', 'doublescale' ),
				'plugin_file' => file_exists( $plugins_dir . 'doublescale-zapier/doublescale-zapier.php' )
					? 'doublescale-zapier/doublescale-zapier.php'
					: ( file_exists( $plugins_dir . 'DoubleScale-Zapier/doublescale-zapier.php' )
						? 'DoubleScale-Zapier/doublescale-zapier.php'
						: 'DS-Zapier/ds-zapier.php' ),
				'image'       => 'zapier/zapier.svg',
				'plan'        => 'plus',
			),
			// 'make'   => array(
			// 'name'        => 'Make',
			// 'slug'        => 'make',
			// 'description' => __( 'Connect Plugin with Make (Integromat) to automate your CRM with 1500+ apps.', 'doublescale'),
			// 'plugin_file' => 'doublescale-make/doublescale-make.php',
			// 'image'       => 'make/make.svg',
			// 'plan'        => 'basic',
			// ),
			'white-label'   => array(
				'name'        => 'White Labeling',
				'slug'        => 'white-label',
				'description' => __( 'Remove Plugin branding and replace with your own plugin name, logo, menu icon, and brand colors.', 'doublescale' ),
				'plugin_file' => self::resolve_white_label_plugin_file( $plugins_dir ),
				'image'       => 'white-label/white-label.svg',
				'plan'        => 'enterprise',
			),
			'ai-assistant'  => array(
				'name'        => 'AI Assistant',
				'slug'        => 'ai-assistant',
				'description' => __( 'AI-powered CRM assistant with chat panel, tool calling, conversation history, and MCP tools for managing contacts, deals, campaigns, and more.', 'doublescale' ),
				'plugin_file' => self::resolve_ai_assistant_plugin_file( $plugins_dir ),
				'image'       => 'ai-assistant/ai-assistant.svg',
				'plan'        => 'plus',
			),
			'subscriptions' => array(
				'name'        => 'Subscriptions',
				'slug'        => 'subscriptions',
				'description' => __( 'Recurring Stripe billing — subscribe customers to a plan, auto-charge each cycle, and record a child invoice per charge.', 'doublescale' ),
				'plugin_file' => self::resolve_subscriptions_plugin_file( $plugins_dir ),
				'image'       => 'subscriptions/subscriptions.svg',
				'plan'        => 'enterprise',
			),
		);

		$this->addons = apply_filters( 'doublescale_store_addons', $addons );

		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $this->addons as $key => &$addon ) {
			$full_path                 = $plugins_dir . $addon['plugin_file'];
			$addon['full_plugin_file'] = $full_path;
			$addon['is_installed']     = file_exists( $full_path );
			$addon['is_active']        = is_plugin_active( $addon['plugin_file'] );
			$addon['version']          = $addon['is_installed'] ? ( get_plugin_data( $full_path, false, false )['Version'] ?? null ) : null;
		}
	}

	/**
	 * Get all addons with status.
	 *
	 * @return array
	 */
	public function get_all_addons() {
		return $this->addons;
	}

	/**
	 * Get a single addon definition.
	 *
	 * @param string $slug Addon slug.
	 * @return array|null
	 */
	public function get_addon( $slug ) {
		return $this->addons[ $slug ] ?? null;
	}

	/**
	 * AJAX: Install an addon.
	 *
	 * @return void
	 */
	public function ajax_install() {
		$this->check_authorization();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified inside $this->check_authorization() above.
		$addon_slug = isset( $_POST['addon'] ) ? sanitize_text_field( wp_unslash( $_POST['addon'] ) ) : '';
		$addon      = $this->get_addon( $addon_slug );
		if ( ! $addon ) {
			wp_send_json_error( esc_html__( 'Unknown addon', 'doublescale' ), 400 );
			exit;
		}

		if ( $addon['is_installed'] ) {
			wp_send_json_error( esc_html__( 'Addon is already installed', 'doublescale' ), 400 );
			exit;
		}

		// Check if addon has a direct download URL (free WordPress.org plugin).
		$download_link = $addon['download_url'] ?? null;

		// If no direct download URL, get it from license server (premium addon).
		if ( empty( $download_link ) ) {
			$license = get_option( 'doublescale_license' );
			if ( empty( $license['key'] ) ) {
				wp_send_json_error( esc_html__( 'A valid license is required to install addons', 'doublescale' ), 403 );
				exit;
			}

			$response = Site::instance()->api_request(
				array(
					'edd_action' => 'get_version',
					'license'    => $license['key'],
					'item_id'    => "{$addon_slug}_addon",
				)
			);

			// EDD may return download_link or package
			$download_link = $response['data']['download_link'] ?? $response['data']['package'] ?? null;
			if ( empty( $download_link ) ) {
				// Log the response for debugging.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- intentional admin-side diagnostic when license API returns no download link.
				error_log( 'DoubleScale addon install response: ' . print_r( $response, true ) );
				wp_send_json_error( esc_html__( 'Cannot retrieve addon download link. Please check your license.', 'doublescale' ), 422 );
				exit;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$installer_skin = new Automatic_Upgrader_Skin();
		$installer      = new Plugin_Upgrader( $installer_skin );

		if ( ! $installer_skin->request_filesystem_credentials() ) {
			wp_send_json_error( esc_html__( 'Cannot access the filesystem. Please install the addon manually.', 'doublescale' ), 500 );
			exit;
		}

		$installer->install( $download_link );

		if ( is_wp_error( $installer_skin->result ) ) {
			wp_send_json_error( esc_html__( 'Installation failed. Check server logs for details.', 'doublescale' ), 500 );
			exit;
		}

		if ( ! $installer_skin->result || ! $installer->plugin_info() ) {
			wp_send_json_error( esc_html__( 'Installation failed unexpectedly.', 'doublescale' ), 500 );
			exit;
		}

		if ( $installer->plugin_info() !== $addon['plugin_file'] ) {
			if ( ! function_exists( 'delete_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			delete_plugins( array( $installer->plugin_info() ) );
			wp_send_json_error( esc_html__( 'Addon installed but plugin path mismatch detected. Please install manually.', 'doublescale' ), 500 );
			exit;
		}

		wp_send_json_success( esc_html__( 'Addon installed successfully', 'doublescale' ), 200 );
	}

	/**
	 * AJAX: Activate an addon.
	 *
	 * @return void
	 */
	public function ajax_activate() {
		$this->check_authorization();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified inside $this->check_authorization() above.
		$addon_slug = isset( $_POST['addon'] ) ? sanitize_text_field( wp_unslash( $_POST['addon'] ) ) : '';
		$addon      = $this->get_addon( $addon_slug );
		if ( ! $addon ) {
			wp_send_json_error( esc_html__( 'Unknown addon', 'doublescale' ), 400 );
			exit;
		}

		if ( ! $addon['is_installed'] ) {
			wp_send_json_error( esc_html__( 'Addon is not installed', 'doublescale' ), 400 );
			exit;
		}

		if ( $addon['is_active'] ) {
			wp_send_json_error( esc_html__( 'Addon is already active', 'doublescale' ), 400 );
			exit;
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $addon['plugin_file'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( esc_html__( 'Activation failed', 'doublescale' ), 500 );
			exit;
		}

		wp_send_json_success( esc_html__( 'Addon activated successfully', 'doublescale' ), 200 );
	}

	/**
	 * Resolve the White Labeling add-on plugin file path across naming conventions.
	 *
	 * @param string $plugins_dir Absolute path to the plugins directory.
	 * @return string Plugin file relative path.
	 */
	private static function resolve_white_label_plugin_file( $plugins_dir ) {
		$candidates = array(
			'DoubleScale-WhiteLabeling/doublescale-white-labeling.php',
			'doublescale-whitelabeling/doublescale-white-labeling.php',
			'DS-WhiteLabeling/ds-white-labeling.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $plugins_dir . $file ) ) {
				return $file;
			}
		}

		return $candidates[0];
	}

	/**
	 * Resolve the AI Assistant plugin file path across naming conventions.
	 *
	 * @param string $plugins_dir Absolute path to the plugins directory.
	 * @return string Plugin file relative path.
	 */
	private static function resolve_ai_assistant_plugin_file( $plugins_dir ) {
		$candidates = array(
			'DoubleScale-AIAssistant/doublescale-ai-assistant.php',
			'DS-AIAssistant/ds-ai-assistant.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $plugins_dir . $file ) ) {
				return $file;
			}
		}

		return $candidates[0];
	}

	/**
	 * Resolve the Subscriptions add-on plugin file path across naming conventions.
	 *
	 * @param string $plugins_dir Absolute path to the plugins directory.
	 * @return string Plugin file relative path.
	 */
	private static function resolve_subscriptions_plugin_file( $plugins_dir ) {
		$candidates = array(
			'DoubleScale-Subscriptions/doublescale-subscriptions.php',
			'doublescale-subscriptions/doublescale-subscriptions.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $plugins_dir . $file ) ) {
				return $file;
			}
		}

		return $candidates[0];
	}

	/**
	 * Check AJAX authorization.
	 *
	 * @return void
	 */
	private function check_authorization() {
		if ( ! check_ajax_referer( 'doublescale-admin', '_nonce', false ) ) {
			wp_send_json_error( esc_html__( 'Invalid nonce', 'doublescale' ), 403 );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Forbidden', 'doublescale' ), 403 );
			exit;
		}
	}
}
