<?php
/**
 * Class: Updater
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Website;
use DoubleScale\Website\Store;
use DoubleScale\Website\License;
use DoubleScale\Website\Site;

/**
 * Updater Class
 *
 * @since 1.0.0
 */
class Updater {

	/**
	 * Class instance
	 *
	 * @var self instance
	 */
	private static $instance = null;

	/**
	 * Get class instance
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
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// set pro + addon updates data.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'init_pro_updates' ) );

		// filter pro + addon plugins info.
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );

		// add additional messages to pro plugin row.
		$pro_basename = defined( 'DOUBLESCALE_PRO_PLUGIN_PATH' )
			? \DOUBLESCALE_PRO_PLUGIN_PATH
			: 'doublescale-pro/doublescale-pro.php';
		add_action(
			'in_plugin_update_message-' . $pro_basename,
			array( $this, 'add_in_plugin_update_message' ),
			10
		);

		// add additional messages to addon plugin rows.
		$store_addons = Store::instance()->get_all_addons();
		foreach ( $store_addons as $addon ) {
			add_action(
				"in_plugin_update_message-{$addon['plugin_file']}",
				array( $this, 'add_in_plugin_update_message' ),
				10
			);
		}

		// clear cache on upgrader process complete.
		add_action(
			'upgrader_process_complete',
			function () {
				update_option( 'doublescale_pro_update_cache_needs_clear', true );
			}
		);
		if ( get_option( 'doublescale_pro_update_cache_needs_clear' ) ) {
			update_option( 'doublescale_pro_update_cache_needs_clear', false );
			$this->clear_pro_update_cache();
		}
	}

	/**
	 * Get Plugin Data
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_plugin_data() {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		$plugin_file      = defined( 'DOUBLESCALE_PRO_PLUGIN_PATH' )
			? \DOUBLESCALE_PRO_PLUGIN_PATH
			: 'doublescale-pro/doublescale-pro.php';
		$full_plugin_file = defined( 'DOUBLESCALE_PRO_PLUGIN_FILE' )
			? \DOUBLESCALE_PRO_PLUGIN_FILE
			: ( WP_PLUGIN_DIR . '/' . $plugin_file );
		$plugin_exists    = is_readable( $full_plugin_file );
		$plugin_data      = $plugin_exists ? get_plugin_data( $full_plugin_file ) : array();
		$plugin_slug      = 'doublescale-pro';
		$is_active        = function_exists( 'doublescale_is_pro_addon_active' )
			? doublescale_is_pro_addon_active()
			: is_plugin_active( $plugin_file );

		$data = array(
			'is_installed'     => $plugin_exists,
			'is_active'        => $is_active,
			'full_plugin_file' => $full_plugin_file,
			'plugin_file'      => $plugin_file,
			'version'          => $plugin_data['Version'] ?? '',
			'slug'             => $plugin_slug,
		);

		return $data;
	}

	/**
	 * Init pro updates
	 *
	 * @since 1.21.0
	 *
	 * @param object $transient Transient to filter.
	 * @return object
	 */
	public function init_pro_updates( $transient ) {
		$updates_data = $this->get_pro_update();
		$plugin       = $this->get_plugin_data();

		// Pro plugin updates.
		if ( $plugin['is_installed'] && isset( $updates_data[ $plugin['slug'] ] ) ) {
			$plugin_basename = plugin_basename( $plugin['full_plugin_file'] );
			$new_version     = $updates_data[ $plugin['slug'] ]->new_version ?? null;

			if ( $new_version && version_compare( $plugin['version'], $new_version, '<' ) ) {
				$transient->response[ $plugin_basename ] = $updates_data[ $plugin['slug'] ];
				unset( $transient->no_update[ $plugin_basename ] );
			} else {
				$transient->no_update[ $plugin_basename ] = $updates_data[ $plugin['slug'] ];
				unset( $transient->response[ $plugin_basename ] );
			}
		}

		// Store addon updates.
		$store_addons = Store::instance()->get_all_addons();
		foreach ( $store_addons as $addon_slug => $addon ) {
			if ( $addon['is_installed'] && isset( $updates_data[ $addon_slug ] ) ) {
				$addon_basename = $addon['plugin_file'];
				$new_version    = $updates_data[ $addon_slug ]->new_version ?? null;

				if ( $new_version && version_compare( $addon['version'], $new_version, '<' ) ) {
					$transient->response[ $addon_basename ] = $updates_data[ $addon_slug ];
					unset( $transient->no_update[ $addon_basename ] );
				} else {
					$transient->no_update[ $addon_basename ] = $updates_data[ $addon_slug ];
					unset( $transient->response[ $addon_basename ] );
				}
			}
		}

		return $transient;
	}

	/**
	 * Filter plugins_api
	 *
	 * @since 1.21.0
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args Args.
	 * @return false|object|array
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$updates_data       = $this->get_pro_update();
		$plugin_update_data = doublescale_objects_find( $updates_data, 'slug', $args->slug );
		if ( $plugin_update_data ) {
			return $plugin_update_data;
		}

		return $result;
	}

	/**
	 * Get pro updates data
	 *
	 * @since 1.21.0
	 *
	 * @return array
	 */
	private function get_pro_update() {
		$payload = array(
			'edd_action' => 'get_version',
			'products'   => array(),
			'versions'   => array(
				'php'       => phpversion(),
				'wp'        => get_bloginfo( 'version' ),
				'doublescale' => DOUBLESCALE_VERSION,
			),
		);

		$license     = get_option( 'doublescale_license' );
		$license_key = ! empty( $license ) ? $license['key'] : '';
		$plugin      = $this->get_plugin_data();

		if ( $plugin['is_installed'] ) {
			$payload['products'][ $plugin['slug'] ] = array(
				'action'  => 'get_version',
				'license' => $license_key,
				'item_id' => 'doublescale-pro',
				'version' => $plugin['version'],
				'slug'    => basename( $plugin['full_plugin_file'], '.php' ),
				'author'  => 'doublescale.io',
				'url'     => home_url(),
				'beta'    => false,
			);
		}

		// Include installed store addons in the update check.
		$store_addons = Store::instance()->get_all_addons();
		foreach ( $store_addons as $addon_slug => $addon ) {
			if ( $addon['is_installed'] ) {
				$payload['products'][ $addon_slug ] = array(
					'action'  => 'get_version',
					'license' => $license_key,
					'item_id' => "{$addon_slug}",
					'version' => $addon['version'],
					'slug'    => basename( $addon['plugin_file'], '.php' ),
					'author'  => 'doublescale.io',
					'url'     => home_url(),
					'beta'    => false,
				);
				$payload['versions'][ "{$addon_slug}_addon" ] = $addon['version'];
			}
		}

		$hash      = md5( wp_json_encode( $payload ) );
		$cache_key = 'doublescale_pro_updates';
		$transient = get_transient( $cache_key );
		if ( $transient && hash_equals( $hash, $transient['hash'] ) ) {
			return $transient['data'];
		}

		$response = Site::instance()->api_request( $payload );
		if ( ! $response['success'] || ! $response['data'] ) {
			return array();
		}

		$data = array();
		foreach ( $response['data'] as $resp_slug => $item ) {
			$data[ $resp_slug ] = (object) array();
			foreach ( $item as $key => $value ) {
				$data[ $resp_slug ]->{$key} = maybe_unserialize( $value );
			}
		}

		$transient = array(
			'hash' => $hash,
			'data' => $data,
			'time' => time(),
		);
		set_transient( $cache_key, $transient, 4 * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Add pro update message.
	 *
	 * @since 1.21.0
	 *
	 * @return void
	 */
	public function add_in_plugin_update_message() {
		$license_info = License::instance()->get_license_info();
		$license_page = esc_url( admin_url( 'admin.php?page=doublescale&path=license' ) );

		// invalid license.
		if ( ! $license_info || 'valid' !== $license_info['status'] ) {
			echo '&nbsp;<strong><a href="' . esc_attr($license_page) . '">' . esc_html__( 'Enter valid license key for automatic updates.', 'doublescale') . '</a></strong>';
			return;
		}
	}

	/**
	 * Clear pro update cache
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear_pro_update_cache() {
		// delete updates transient.
		delete_transient( 'doublescale_pro_updates' );

		// clear wp plugins cache.
		if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache();
	}

}