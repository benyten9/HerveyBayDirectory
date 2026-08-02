<?php
/**
 * Class Integrations Manager
 * This class is responsible for handling the integrations
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Services;

use Exception;
use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration;

/**
 * Integrations class
 */
final class IntegrationsManager {

	/**
	 * Registed integrations
	 *
	 * @since 1.0.0
	 *
	 * @var Integration[]
	 */
	protected $integrations = array();

	/**
	 * Options
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * @deprecated Retained for backward compatibility; prefer container resolution.
	 * @var IntegrationsManager|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * The DI container is registered to call this method. Do not resolve the
	 * same FQCN from within here or the container will recurse until the
	 * process runs out of memory.
	 *
	 * @since 1.0.0
	 *
	 * @return IntegrationsManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register Integration
	 *
	 * @since 1.0.0
	 *
	 * @param Integration $integration
	 * @param bool        $override Whether to override existing integration (used by Pro plugin)
	 * @return void
	 */
	public function register( Integration $integration, $override = false ) {
		if ( ! $integration instanceof Integration ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new Exception( __( 'Invalid integration', 'doublescale') );
		}

		if ( isset( $this->integrations[ $integration->slug ] ) && ! $override ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			/* translators: %s: integration name */
			throw new Exception( sprintf( __( 'Integration %s already registered', 'doublescale'), $integration->name ) );
		}

		$this->integrations[ $integration->slug ] = $integration;

		$schema     = $integration->rest_controller->get_settings_schema();
		$properties = $schema['properties'] ?? array();
		$settings   = $integration->get_settings();

		$frontend_settings = array();
		foreach ( array_keys( $properties ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$frontend_settings[ $key ] = $settings[ $key ];
			}
		}

		$this->options[ $integration->slug ] = array(
			'label'         => $integration->name,
			'description'   => $integration->description,
			'fields'        => $properties,
			'is_connected'  => $integration->is_connected(),
			'settings'      => $frontend_settings,
			'is_pro'        => $integration->is_pro ?? false,
			'required_plan' => $integration->required_plan,
		);
	}

	/**
	 * Get Integration
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 * @return Integration
	 */
	public function get_integration( $slug ) {
		if ( isset( $this->integrations[ $slug ] ) ) {
			return $this->integrations[ $slug ];
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
		/* translators: %s: integration slug */
		throw new Exception( sprintf( __( 'Integration %s not found', 'doublescale'), $slug ) );
	}

	/**
	 * Get Integrations
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_integrations() {
		return $this->integrations;
	}

	/**
	 * Get Options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Is active
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 *
	 * @return bool
	 */
	public function is_active( $slug ) {
		if ( isset( $this->integrations[ $slug ] ) ) {
			return $this->integrations[ $slug ]->is_connected();
		}

		return false;
	}
}
