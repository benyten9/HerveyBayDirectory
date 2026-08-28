<?php
/**
 * WooCommerce CRM settings (sync + products toggles).
 *
 * @package DoubleScale\Pro\Modules\Integrations\WooCommerce
 */

namespace DoubleScale\Pro\Modules\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 */
final class Settings {

	public const OPTION_NAME = 'doublescale_woocommerce_settings';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		OrderContactSync::instance();

		add_filter( 'doublescale_admin_config', array( __CLASS__, 'inject_admin_config' ) );
	}

	/**
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		return array(
			'enable_products' => false,
			'enable_sync'     => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $stored;
	}

	/**
	 * @return array<string, bool>
	 */
	public function resolved_settings(): array {
		return array_merge( self::defaults(), $this->get_settings() );
	}

	/**
	 * @return bool
	 */
	public function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * @return bool
	 */
	public function is_products_enabled(): bool {
		return $this->is_woocommerce_active() && ! empty( $this->resolved_settings()['enable_products'] );
	}

	/**
	 * @return bool
	 */
	public function is_sync_enabled(): bool {
		return $this->is_woocommerce_active() && ! empty( $this->resolved_settings()['enable_sync'] );
	}

	/**
	 * @param array<string, mixed> $settings Candidate settings.
	 * @return true|\WP_Error
	 */
	public function validate( array $settings ) {
		$merged = array_merge( self::defaults(), $settings );
		$any_on = ! empty( $merged['enable_products'] ) || ! empty( $merged['enable_sync'] );

		if ( $any_on && ! $this->is_woocommerce_active() ) {
			return new \WP_Error(
				'woocommerce_inactive',
				__( 'WooCommerce must be installed and active before enabling these features.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $settings Settings payload.
	 * @return array<string, bool>
	 */
	public function update_settings( array $settings ): array {
		$merged = array_merge( self::defaults(), $settings );
		$clean  = array(
			'enable_products' => ! empty( $merged['enable_products'] ),
			'enable_sync'     => ! empty( $merged['enable_sync'] ),
		);

		update_option( self::OPTION_NAME, $clean );

		return $clean;
	}

	/**
	 * Expose feature flags to the admin bundle.
	 *
	 * @param array<string, mixed> $config Admin config payload.
	 * @return array<string, mixed>
	 */
	public static function inject_admin_config( array $config ): array {
		$settings = self::instance();

		$config['wooProductsEnabled'] = $settings->is_products_enabled();
		$config['wooSyncEnabled']     = $settings->is_sync_enabled();

		return $config;
	}
}
