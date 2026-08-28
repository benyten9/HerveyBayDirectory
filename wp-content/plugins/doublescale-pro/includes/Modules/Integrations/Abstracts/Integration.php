<?php
/**
 * Class Integration
 *
 * This class is responsible for handling the integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Abstracts;

/**
 * Integration class
 */
abstract class Integration {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name;

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug;

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description;

	/**
	 * Is Pro feature
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $is_pro = false;

	/**
	 * Minimum plan required to use this integration.
	 *
	 * @var string|null
	 *
	 * @since 1.5.0
	 */
	public $required_plan = null;

	/**
	 * Whether to show a card on the Integrations catalog page.
	 * CRM/automation-only integrations should leave this false.
	 *
	 * @var bool
	 */
	public $show_in_catalog = false;

	/**
	 * Catalog tab this integration belongs to.
	 *
	 * One of: payment, messaging, forms, automation, other.
	 *
	 * @var string
	 */
	public $catalog_category = 'other';

	/**
	 * Absolute URL for the Integrations catalog card image.
	 * Prefer setting this (or override get_icon_url()) from the Integration class — no frontend whitelist needed.
	 *
	 * @var string
	 */
	public $icon_url = '';

	/**
	 * Option name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $option_name;

	/**
	 * Remote Data
	 *
	 * @var IntegrationRemoteData
	 */
	public $remote_data;

	/**
	 * REST Controller
	 *
	 * @var RestIntegrationController
	 */
	public $rest_controller;

	/**
	 * Api
	 *
	 * @var IntegrationApi
	 */
	public $api;

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array(
		// + classes from parent.
		// 'remote_data'   => IntegrationRemoteData::class,
		// 'rest_controller' => RestIntegrationController::class,
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! empty( static::$classes['rest_controller'] ) ) {
			$this->rest_controller = new static::$classes['rest_controller']( $this );
		}

		if ( ! empty( static::$classes['remote_data'] ) ) {
			$this->remote_data = new static::$classes['remote_data']( $this );
		}

		$this->option_name = 'doublescale_' . $this->slug . '_settings';
	}

	/**
	 * Catalog / REST description. Child classes may override for extractable strings.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return is_string( $this->description ) ? __( $this->description, 'doublescale' ) : '';
	}

	/**
	 * Connect the integration
	 *
	 * @since 1.0.0
	 *
	 * @return bool|IntegrationApi
	 */
	public function connect() {
		// Implement this method in the child class.
	}

	/**
	 * Get the settings
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_settings() {
		return get_option( $this->option_name, array() );
	}

	/**
	 * Get the setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if setting doesn't exist.
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update the settings
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings
	 *
	 * @return void
	 */
	public function update_settings( $settings ) {
		update_option( $this->option_name, $settings );
	}

	/**
	 * Update the setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return void
	 */
	public function update_setting( $key, $value ) {
		$settings         = $this->get_settings();
		$settings[ $key ] = $value;
		$this->update_settings( $settings );
	}

	/**
	 * validate the integration
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function validate( $settings ) {
		return true;
	}

	/**
	 * Is connected
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_connected() {
		$api = $this->connect();
		if ( $api instanceof IntegrationApi ) {
			return true;
		}

		return false;
	}

	/**
	 * Catalog card image URL for the Integrations UI.
	 *
	 * @return string Absolute URL or empty string.
	 */
	public function get_icon_url(): string {
		return is_string( $this->icon_url ) ? $this->icon_url : '';
	}

	/**
	 * Integrations catalog tab slug.
	 *
	 * @return string
	 */
	public function get_catalog_category(): string {
		$allowed = array( 'payment', 'messaging', 'forms', 'automation', 'other' );
		$category = is_string( $this->catalog_category ) ? $this->catalog_category : 'other';
		return in_array( $category, $allowed, true ) ? $category : 'other';
	}

	/**
	 * Build an absolute URL for a catalog icon.
	 *
	 * Icons ship with the free plugin (WordPress.org package). When the file is
	 * missing there (older free install), fall back to the same path under Pro.
	 *
	 * @param string $relative Path relative to the plugin root (e.g. assets/images/twilio/twilio.png).
	 * @return string
	 */
	protected function plugin_asset_url( string $relative ): string {
		$relative = ltrim( $relative, '/' );

		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) && defined( 'DOUBLESCALE_PLUGIN_URL' ) ) {
			$free_path = trailingslashit( DOUBLESCALE_PLUGIN_DIR ) . $relative;
			if ( is_readable( $free_path ) ) {
				return trailingslashit( DOUBLESCALE_PLUGIN_URL ) . $relative;
			}
		}

		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) && defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
			$pro_path = trailingslashit( DOUBLESCALE_PRO_PLUGIN_DIR ) . $relative;
			if ( is_readable( $pro_path ) ) {
				return trailingslashit( DOUBLESCALE_PRO_PLUGIN_URL ) . $relative;
			}
		}

		// Last resort: prefer free URL so placeholders still resolve after icons ship.
		if ( defined( 'DOUBLESCALE_PLUGIN_URL' ) ) {
			return trailingslashit( DOUBLESCALE_PLUGIN_URL ) . $relative;
		}

		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
			return trailingslashit( DOUBLESCALE_PRO_PLUGIN_URL ) . $relative;
		}

		return '';
	}

	/**
	 * Build an absolute URL under the Pro plugin assets directory (when available).
	 *
	 * @param string $relative Path relative to Pro assets/.
	 * @return string
	 */
	protected function pro_plugin_asset_url( string $relative ): string {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
			return '';
		}

		return trailingslashit( DOUBLESCALE_PRO_PLUGIN_URL ) . ltrim( $relative, '/' );
	}
}
